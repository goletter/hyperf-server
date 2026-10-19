<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * HTTP 固定窗口限流（Redis INCR + EXPIRE，同一脚本原子执行）.
 *
 * 配置见 config/autoload/rate_limit.php（vendor:publish goletter/hyperf-server）.
 * 命中后响应带 X-RateLimit-Limit / Remaining / Reset；超限 429 并带 Retry-After.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private const SCRIPT = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], tonumber(ARGV[1]))
end
return current
LUA;

    private LoggerInterface $logger;

    public function __construct(
        private Redis $redis,
        private ResponseInterface $response,
        private ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('default');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        if (! $this->enabled() || $request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if ($this->isWhitelisted($path)) {
            return $handler->handle($request);
        }

        $rule = $this->matchRule($path, $request->getMethod());
        if ($rule === null) {
            return $handler->handle($request);
        }

        $window = max(1, (int) ($rule['window'] ?? 60));
        $max = max(1, (int) ($rule['max'] ?? 1));
        $dimension = (string) ($rule['dimension'] ?? 'ip');
        $bucket = (int) floor(time() / $window);
        $key = $this->buildKey($request, $path, $rule, $dimension, $bucket);

        try {
            $count = $this->hit($key, $window);
        } catch (Throwable $e) {
            $this->logger->error('[rate_limit] redis error: ' . $e->getMessage(), ['exception' => $e]);
            if ($this->failOpen()) {
                return $handler->handle($request);
            }

            return $this->unavailableResponse();
        }

        $resetAt = ($bucket + 1) * $window;
        $remaining = max(0, $max - $count);
        if ($count > $max) {
            return $this->tooManyRequestsResponse($max, $resetAt);
        }

        $response = $handler->handle($request);

        return $this->withLimitHeaders($response, $max, $remaining, $resetAt);
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('rate_limit.enable', false);
    }

    private function failOpen(): bool
    {
        return (bool) $this->config->get('rate_limit.fail_open', true);
    }

    private function isWhitelisted(string $path): bool
    {
        $prefixes = $this->config->get('rate_limit.whitelist_prefixes', ['/health', '/metrics', '/favicon.ico']);
        if (! is_array($prefixes)) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 最长 path_prefix 优先；methods 不匹配则跳过该条.
     *
     * @return null|array<string, mixed>
     */
    private function matchRule(string $path, string $method): ?array
    {
        $rules = $this->config->get('rate_limit.rules', []);
        if (! is_array($rules)) {
            $rules = [];
        }

        usort($rules, static function ($a, $b): int {
            $left = is_array($a) ? strlen((string) ($a['path_prefix'] ?? '')) : 0;
            $right = is_array($b) ? strlen((string) ($b['path_prefix'] ?? '')) : 0;

            return $right <=> $left;
        });

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $prefix = (string) ($rule['path_prefix'] ?? '');
            if ($prefix === '' || ! str_starts_with($path, $prefix)) {
                continue;
            }
            if (! $this->methodAllowed($rule, $method)) {
                continue;
            }
            if ((int) ($rule['max'] ?? 0) <= 0) {
                continue;
            }

            return $rule;
        }

        $default = $this->config->get('rate_limit.default', []);
        if (! is_array($default) || (int) ($default['max'] ?? 0) <= 0) {
            return null;
        }
        if (! $this->methodAllowed($default, $method)) {
            return null;
        }

        $default['path_prefix'] = (string) ($default['path_prefix'] ?? '/');

        return $default;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function methodAllowed(array $rule, string $method): bool
    {
        $methods = $rule['methods'] ?? [];
        if (! is_array($methods) || $methods === []) {
            return true;
        }

        $allowed = array_map(static fn ($item) => strtoupper((string) $item), $methods);

        return in_array(strtoupper($method), $allowed, true);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function buildKey(
        ServerRequestInterface $request,
        string $path,
        array $rule,
        string $dimension,
        int $bucket,
    ): string {
        $scope = $this->scope($path, $rule, $dimension);
        $identity = $this->identity($request, $dimension);
        $prefix = (string) $this->config->get('rate_limit.key_prefix', 'rate_limit:');
        $fingerprint = hash('sha1', $dimension . "\0" . $scope . "\0" . $identity);

        return $prefix . $bucket . ':' . $fingerprint;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function scope(string $path, array $rule, string $dimension): string
    {
        $byPath = (bool) ($rule['by_path'] ?? false)
            || in_array($dimension, ['ip_path', 'user_path', 'path'], true);
        if ($byPath) {
            return $path;
        }

        $prefix = (string) ($rule['path_prefix'] ?? '');

        return $prefix !== '' ? $prefix : $path;
    }

    private function identity(ServerRequestInterface $request, string $dimension): string
    {
        if (str_starts_with($dimension, 'user')) {
            $userId = $this->resolveUserId($request);
            if ($userId !== '') {
                return 'uid:' . $userId;
            }

            return 'guest:' . $this->resolveClientIp($request);
        }

        return 'ip:' . $this->resolveClientIp($request);
    }

    private function resolveUserId(ServerRequestInterface $request): string
    {
        $attributes = $this->config->get('rate_limit.user_attributes', ['user_id', 'uid']);
        if (! is_array($attributes)) {
            $attributes = ['user_id'];
        }

        foreach ($attributes as $name) {
            $value = $request->getAttribute((string) $name);
            if (is_int($value) || (is_string($value) && $value !== '')) {
                return (string) $value;
            }
        }

        return '';
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        if ((bool) $this->config->get('rate_limit.use_forwarded', false)) {
            $realIp = trim($request->getHeaderLine('X-Real-IP'));
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
                return $realIp;
            }

            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0] ?? '');
                if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        $remote = (string) ($request->getServerParams()['remote_addr'] ?? '');

        return $remote !== '' ? $remote : '0.0.0.0';
    }

    /**
     * @throws Throwable
     */
    private function hit(string $key, int $window): int
    {
        // 多留 1 秒，避免窗口边界上 key 提前消失
        $ttl = $window + 1;
        $count = $this->redis->eval(self::SCRIPT, [$key, (string) $ttl], 1);

        return (int) $count;
    }

    private function tooManyRequestsResponse(int $max, int $resetAt): PsrResponseInterface
    {
        $retryAfter = max(1, $resetAt - time());
        $message = (string) $this->config->get('rate_limit.messages.exceeded', '请求过于频繁，请稍后再试');

        $response = $this->response->json([
            'code' => 429,
            'message' => $message,
        ])->withStatus(429)->withHeader('Retry-After', (string) $retryAfter);

        return $this->withLimitHeaders($response, $max, 0, $resetAt);
    }

    private function unavailableResponse(): PsrResponseInterface
    {
        $message = (string) $this->config->get('rate_limit.messages.unavailable', '限流服务暂不可用');

        return $this->response->json([
            'code' => 503,
            'message' => $message,
        ])->withStatus(503);
    }

    private function withLimitHeaders(
        PsrResponseInterface $response,
        int $max,
        int $remaining,
        int $resetAt,
    ): PsrResponseInterface {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) $resetAt);
    }
}
