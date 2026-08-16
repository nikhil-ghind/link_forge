<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The wire format pushed onto the Redis click buffer.
 *
 * Only cheap-to-read request attributes are captured on the hot path; all the
 * expensive derivation (UA parsing, referrer host extraction, geo lookup) is
 * deferred to the drain worker.
 */
final class ClickRecord
{
    public function __construct(
        public readonly int $linkId,
        public readonly int $timestamp,
        public readonly ?string $referrer,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
        public readonly ?string $country,
        public readonly ?string $language,
    ) {}

    public static function fromRequest(int $linkId, Request $request): self
    {
        return new self(
            linkId: $linkId,
            timestamp: time(),
            referrer: self::truncate($request->headers->get('referer'), 512),
            userAgent: self::truncate($request->headers->get('user-agent'), 512),
            ip: $request->ip(),
            // CloudFront / ALB can resolve geo for us; falling back to a
            // MaxMind lookup in the worker costs nothing on the hot path.
            country: self::truncate(
                $request->headers->get('cloudfront-viewer-country')
                    ?? $request->headers->get('x-country-code'),
                2
            ),
            language: self::truncate($request->headers->get('accept-language'), 16),
        );
    }

    public function encode(): string
    {
        return json_encode([
            'l' => $this->linkId,
            't' => $this->timestamp,
            'r' => $this->referrer,
            'u' => $this->userAgent,
            'i' => $this->ip,
            'c' => $this->country,
            'a' => $this->language,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    public static function decode(string $raw): ?self
    {
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['l'], $data['t'])) {
            return null;
        }

        return new self(
            linkId: (int) $data['l'],
            timestamp: (int) $data['t'],
            referrer: $data['r'] ?? null,
            userAgent: $data['u'] ?? null,
            ip: $data['i'] ?? null,
            country: $data['c'] ?? null,
            language: $data['a'] ?? null,
        );
    }

    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
