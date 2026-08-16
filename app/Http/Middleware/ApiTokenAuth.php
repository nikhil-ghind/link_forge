<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer-token auth for the management/analytics API.
 *
 * Tokens are looked up by SHA-256 hash (the plaintext is never stored) and the
 * lookup is memoised for a minute so an API burst does not hit MySQL once per
 * request. `last_used_at` is written at most once a minute for the same reason.
 */
class ApiTokenAuth
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $plaintext = $this->tokenFromRequest($request);

        if ($plaintext === null) {
            return $this->deny('Missing API token.', 401);
        }

        $hash = ApiToken::hashToken($plaintext);

        $token = Cache::remember(
            'api_token:'.$hash,
            now()->addMinute(),
            fn () => ApiToken::query()->where('token_hash', $hash)->first()
        );

        if ($token === null || $token->isExpired()) {
            return $this->deny('Invalid or expired API token.', 401);
        }

        if ($ability !== null && ! $token->hasAbility($ability)) {
            return $this->deny("This token lacks the [{$ability}] ability.", 403);
        }

        if ($exceeded = $this->rateLimitExceeded($token)) {
            return $exceeded;
        }

        $this->touch($token);

        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    private function tokenFromRequest(Request $request): ?string
    {
        $header = (string) $request->headers->get('authorization', '');

        if (str_starts_with(strtolower($header), 'bearer ')) {
            return trim(substr($header, 7)) ?: null;
        }

        $header = $request->headers->get('x-api-key');

        return $header !== null && $header !== '' ? $header : null;
    }

    private function rateLimitExceeded(ApiToken $token): ?JsonResponse
    {
        $limit = $token->rate_limit_per_minute ?? (int) config('linkforge.api.rate_limit_per_minute', 120);

        if ($limit <= 0) {
            return null;
        }

        $key = 'api_rate:'.$token->id.':'.now()->format('YmdHi');
        $hits = (int) Cache::increment($key);

        if ($hits === 1) {
            Cache::put($key, 1, now()->addSeconds(90));
        }

        if ($hits > $limit) {
            return response()->json([
                'message' => 'Rate limit exceeded.',
                'limit' => $limit,
            ], 429, ['Retry-After' => 60 - (int) now()->format('s')]);
        }

        return null;
    }

    private function touch(ApiToken $token): void
    {
        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now()) > 60) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    private function deny(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
