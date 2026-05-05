<?php

namespace App\Http\Controllers;

use App\Services\DecryptsEncryptedToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AuthController extends Controller
{
    use DecryptsEncryptedToken;

    /**
     * Login admin with email and password from .env
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {
        // Get credentials from environment
        $adminEmail = env('ADMIN_EMAIL');
        $adminPassword = env('ADMIN_PASSWORD');

        $email = $this->normalizeCredential($request->input('email'), $adminEmail);
        $password = $this->normalizeCredential($request->input('password'), $adminPassword);

        if ($email === null || $password === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to decrypt email or password.',
            ], 401);
        }

        // Validate input after decrypting encrypted payloads
        $request->merge([
            'email' => $email,
            'password' => $password,
        ]);

        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Check if credentials match
        if ($email === $adminEmail && $password === $adminPassword) {
            $secret = env('API_TOKEN');
            if (empty($secret)) {
                return response()->json([
                    'success' => false,
                    'message' => 'API_TOKEN not configured on server.',
                ], 500);
            }

            $jwt = $this->generateJwt([
                'email' => $adminEmail,
                'type' => 'admin',
            ], $secret, 3600); // 1 hour

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'email' => $adminEmail,
                    'token' => $jwt,
                    'type' => 'admin',
                    'expires_in' => 3600,
                ],
            ], 200);
        }

        // Invalid credentials
        return response()->json([
            'success' => false,
            'message' => 'Invalid email or password',
        ], 401);
    }

    private function normalizeCredential(?string $value, ?string $expectedValue): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (strpos($value, 'v1.aes:') !== 0) {
            return $value;
        }

        if (empty($expectedValue)) {
            return null;
        }

        return $this->decryptEncryptedToken($value, [$expectedValue]);
    }

    /**
     * Logout admin
     *
     * @return JsonResponse
     */
    public function logout(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Logout successful (token is stateless and not persisted).',
        ], 200);
    }

    /**
     * Verify JWT token and return expiry info
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(Request $request): JsonResponse
    {
        $token = $this->getTokenFromRequest($request);
        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'Token not provided',
            ], 401);
        }

        $secret = env('API_TOKEN');
        if (empty($secret)) {
            return response()->json([
                'success' => false,
                'message' => 'API_TOKEN not configured on server.',
            ], 500);
        }

        $res = $this->validateJwt($token, $secret);
        if ($res['valid']) {
            $expiresAt = Carbon::createFromTimestamp($res['payload']['exp']);
            return response()->json([
                'success' => true,
                'message' => 'Token is valid',
                'data' => [
                    'payload' => $res['payload'],
                    'expires_at' => $expiresAt->toDateTimeString(),
                    'expired' => false,
                ],
            ], 200);
        }

        $status = $res['expired'] ? 401 : 400;
        return response()->json([
            'success' => false,
            'message' => $res['message'] ?? 'Invalid token',
            'expired' => $res['expired'] ?? false,
        ], $status);
    }

    private function getTokenFromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header && stripos($header, 'bearer ') === 0) {
            return trim(substr($header, 7));
        }

        return $request->input('token');
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private function generateJwt(array $claims, string $secret, int $ttlSeconds = 3600): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $iat = time();
        $exp = $iat + $ttlSeconds;

        $payload = array_merge($claims, ['iat' => $iat, 'exp' => $exp]);

        $base64Header = $this->base64UrlEncode(json_encode($header));
        $base64Payload = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
        $base64Signature = $this->base64UrlEncode($signature);

        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }

    private function validateJwt(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return ['valid' => false, 'message' => 'Malformed token'];
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $payloadJson = $this->base64UrlDecode($base64Payload);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return ['valid' => false, 'message' => 'Invalid payload'];
        }

        $expectedSig = $this->base64UrlEncode(hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true));
        if (!hash_equals($expectedSig, $base64Signature)) {
            return ['valid' => false, 'message' => 'Signature mismatch'];
        }

        $now = time();
        if (isset($payload['exp']) && $now > $payload['exp']) {
            return ['valid' => false, 'expired' => true, 'message' => 'Token expired', 'payload' => $payload];
        }

        return ['valid' => true, 'payload' => $payload];
    }
}
