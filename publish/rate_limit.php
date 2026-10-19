<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    // 总开关；关闭时中间件直接放行
    'enable' => (bool) env('RATE_LIMIT_ENABLE', false),

    // Redis 不可用时：true 放行，false 返回 503
    'fail_open' => (bool) env('RATE_LIMIT_FAIL_OPEN', true),

    // 仅在网关已覆盖 X-Forwarded-For / X-Real-IP 时打开，否则客户端可伪造 IP
    'use_forwarded' => (bool) env('RATE_LIMIT_USE_FORWARDED', false),

    'key_prefix' => env('RATE_LIMIT_KEY_PREFIX', 'rate_limit:'),

    // 这些前缀不计数（健康检查、监控等）
    'whitelist_prefixes' => [
        '/health',
        '/metrics',
        '/favicon.ico',
    ],

    // dimension=user 时依次读取的请求属性
    'user_attributes' => ['user_id', 'uid'],

    // 没有命中 rules 时使用；max <= 0 表示不启用默认规则
    'default' => [
        'max' => (int) env('RATE_LIMIT_MAX', 120),
        'window' => (int) env('RATE_LIMIT_WINDOW', 60),
        // ip：同一 IP 在该规则前缀下共用额度
        // user：登录用户共用额度，未登录回退到 IP
        // ip_path / user_path：再按完整路径拆开
        'dimension' => env('RATE_LIMIT_DIMENSION', 'ip'),
    ],

    /*
    | 更长的 path_prefix 优先。methods 为空表示不限方法。
    |
    | [
    |     'path_prefix' => '/api/auth/login',
    |     'max' => 10,
    |     'window' => 60,
    |     'dimension' => 'ip',
    |     'methods' => ['POST'],
    | ],
    */
    'rules' => [],

    'messages' => [
        'exceeded' => '请求过于频繁，请稍后再试',
        'unavailable' => '限流服务暂不可用',
    ],
];
