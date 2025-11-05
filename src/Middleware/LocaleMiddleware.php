<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Contract\TranslatorInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function Hyperf\Support\env;

class LocaleMiddleware implements MiddlewareInterface
{
    protected TranslatorInterface $translator;
    protected RequestInterface $request;

    public function __construct(TranslatorInterface $translator, RequestInterface $request)
    {
        $this->translator = $translator;
        $this->request = $request;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. 优先读取请求参数 ?lang=zh-CN
        $locale = $this->request->input('lang');

        // 2. 如果没有参数，读取请求头 Accept-Language
        if (!$locale) {
            $locale = $request->getHeaderLine('Accept-Language');
        }

        // 3. 如果还没有，使用默认语言
        $locale = $locale ?: env('translation.locale', 'en');

        // 4. 设置当前请求的语言
        $this->translator->setLocale($locale);

        // 5. 继续请求处理
        return $handler->handle($request);
    }
}
