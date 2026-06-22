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

class JsonArrayIntCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        $arr = json_decode($value, true);
        if (! is_array($arr)) {
            return [];
        }

        return array_map('intval', $arr);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return json_encode(array_map('intval', (array) $value));
    }
}
