<?php

namespace App\Services;

trait DecryptsEncryptedToken
{
    /**
     * Attempt to decrypt an encrypted token header produced by the client's
     * CryptoJS function. The client encodes: "v1.aes:" + base64(IV + ciphertext).
     * The client derives the key using SHA-256(token + salt), matching CryptoJS logic.
     *
     * @param string $headerValue
     * @param array $validTokens
     * @return string|null decrypted token (one of the valid tokens) or null
     */
    protected function decryptEncryptedToken(string $headerValue, array $validTokens): ?string
    {
        if (empty($validTokens)) {
            return null;
        }

        $prefix = 'v1.aes:';
        if (strpos($headerValue, $prefix) !== 0) {
            return null;
        }

        $b64 = substr($headerValue, strlen($prefix));
        $data = base64_decode($b64, true);
        if ($data === false || strlen($data) <= 16) {
            return null;
        }

        // Extract IV (first 16 bytes) and ciphertext (rest)
        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);

        // Default salt matching JavaScript: 'nusa-dpd-salt'
        $salt = $validTokens[0];

        // Try each valid token as a decryption candidate
        foreach ($validTokens as $candidate) {
            $candidate = (string) $candidate;

            // Derive 32-byte key (256 bits) using SHA-256(token + salt)
            // This matches: CryptoJS.SHA256(CryptoJS.enc.Utf8.parse(token + saltStr))
            $key = hash('sha256', $candidate . $salt, true);

            // Decrypt using AES-256-CBC
            $plain = openssl_decrypt($ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

            if ($plain === false) {
                continue;
            }

            // If decryption yields exactly the candidate token, it's valid
            if ($plain === $candidate) {
                return $candidate;
            }
        }

        return null;
    }
}