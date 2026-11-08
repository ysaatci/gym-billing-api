<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts a request ID on every log line and in the X-Request-Id response header,
 * so a support ticket quoting the header leads straight to the logs.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Reuse the caller's ID (load balancer, other service) only if it looks safe:
        // it ends up in logs and headers.
        $incoming = $request->header('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
