<?php
namespace Goletter\Server\Middleware;

use Hyperf\Context\Context;
use Hyperf\Context\RequestContext;
use OpenTracing\Formats;
use OpenTracing\GlobalTracer;
use OpenTracing\Tags;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramsey\Uuid\Uuid;

class TraceGatewayMiddleware implements MiddlewareInterface
{
    public const TRACE_ID_HEADER = 'X-Trace-Id';

    private const TRACE_ID_HEADERS = [
        self::TRACE_ID_HEADER,
        'X-Request-Id',
        'trace-id',
        'Trace-Id',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $traceId = $this->resolveTraceId($request);
        Context::set('trace_id', $traceId);
        $request = $this->withTraceIdInput($request, $traceId);

        $tracer = GlobalTracer::get();
        $spanOptions = [
            'tags' => [
                Tags\SPAN_KIND => Tags\SPAN_KIND_RPC_SERVER,
                Tags\HTTP_METHOD => $request->getMethod(),
                Tags\HTTP_URL => (string) $request->getUri(),
                'trace_id' => $traceId,
            ],
        ];

        $parentContext = $tracer->extract(Formats\HTTP_HEADERS, $this->flattenHeaders($request));
        if ($parentContext !== null) {
            $spanOptions['child_of'] = $parentContext;
        }

        $operationName = sprintf('%s %s', $request->getMethod(), $request->getUri()->getPath());
        $scope = $tracer->startActiveSpan($operationName, $spanOptions);

        try {
            $response = $handler->handle($request);
            $scope->getSpan()->setTag(Tags\HTTP_STATUS_CODE, $response->getStatusCode());

            return $response->withHeader(self::TRACE_ID_HEADER, $traceId);
        } catch (\Throwable $e) {
            $scope->getSpan()->setTag(Tags\ERROR, true);
            $scope->getSpan()->setTag('error.message', $e->getMessage());

            throw $e;
        } finally {
            $scope->close();
            $tracer->flush();
        }
    }

    private function resolveTraceId(ServerRequestInterface $request): string
    {
        foreach (self::TRACE_ID_HEADERS as $header) {
            $value = trim($request->getHeaderLine($header));
            if ($value !== '') {
                return $value;
            }
        }

        return Uuid::uuid4()->toString();
    }

    private function withTraceIdInput(ServerRequestInterface $request, string $traceId): ServerRequestInterface
    {
        $request = $request->withQueryParams(array_merge($request->getQueryParams(), [
            'trace_id' => $traceId,
        ]));

        RequestContext::set($request);

        return $request;
    }

    private function flattenHeaders(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(',', $values);
        }

        return $headers;
    }
}
