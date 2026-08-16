<?php

namespace App\Services;

/**
 * Country resolution for a click.
 *
 * In the AWS deployment the edge already knows the country: CloudFront sets
 * CloudFront-Viewer-Country and the value is captured into the ClickRecord on
 * the hot path for free. This class only handles the fallback cases — a
 * private/reserved address (internal traffic, health checks) resolves to null
 * rather than being misattributed, and an optional MaxMind-style lookup table
 * can be plugged in without touching the redirect path.
 */
class GeoResolver
{
    public function resolve(?string $edgeCountry, ?string $ip): ?string
    {
        $country = $this->normalise($edgeCountry);

        if ($country !== null) {
            return $country;
        }

        if ($ip === null || $ip === '' || $this->isPrivate($ip)) {
            return null;
        }

        return $this->lookup($ip);
    }

    /**
     * Hook point for a real GeoIP database. Left as a null lookup so the
     * service has no runtime dependency it cannot satisfy in every
     * environment; wiring MaxMind in means replacing this one method.
     */
    protected function lookup(string $ip): ?string
    {
        return null;
    }

    public function isPrivate(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function normalise(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        // CloudFront uses "XX" for unknown / anonymised viewers.
        if (strlen($country) !== 2 || $country === 'XX' || ! ctype_alpha($country)) {
            return null;
        }

        return $country;
    }

    /**
     * Salted hash used for unique-visitor counts when raw IP storage is off.
     */
    public function visitorHash(?string $ip, ?string $userAgent): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $salt = (string) config('linkforge.clicks.ip_hash_salt', '');

        return substr(hash('sha256', $salt.'|'.$ip.'|'.substr((string) $userAgent, 0, 64)), 0, 32);
    }
}
