<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSecurityHeaders
{
    /**
     * Handle an incoming request and add security headers to the response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent browsers from MIME-type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking attacks
        $response->headers->set('X-Frame-Options', 'DENY');

        // Enable XSS filtering in older browsers
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Force HTTPS connection for this domain and subdomains
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // Restrict which features and APIs can be used
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');

        // Control referrer information sent in requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Content Security Policy - restrict resource loading
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data: https:; "
             . "font-src 'self' data:; "
             . "connect-src 'self' https:; "
             . "frame-ancestors 'none'; "
             . "base-uri 'self'; "
             . "form-action 'self'";

        $response->headers->set('Content-Security-Policy', $csp);

        // Remove server information disclosure
        $response->headers->set('Server', 'Application Server');
        
        // Remove X-Powered-By header that exposes PHP
        $response->headers->remove('X-Powered-By');

        // Disable client-side caching for sensitive data
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}