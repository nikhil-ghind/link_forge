<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Bijective base62 encoding used to turn integer IDs into short slugs.
 *
 * The alphabet is digit-first so encodings sort in the same order as the
 * integers they came from, which keeps counter-derived slugs monotonic and
 * therefore index-friendly on the MySQL side.
 */
final class Base62
{
    public const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const BASE = 62;

    /**
     * Characters that are easy to confuse when a slug is read aloud or copied
     * from print. Used by the random strategy only — the counter strategy must
     * keep the full alphabet to stay bijective.
     */
    public const UNAMBIGUOUS_ALPHABET = '23456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';

    public static function encode(int $number): string
    {
        if ($number < 0) {
            throw new InvalidArgumentException('Base62 can only encode non-negative integers.');
        }

        if ($number === 0) {
            return '0';
        }

        $out = '';

        while ($number > 0) {
            $out = self::ALPHABET[$number % self::BASE].$out;
            $number = intdiv($number, self::BASE);
        }

        return $out;
    }

    public static function decode(string $value): int
    {
        if ($value === '') {
            throw new InvalidArgumentException('Cannot decode an empty string.');
        }

        $number = 0;

        for ($i = 0, $len = strlen($value); $i < $len; $i++) {
            $position = strpos(self::ALPHABET, $value[$i]);

            if ($position === false) {
                throw new InvalidArgumentException("Character '{$value[$i]}' is not in the base62 alphabet.");
            }

            $number = ($number * self::BASE) + $position;
        }

        return $number;
    }

    /**
     * Left-pad an encoded value so every slug from the counter strategy has a
     * stable minimum width.
     */
    public static function encodePadded(int $number, int $minLength): string
    {
        return str_pad(self::encode($number), $minLength, '0', STR_PAD_LEFT);
    }

    /**
     * Cryptographically random slug drawn from the unambiguous alphabet.
     */
    public static function random(int $length): string
    {
        if ($length < 1) {
            throw new InvalidArgumentException('Slug length must be at least 1.');
        }

        $alphabet = self::UNAMBIGUOUS_ALPHABET;
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function isValid(string $value): bool
    {
        return $value !== '' && strspn($value, self::ALPHABET) === strlen($value);
    }
}
