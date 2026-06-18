<?php

defined('ABSPATH') || exit;

class WAA_Encryptor {
    private const CIPHER = 'AES-256-CBC';

    public function encrypt(string $value): string {
        $iv        = random_bytes(16);
        $encrypted = openssl_encrypt($value, self::CIPHER, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $encoded): string {
        $raw = base64_decode($encoded, strict: true);
        if ($raw === false || strlen($raw) < 17) return '';
        $iv        = substr($raw, 0, 16);
        $encrypted = substr($raw, 16);
        $result    = openssl_decrypt($encrypted, self::CIPHER, AUTH_KEY, OPENSSL_RAW_DATA, $iv);
        return $result !== false ? $result : '';
    }
}
