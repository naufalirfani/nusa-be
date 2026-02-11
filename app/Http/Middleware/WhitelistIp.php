<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class WhitelistIp
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $raw = env('API_WHITELIST_IPS', '');
        $list = array_filter(array_map('trim', explode(',', $raw)));

        // If no whitelist configured, allow by default
        if (empty($list)) {
            return $next($request);
        }

        $ip = $request->ip();

        if (!in_array($ip, $list, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: IP not allowed.'
            ], 403);
        }

        return $next($request);
    }
}
