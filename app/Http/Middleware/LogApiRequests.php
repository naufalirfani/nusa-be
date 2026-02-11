<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LogApiRequests
{
    /**
     * Handle an incoming request and log basic API request info.
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $data = [
                'time' => now()->toDateTimeString(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'user_agent' => $request->header('User-Agent'),
                'has_token' => $request->header('X-API-TOKEN') ? true : false,
                'query' => $request->query(),
                'body' => $this->safePayload($request->all()),
                'user_id' => optional($request->user())->id,
            ];

            $line = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL;
            @file_put_contents(storage_path('logs/api_requests.log'), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Don't break the request if logging fails
        }

        return $next($request);
    }

    /**
     * Remove large or sensitive fields from payload to keep log small.
     */
    protected function safePayload(array $payload): array
    {
        // Remove file uploads and large fields
        foreach ($payload as $k => $v) {
            if (is_object($v) || is_resource($v)) {
                unset($payload[$k]);
                continue;
            }
            if (is_string($v) && strlen($v) > 1000) {
                $payload[$k] = substr($v, 0, 1000) . '...';
            }
        }

        return $payload;
    }
}
