<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = $request->bearerToken();

        if (! $plainTextKey) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $keyHash = hash('sha256', $plainTextKey);

        $apiClient = ApiClient::query()
            ->where('key_hash', $keyHash)
            ->where('is_active', true)
            ->first();

        if (! $apiClient) {
            return response()->json([
                'message' => 'Invalid or inactive API key.',
            ], 401);
        }

        $request->attributes->set('api_client', $apiClient);

        $limiterKey = 'webhook-ingest:client:'.$apiClient->id;

        $attempts = RateLimiter::increment($limiterKey, 60);

        if ($attempts > 60) {
            return response()->json([
                'message' => 'Too many requests.',
            ], 429)->withHeaders([
                'Retry-After' => RateLimiter::availableIn($limiterKey),
            ]);
        }

        return $next($request);
    }
}
