<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitWebhookPayloadSize
{
    private const MAX_BYTES = 65_536;

    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $actualSize = strlen($request->getContent());

        if (
            $contentLength > self::MAX_BYTES ||
            $actualSize > self::MAX_BYTES
        ) {
            return response()->json([
                'message' => 'Request payload exceeds the maximum allowed size.',
                'max_bytes' => self::MAX_BYTES,
            ], 413);
        }

        return $next($request);
    }
}
