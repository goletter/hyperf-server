<?php

declare(strict_types=1);

namespace Goletter\Server\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected RequestInterface $request,
        protected ConfigInterface $config,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->resolveLocale($request);
        $this->translator->setLocale($locale);

        return $handler->handle($request);
    }

    protected function resolveLocale(ServerRequestInterface $request): string
    {
        $default = (string) $this->config->get('translation.locale', 'zh_CN');

        // 1. ?lang=zh_CN / zh-CN
        $locale = $this->normalizeLocale((string) $this->request->input('lang', ''));
        if ($locale !== '') {
            return $locale;
        }

        // 2. Accept-Language: zh-CN,zh;q=0.9 → zh_CN
        $locale = $this->normalizeLocale($this->parseAcceptLanguage($request->getHeaderLine('Accept-Language')));
        if ($locale !== '') {
            return $locale;
        }

        return $default !== '' ? $default : 'zh_CN';
    }

    /** 取 Accept-Language 第一个语言标签。 */
    protected function parseAcceptLanguage(string $header): string
    {
        $header = trim($header);
        if ($header === '') {
            return '';
        }

        $first = trim(explode(',', $header)[0] ?? '');
        // zh-CN;q=0.9 → zh-CN
        $tag = trim(explode(';', $first)[0] ?? '');

        return $tag;
    }

    /** zh-CN / zh_cn → zh_CN */
    protected function normalizeLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }

        $locale = str_replace('-', '_', $locale);
        if (! str_contains($locale, '_')) {
            return strtolower($locale);
        }

        [$lang, $region] = array_pad(explode('_', $locale, 2), 2, '');

        return strtolower($lang) . ($region !== '' ? '_' . strtoupper($region) : '');
    }
}
