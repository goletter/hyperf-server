<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Hyperf\DbConnection\Model\Model;

class ModelBindingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $dispatched = $request->getAttribute(Dispatched::class);

        if (! $dispatched || ! isset($dispatched->handler->callback)) {
            return $handler->handle($request);
        }

        [$controller, $method] = $dispatched->handler->callback;

        // 获取控制器方法参数反射
        $reflection = new \ReflectionMethod($controller, $method);
        $parameters = $reflection->getParameters();

        $routeParams = $dispatched->params ?? [];

        foreach ($parameters as $param) {
            $name = $param->getName();
            $type = $param->getType();

            if (! $type || ! isset($routeParams[$name])) {
                continue;
            }

            $className = $type->getName();

            // 如果是 Eloquent 模型
            if (class_exists($className) && is_subclass_of($className, Model::class)) {
                $key = $routeParams[$name];
                /** @var \Hyperf\DbConnection\Model\Model|null $model */
                $model = $className::find($key);

                if (! $model) {
                    throw new NotFoundHttpException("{$className} not found for key {$key}");
                }

                $routeParams[$name] = $model;
            }
        }

        // 替换参数
        $dispatched->params = $routeParams;

        // 重新设置请求属性
        $request = $request->withAttribute(Dispatched::class, $dispatched);

        return $handler->handle($request);
    }
}
