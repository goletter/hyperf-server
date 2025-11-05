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
        // 获取返回内容
        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if ($meta = Arr::get($data, 'meta')) {
            unset($data['links'], $data['meta']);
            $data['total'] = Arr::get($meta, 'total', 0);
        }

        // 返回自定义格式
        return $this->response->withBody(new SwooleStream(json_encode($data)));
    }
}
