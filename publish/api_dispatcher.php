<?php

declare(strict_types=1);

/**
 * 多版本 API 网关（BaseDispatcherController）配置。
 *
 * 版本顺序：新 → 旧。未传版本头时使用 default；default 为空则取 versions 第一个。
 * 建议 openapi.php 的 versions 引用本文件，避免两处不一致。
 */
return [
    'versions' => ['v3', 'v2', 'v1'],

    /** 请求头名，如 X-API-Version: v1 */
    'version_header' => 'X-API-Version',

    /** 未传版本头时的默认版本；空字符串表示取 versions[0] */
    'default' => '',
];
