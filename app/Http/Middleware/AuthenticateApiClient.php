<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextKey = $request->bearerToken();

        if (!$plainTextKey) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $keyHash = hash('sha256', $plainTextKey);

        $apiClient = ApiClient::query()
            ->where('key_hash', $keyHash)
            ->where('is_active', true)
            ->first();

        if (!$apiClient) {
            return response()->json([
                'message' => 'Invalid or inactive API key.',
            ], 401);
        }

        $request->attributes->set('api_client', $apiClient);

        return $next($request);
    }
}