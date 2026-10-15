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
use RuntimeException;

use function Hyperf\Support\env;

/**
 * 存储格式：base64(iv[12] . tag[16] . ciphertext)，AES-256-GCM。
 * 兼容读取旧的 AES-256-CBC 密文，重新保存时自动转为 GCM。
 */
class Encrypted implements CastsAttributes
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $plaintext = $this->decryptGcm((string) $value);
        if ($plaintext !== false) {
            return $plaintext;
        }

        return $this->decryptLegacyCbc((string) $value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) $value,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt attribute "' . $key . '".');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decryptGcm(string $value): false|string
    {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH) {
            return false;
        }

        return openssl_decrypt(
            substr($raw, self::IV_LENGTH + self::TAG_LENGTH),
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH),
            substr($raw, self::IV_LENGTH, self::TAG_LENGTH)
        );
    }

    private function decryptLegacyCbc(string $value): false|string
    {
        return openssl_decrypt(
            $value,
            'AES-256-CBC',
            $this->key(),
            0,
            substr($this->key(), 0, 16)
        );
    }

    private function key(): string
    {
        return (string) env('RESPONSE_AES_KEY');
    }
}
