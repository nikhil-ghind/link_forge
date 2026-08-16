<?php

namespace App\Services;

/**
 * Deliberately small, dependency-free UA classifier.
 *
 * Full UA databases are heavy and need constant updating; for click analytics
 * we only need a device bucket, a browser family and an OS family, plus a solid
 * bot signal. This runs in the drain worker, never on the redirect path.
 */
class UserAgentParser
{
    private const BOT_TOKENS = [
        'bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python-requests',
        'httpclient', 'okhttp', 'headlesschrome', 'phantomjs', 'preview',
        'monitoring', 'uptime', 'pingdom', 'facebookexternalhit', 'whatsapp',
        'slackbot', 'telegrambot', 'discordbot', 'embedly', 'go-http-client',
    ];

    /**
     * Ordered longest/most-specific first: Edge and Opera both advertise
     * "Chrome", Chrome advertises "Safari", so order is the whole algorithm.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'EdgiOS' => 'Edge',
        'OPR/' => 'Opera',
        'Opera' => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'YaBrowser' => 'Yandex',
        'Vivaldi' => 'Vivaldi',
        'Brave' => 'Brave',
        'CriOS' => 'Chrome',
        'Chrome' => 'Chrome',
        'FxiOS' => 'Firefox',
        'Firefox' => 'Firefox',
        'Safari' => 'Safari',
        'MSIE' => 'Internet Explorer',
        'Trident' => 'Internet Explorer',
    ];

    /**
     * @var array<string, string>
     */
    private const OPERATING_SYSTEMS = [
        'Windows NT 10' => 'Windows',
        'Windows NT' => 'Windows',
        'Windows Phone' => 'Windows Phone',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'iPod' => 'iOS',
        'Mac OS X' => 'macOS',
        'Macintosh' => 'macOS',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Ubuntu' => 'Linux',
        'Linux' => 'Linux',
        'FreeBSD' => 'BSD',
    ];

    /**
     * @return array{device_type: string, browser: string|null, os: string|null, is_bot: bool}
     */
    public function parse(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);

        if ($ua === '') {
            return [
                'device_type' => 'other',
                'browser' => null,
                'os' => null,
                'is_bot' => true, // no UA at all is almost always automation
            ];
        }

        $lower = strtolower($ua);

        if ($this->isBot($lower)) {
            return [
                'device_type' => 'bot',
                'browser' => null,
                'os' => $this->matchOs($ua),
                'is_bot' => true,
            ];
        }

        return [
            'device_type' => $this->deviceType($lower),
            'browser' => $this->matchBrowser($ua),
            'os' => $this->matchOs($ua),
            'is_bot' => false,
        ];
    }

    public function isBot(string $lowercaseUa): bool
    {
        foreach (self::BOT_TOKENS as $token) {
            if (str_contains($lowercaseUa, $token)) {
                return true;
            }
        }

        return false;
    }

    private function deviceType(string $lower): string
    {
        if (str_contains($lower, 'ipad') || (str_contains($lower, 'android') && ! str_contains($lower, 'mobile'))) {
            return 'tablet';
        }

        if (str_contains($lower, 'tablet') || str_contains($lower, 'kindle') || str_contains($lower, 'playbook')) {
            return 'tablet';
        }

        if (str_contains($lower, 'mobi') || str_contains($lower, 'iphone') || str_contains($lower, 'ipod')
            || str_contains($lower, 'windows phone') || str_contains($lower, 'blackberry')) {
            return 'mobile';
        }

        if (str_contains($lower, 'smart-tv') || str_contains($lower, 'appletv') || str_contains($lower, 'googletv')) {
            return 'tv';
        }

        return 'desktop';
    }

    private function matchBrowser(string $ua): ?string
    {
        foreach (self::BROWSERS as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return 'Other';
    }

    private function matchOs(string $ua): ?string
    {
        foreach (self::OPERATING_SYSTEMS as $needle => $name) {
            if (str_contains($ua, $needle)) {
                return $name;
            }
        }

        return 'Other';
    }
}
