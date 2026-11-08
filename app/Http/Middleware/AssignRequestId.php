<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID that appears in every log line it writes and in the
 * X-Request-Id response header. A customer or support ticket quoting that header
 * leads straight to the right log lines.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        // Reuse the caller's ID (e.g. from a load balancer or another service) so one
        // ID follows the request across systems, but only if it looks safe: it ends up
        // in logs and headers, so we don't accept arbitrary client text.
        $incoming = $request->header('X-Request-Id');
        $requestId = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        // Context: Laravel adds everything in it to every log entry, on every channel,
        // and carries it into queued jobs dispatched during this request.
        Context::add('request_id', $requestId);

        $response = $next($request);

        // Error responses pass through here too: exceptions are already rendered
        // into responses further down the pipeline.
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
