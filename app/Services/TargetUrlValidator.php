<?php

namespace App\Services;

/**
 * Validates the destination of a short link.
 *
 * A shortener is an open redirector by design, which makes it an attractive
 * wrapper for phishing and for reaching internal addresses from someone else's
 * browser. The rules here are the minimum: scheme allow-list, length cap, and a
 * block on loopback/private/link-local destinations (including the EC2 metadata
 * endpoint).
 */
class TargetUrlValidator
{
    /**
     * @return string|null  error message, or null when the URL is acceptable
     */
    public function validate(string $url): ?string
    {
        $url = trim($url);

        $maxLength = (int) config('linkforge.redirect.max_target_length', 2048);

        if ($url === '' || strlen($url) > $maxLength) {
            return "Target URL must be between 1 and {$maxLength} characters.";
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Target URL must be an absolute URL including scheme and host.';
        }

        $scheme = strtolower($parts['scheme']);
        $allowed = array_map('strtolower', (array) config('linkforge.redirect.allowed_schemes', ['http', 'https']));

        if (! in_array($scheme, $allowed, true)) {
            return 'Target URL scheme must be one of: '.implode(', ', $allowed).'.';
        }

        $host = strtolower($parts['host']);

        if ($this->isBlockedHost($host)) {
            return 'Target URL points at a blocked or internal host.';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Target URL is not a well-formed URL.';
        }

        return null;
    }

    public function isBlockedHost(string $host): bool
    {
        $host = trim($host, '[]');
        $blocked = array_map('strtolower', (array) config('linkforge.redirect.blocked_hosts', []));

        if (in_array($host, $blocked, true)) {
            return true;
        }

        // Reject anything resolving to a reserved range by literal form. A full
        // DNS-resolution check belongs at request time, not at create time,
        // because DNS can be re-pointed after the link is stored.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return str_ends_with($host, '.internal') || str_ends_with($host, '.local') || $host === 'localhost';
    }

    public function host(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
    }
}
