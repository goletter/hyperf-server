<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Goletter\Utils\ResponseEncrypt;
use Hyperf\Context\RequestContext;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\RequestInterface as HyperfRequest;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use function Hyperf\Support\env;
use function Hyperf\Support\make;

/**
 * 请求体解密，与 ResponseFormatMiddleware 对称。
 *
 * 前端 POST body 格式：
 * {"encrypted": true, "data": "base64密文", "iv": "base64 IV"}
 *
 * 触发条件（满足其一）：
 * - 请求头 X-Request-Encrypt: true
 * - env REQUEST_ENCRYPT_ENABLED=true
 * - env RESPONSE_ENCRYPT_ENABLED=true（未单独配置 REQUEST_ENCRYPT_ENABLED 时）
 */
class RequestDecryptMiddleware implements MiddlewareInterface
{
    /** 仅这些 HTTP 方法尝试解密 body */
    private const DECRYPT_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    public function __construct(protected HttpResponse $response)
    {
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->shouldAttemptDecrypt($request)) {
            return $handler->handle($request);
        }
        $body = (string) $request->getBody();
        if ($body === '') {
            return $handler->handle($request);
        }
        $parsed = json_decode($body, true);
        if (! is_array($parsed)) {
            return $handler->handle($request);
        }
        // 未加密请求，原样放行
        if (empty($parsed['encrypted'])) {
            return $handler->handle($request);
        }
        if (empty($parsed['data']) || empty($parsed['iv'])) {
            return $this->badRequest('Invalid encrypted payload.');
        }
        try {
            $plaintext = ResponseEncrypt::decrypt((string) $parsed['data'], (string) $parsed['iv']);
        } catch (Throwable) {
            return $this->badRequest('Request decrypt failed.');
        }
        $decrypted = json_decode($plaintext, true);
        if (! is_array($decrypted)) {
            return $this->badRequest('Decrypted request body must be JSON object.');
        }

        $request = $request
            ->withHeader('Content-Type', 'application/json')
            ->withBody(new SwooleStream(json_encode($decrypted, JSON_UNESCAPED_UNICODE)))
            ->withParsedBody($decrypted);

        // 同步到 Hyperf 协程上下文，确保 $this->request / FormRequest 读到明文
        RequestContext::set($request);
        // 清除 HeaderMiddleware 等更早中间件可能缓存的加密 body
        make(HyperfRequest::class)->clearStoredParsedData();

        return $handler->handle($request);
    }

    private function shouldAttemptDecrypt(ServerRequestInterface $request): bool
    {
        if (! in_array($request->getMethod(), self::DECRYPT_METHODS, true)) {
            return false;
        }
        // multipart 上传不解密
        $contentType = strtolower($request->getHeaderLine('Content-Type'));
        if (str_contains($contentType, 'multipart/form-data')) {
            return false;
        }
        if ($request->getHeaderLine('X-Request-Encrypt') === 'true') {
            return true;
        }
        if (env('REQUEST_ENCRYPT_ENABLED') !== null) {
            return (bool) env('REQUEST_ENCRYPT_ENABLED');
        }
        return (bool) env('RESPONSE_ENCRYPT_ENABLED', false);
    }
    private function badRequest(string $message): ResponseInterface
    {
        return $this->response->json([
            'code' => 400,
            'message' => $message,
        ])->withStatus(400);
    }
}