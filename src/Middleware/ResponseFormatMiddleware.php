<?php

namespace Goletter\Server\Middleware;

use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Goletter\Utils\ResponseEncrypt;
use Hyperf\Collection\Arr;
use function Hyperf\Support\env;

class ResponseFormatMiddleware implements MiddlewareInterface
{
    protected $response;

    public function __construct(HttpResponse $response)
    {
        $this->response = $response;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // 非 JSON 响应直接透传
        $contentDisposition = $response->getHeaderLine('Content-Disposition');
        if ($contentDisposition && stripos($contentDisposition, 'attachment') !== false) {
            return $response;
        }
        $contentType = $response->getHeaderLine('Content-Type');
        if ($contentType && stripos($contentType, 'application/json') === false) {
            return $response;
        }

        // 获取返回内容
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        // 如果不是有效 JSON，则不做格式化
        if ($data === null && trim($body) !== 'null') {
            return $response;
        }

        if ($meta = Arr::get($data, 'meta')) {
            unset($data['links'], $data['meta']);
            $data['total'] = Arr::get($meta, 'total', 0);
        }

        // 检查是否需要加密（可以通过请求头或配置控制）
        $needEncrypt = $request->getHeaderLine('X-Response-Encrypt') === 'true'
            || env('RESPONSE_ENCRYPT_ENABLED', false);

        if ($needEncrypt) {
            // 加密响应数据
            $jsonString = json_encode($data, JSON_UNESCAPED_UNICODE);
            $encryptedData = ResponseEncrypt::encrypt($jsonString);
            return $this->response->withBody(new SwooleStream(json_encode($encryptedData)));
        }

        // 返回自定义格式
        return $this->response->withBody(new SwooleStream(json_encode($data)));
    }
}