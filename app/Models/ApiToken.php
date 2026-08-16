<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'token_hash',
        'abilities',
        'rate_limit_per_minute',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'rate_limit_per_minute' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Issue a new token, returning [model, plaintext]. The plaintext is never
     * recoverable afterwards.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(string $name, array $abilities = ['links:read', 'links:write', 'analytics:read'], ?int $rateLimit = null): array
    {
        $plaintext = 'lf_'.Str::random(48);

        $token = static::create([
            'name' => $name,
            'token_hash' => static::hashToken($plaintext),
            'abilities' => implode(',', $abilities),
            'rate_limit_per_minute' => $rateLimit,
        ]);

        return [$token, $plaintext];
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function hasAbility(string $ability): bool
    {
        $abilities = array_map('trim', explode(',', (string) $this->abilities));

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
