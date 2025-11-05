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

namespace Goletter\Server\Router;

use Hyperf\HttpServer\Router\Router as HyperfRouter;

class Router extends HyperfRouter
{
    public static function apiResource(string $name, string $controller)
    {
        $baseUri = trim($name, '/');
        $param = '{' . \Hyperf\Stringable\Str::camel(\Hyperf\Stringable\Str::singular($baseUri)) . '}';

        Router::addGroup("/{$baseUri}", function() use ($controller, $param) {
            Router::addRoute('GET', '', [$controller, 'index']);
            Router::addRoute('POST', '', [$controller, 'store']);
            Router::addRoute('GET', "/{$param}", [$controller, 'show']);
            Router::addRoute(['PUT','PATCH'], "/{$param}", [$controller, 'update']);
            Router::addRoute('DELETE', "/{$param}", [$controller, 'destroy']);
        });
    }
}