<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Redis\Redis;
use JsonException;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class IdempotencyMiddleware implements MiddlewareInterface
{
    private const KEY_PREFIX = 'idempotency:';
    private const TTL = 3600;
    private const LOCK_TTL = 120;
    private const IDEMPOTENCY_KEY_HEADER = 'Idempotency-Key';
    private const REPLAYED_HEADER = 'X-Idempotency-Replayed';
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const CACHED_HEADER_NAMES = ['content-type'];
    private const IGNORE_KEYS = [
        '_random',
        '_timestamp',
        'csrf_token',
        'sign',
        'token',
        'trace_id',
        'request_id',
    ];

    public function __construct(
        private Redis $redis,
        private ResponseInterface $response
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): PsrResponseInterface
    {
        if (!in_array($request->getMethod(), self::WRITE_METHODS, true)) {
            return $handler->handle($request);
        }

        $fingerprint = $this->generateFingerprint($request);
        if ($fingerprint === null) {
            return $handler->handle($request);
        }

        $cacheKey = self::KEY_PREFIX . $fingerprint;
        $cached = $this->getCachedResponse($cacheKey);

        if ($cached !== null) {
            $response = $this->response->json($cached['body'])
                ->withStatus($cached['status'])
                ->withHeader(self::REPLAYED_HEADER, 'true');

            foreach ($cached['headers'] ?? [] as $name => $values) {
                $response = $response->withHeader($name, $values);
            }

            return $response;
        }

        $lockKey = $cacheKey . ':lock';
        $lockToken = $this->acquireLock($lockKey);
        if ($lockToken === null) {
            return $this->response->json([
                'code' => 409,
                'message' => '请勿重复操作！',
            ])->withStatus(409)
                ->withHeader('Retry-After', (string) self::LOCK_TTL);
        }

        $shouldReleaseLock = true;
        try {
            $response = $handler->handle($request);

            $cached = $this->cacheSuccessfulJsonResponse($cacheKey, $response);
            // 业务已成功但幂等结果没有落 Redis 时，保留锁到自动过期，避免瞬时重试再次执行业务。
            $shouldReleaseLock = !$this->isSuccessfulResponse($response) || $cached;

            return $response;
        } finally {
            if ($shouldReleaseLock) {
                $this->releaseLock($lockKey, $lockToken);
            }
        }
    }

    /**
     * 根据请求路径、参数和用户身份生成稳定指纹。
     */
    private function generateFingerprint(ServerRequestInterface $request): ?string
    {
        $idempotencyKey = trim($request->getHeaderLine(self::IDEMPOTENCY_KEY_HEADER));
        if ($idempotencyKey !== '') {
            return $this->hashFingerprint(array_merge([
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'idempotency_key' => $idempotencyKey,
            ], $this->getIdentityScope($request)));
        }

        $params = $this->extractParams($request);
        $normalizedParams = $this->normalizeParams($params);
        if ($normalizedParams === []) {
            return null;
        }

        $fingerprintData = array_merge([
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'params' => $normalizedParams,
        ], $this->getIdentityScope($request));

        return $this->hashFingerprint($fingerprintData);
    }

    /**
     * 提取所有请求参数（支持 JSON、表单、Query）
     */
    private function extractParams(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        if (is_array($parsedBody)) {
            $params = array_merge($params, $parsedBody);
        }

        if (str_contains(strtolower($request->getHeaderLine('Content-Type')), 'application/json')) {
            $body = $this->readStreamBody($request->getBody());
            if ($body !== '') {
                $jsonData = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    $params = array_merge($params, $jsonData);
                }
            }
        }

        return $params;
    }

    /**
     * 规范化参数（排序、去除无关字段）
     */
    private function normalizeParams(array $params): array
    {
        return $this->normalizeValue($params);
    }

    /**
     * 获取用户ID（根据你的认证方式调整）
     */
    private function getUserId(ServerRequestInterface $request): ?string
    {
        $userId = $request->getAttribute('user_id');
        if (is_scalar($userId) && $userId !== '') {
            return (string) $userId;
        }

        $user = $request->getAttribute('user');
        if (is_array($user) && isset($user['id'])) {
            return (string) $user['id'];
        }

        if (is_object($user)) {
            if (method_exists($user, 'getId')) {
                return (string) $user->getId();
            }

            if (property_exists($user, 'id')) {
                return (string) $user->id;
            }
        }

        return null;
    }

    private function getIdentityScope(ServerRequestInterface $request): array
    {
        $userId = $this->getUserId($request);
        if ($userId !== null) {
            return ['user_id' => $userId];
        }

        return ['client_ip' => $this->getClientIp($request)];
    }

    private function acquireLock(string $lockKey): ?string
    {
        $token = bin2hex(random_bytes(16));

        if (! (bool) $this->redis->set($lockKey, $token, ['NX', 'EX' => self::LOCK_TTL])) {
            return null;
        }

        return $token;
    }

    private function releaseLock(string $lockKey, string $token): void
    {
        $script = <<<'LUA'
if redis.call("get", KEYS[1]) == ARGV[1] then
    return redis.call("del", KEYS[1])
end

return 0
LUA;

        $this->redis->eval($script, [$lockKey, $token], 1);
    }

    private function getCachedResponse(string $cacheKey): ?array
    {
        $cachedResult = $this->redis->get($cacheKey);
        if (!is_string($cachedResult) || $cachedResult === '') {
            return null;
        }

        $cached = json_decode($cachedResult, true);
        if (!is_array($cached)
            || !isset($cached['status'])
            || !array_key_exists('body', $cached)
            || !is_int($cached['status'])
            || (isset($cached['headers']) && !is_array($cached['headers']))
        ) {
            $this->redis->del($cacheKey);

            return null;
        }

        return $cached;
    }

    private function cacheSuccessfulJsonResponse(string $cacheKey, PsrResponseInterface $response): bool
    {
        if (! $this->isSuccessfulResponse($response)) {
            return false;
        }

        $body = json_decode($this->readStreamBody($response->getBody()), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return (bool) $this->redis->setex($cacheKey, self::TTL, $this->encodeJson([
            'status' => $response->getStatusCode(),
            'headers' => $this->extractCacheableHeaders($response),
            'body' => $body,
        ]));
    }

    private function extractCacheableHeaders(PsrResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            if (is_string($name) && in_array(strtolower($name), self::CACHED_HEADER_NAMES, true)) {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

    private function isSuccessfulResponse(PsrResponseInterface $response): bool
    {
        $statusCode = $response->getStatusCode();

        return $statusCode >= 200 && $statusCode < 300;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_numeric($value) ? (string) $value : $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, self::IGNORE_KEYS, true)) {
                continue;
            }

            $normalized[$key] = $this->normalizeValue($item);
        }

        if (!array_is_list($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    private function encodeJson(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            return '{}';
        }
    }

    private function hashFingerprint(array $fingerprintData): string
    {
        return hash('sha256', $this->encodeJson(array_filter(
            $fingerprintData,
            static fn ($value) => $value !== null && $value !== ''
        )));
    }

    private function getClientIp(ServerRequestInterface $request): ?string
    {
        return $request->getServerParams()['remote_addr'] ?? null;
    }

    private function readStreamBody(StreamInterface $stream): string
    {
        $body = (string) $stream;

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $body;
    }
}