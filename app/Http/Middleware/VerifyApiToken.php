<?php

namespace App\Http\Middleware;

use App\Services\DecryptsEncryptedToken;
use Closure;
use Illuminate\Http\Request;

class VerifyApiToken
{
    use DecryptsEncryptedToken;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $requestOrigin = $request->headers->get('origin');
        $allowedOrigins = config('cors.allowed_origins', []);

        $normalizedOrigin = rtrim($requestOrigin, '/');
        $normalizedAllowedOrigins = array_map(static fn($origin) => rtrim($origin, '/'), $allowedOrigins);

        if (!in_array($normalizedOrigin, $normalizedAllowedOrigins, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden Access.'
            ], 403);
        }
        $apiToken = $request->header('X-API-TOKEN') ?? $request->query('api_token');
        $validTokens = config('app.api_tokens', []);

        if (empty($validTokens)) {
            return response()->json([
                'success' => false,
                'message' => 'API token not configured on server.'
            ], 500);
        }

        if (empty($apiToken)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing API token.'
            ], 401);
        }

        if (strpos($apiToken, 'v1.aes:') !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API token.'
            ], 401);
        }

        $decrypted = $this->decryptEncryptedToken($apiToken, $validTokens);

        if (empty($decrypted) || !in_array($decrypted, $validTokens, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API token.'
            ], 401);
        }

        return $next($request);
    }
}