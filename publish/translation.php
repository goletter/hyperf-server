<?php

declare(strict_types=1);

/**
 * 多语言（LocaleMiddleware / 校验文案）配置。
 *
 * 语言包目录默认 storage/languages/{locale}/validation.php
 * 可从 hyperf/validation 发布中文包：
 *   php bin/hyperf.php vendor:publish hyperf/validation
 */
return [
    'locale' => 'zh_CN',
    'fallback_locale' => 'en',
    'path' => BASE_PATH . '/storage/languages',
];
