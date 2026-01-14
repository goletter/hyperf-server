<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HeaderMiddleware implements MiddlewareInterface
{
    protected RequestInterface $request;

    public function __construct(RequestInterface $request)
    {
        $this->request = $request;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $this->request->input('Authorization');
        if (!$authorization) {
            $authorization = $request->getHeaderLine('Authorization');
        }
        $request = $request->withHeader('Authorization', $authorization);

        return $handler->handle($request);
    }
}
