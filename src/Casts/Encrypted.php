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

namespace Goletter\Server\Casts;

use Hyperf\Contract\CastsAttributes;
use function Hyperf\Support\env;

class Encrypted implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }
        return openssl_decrypt(
            $value,
            'AES-256-CBC',
            env('RESPONSE_AES_KEY'),
            0,
            substr(env('RESPONSE_AES_KEY'), 0, 16)
        );
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return openssl_encrypt(
            $value,
            'AES-256-CBC',
            env('RESPONSE_AES_KEY'),
            0,
            substr(env('RESPONSE_AES_KEY'), 0, 16)
        );
    }
}
