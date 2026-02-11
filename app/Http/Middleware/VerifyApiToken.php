<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VerifyApiToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $apiToken = $request->header('X-API-TOKEN') ?? $request->query('api_token');
        $validTokens = config('app.api_tokens', []);

        // If token is encrypted (client prefix: v1.aes:), try decrypting
        if (!empty($apiToken) && strpos($apiToken, 'v1.aes:') === 0) {
            $decrypted = $this->decryptEncryptedToken($apiToken, $validTokens);
            if (!empty($decrypted)) {
                $apiToken = $decrypted;
            }
        }

        if (empty($validTokens)) {
            return response()->json([
                'success' => false,
                'message' => 'API token not configured on server.'
            ], 500);
        }

        if (empty($apiToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing API token. Please provide X-API-TOKEN header.'
            ], 401);
        }

        if (!in_array($apiToken, $validTokens)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: invalid API token.'
            ], 401);
        }

        return $next($request);
    }

    /**
     * Attempt to decrypt an encrypted token header produced by the client's
     * CryptoJS function. The client encodes: "v1.aes:" + base64(IV + ciphertext).
     * The client derives the key using SHA-256(token + salt), matching CryptoJS logic.
     *
     * @param string $headerValue
     * @param array $validTokens
     * @return string|null decrypted token (one of the valid tokens) or null
     */
    private function decryptEncryptedToken(string $headerValue, array $validTokens): ?string
    {
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
