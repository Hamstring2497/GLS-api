<?php

declare(strict_types=1);

namespace App;

final class Crypto
{
    public static function encrypt(string $plainText): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($cipherText === false) {
            throw new \RuntimeException('Versleutelen van token is mislukt.');
        }
        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 29) {
            throw new \RuntimeException('Ongeldige versleutelde tokenwaarde.');
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipherText = substr($payload, 28);
        $plainText = openssl_decrypt(
            $cipherText,
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plainText === false) {
            throw new \RuntimeException('Ontsleutelen van token is mislukt.');
        }
        return $plainText;
    }

    private static function key(): string
    {
        $hex = Env::require('APP_KEY');
        if (!preg_match('/\A[a-f0-9]{64}\z/i', $hex)) {
            throw new \RuntimeException('APP_KEY moet exact 64 hextekens bevatten.');
        }
        return hex2bin($hex) ?: throw new \RuntimeException('APP_KEY is ongeldig.');
    }
}

