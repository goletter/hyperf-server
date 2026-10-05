<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Goletter\Utils\TenantContext;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 解析并写入租户上下文。
 *
 * 优先级：
 * 1. 请求属性 tenant_id（上游鉴权中间件写入，推荐）
 * 2. Header X-Tenant-Id
 * 3. Query tenant_id（调试）
 *
 * 白名单路径可不带租户；其余缺失则 401。
 */
class TenantMiddleware implements MiddlewareInterface
{
    /** 不强制租户的路径前缀 */
    private array $except = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->except as $prefix) {
            if ($path === $prefix || ($prefix !== '/' && str_starts_with($path, $prefix))) {
                return $handler->handle($request);
            }
        }

        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId === null || $tenantId === '') {
            $header = $request->getHeaderLine('X-Tenant-Id');
            if ($header !== '') {
                $tenantId = $header;
            }
        }

        if ($tenantId === null || $tenantId === '') {
            $query = $request->getQueryParams();
            if (isset($query['tenant_id']) && $query['tenant_id'] !== '') {
                $tenantId = $query['tenant_id'];
            }
        }

        if ($tenantId === null || $tenantId === '' || (int) $tenantId <= 0) {
            throw new NotFoundHttpException('Missing or invalid tenant_id');
        }

        TenantContext::set((int) $tenantId);

        return $handler->handle($request->withAttribute('tenant_id', (int) $tenantId));
    }
}
