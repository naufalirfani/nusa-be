<?php

namespace App\Services;

class TokenEncryptionService
{
    /**
     * Encrypt token for API header using AES-256-CBC
     * Replicates the JavaScript encryptTokenForHeader function
     * 
     * @param string $token
     * @param array $opts
     * @return string
     */
    public static function encryptTokenForHeader(string $token, array $opts = []): string
    {
        try {
            if (empty($token)) {
                return '';
            }

            // Salt (static / env-based)
            $saltStr = $opts['salt'] ?? 'nusa-dpd-salt';

            // Key derivation using SHA-256 (256-bit key for AES-256)
            $key = hash('sha256', $token . $saltStr, true);

            // Generate random 16-byte IV
            $iv = openssl_random_pseudo_bytes(16);

            // Encrypt using AES-256-CBC with PKCS7 padding
            $encrypted = openssl_encrypt(
                $token,
                'AES-256-CBC',
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new \Exception('Encryption failed');
            }

            // Combine IV + ciphertext and encode to base64
            $combined = $iv . $encrypted;
            $b64 = base64_encode($combined);

            return 'v1.aes:' . $b64;
        } catch (\Exception $e) {
            \Log::error('Error encrypting token for header: ' . $e->getMessage());
            return $token;
        }
    }
}
