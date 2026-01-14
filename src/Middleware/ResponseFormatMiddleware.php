<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Goletter\Server\Middleware;

use Hyperf\Collection\Arr;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResponseFormatMiddleware implements MiddlewareInterface
{
    protected $response;

    public function __construct(HttpResponse $response)
    {
        $this->response = $response;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 执行下一个中间件或控制器
        $response = $handler->handle($request);

        // 非 JSON 响应（如文件下载）直接透传，避免把二进制内容 json_decode 成 null
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

        // 如果不是有效 JSON，则不做格式化，避免返回 "null"
        if ($data === null && trim($body) !== 'null') {
            return $response;
        }

        if ($meta = Arr::get($data, 'meta')) {
            unset($data['links'], $data['meta']);
            $data['total'] = Arr::get($meta, 'total', 0);
        }

        // 返回自定义格式
        return $this->response->withBody(new SwooleStream(json_encode($data)));
    }
}
