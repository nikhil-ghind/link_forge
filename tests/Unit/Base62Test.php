<?php

namespace Tests\Unit;

use App\Support\Base62;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class Base62Test extends TestCase
{
    public function test_it_encodes_small_values_against_the_alphabet(): void
    {
        $this->assertSame('0', Base62::encode(0));
        $this->assertSame('9', Base62::encode(9));
        $this->assertSame('a', Base62::encode(10));
        $this->assertSame('Z', Base62::encode(61));
        $this->assertSame('10', Base62::encode(62));
    }

    public function test_encoding_round_trips(): void
    {
        foreach ([1, 61, 62, 3843, 100_000_000, 9_999_999_999] as $value) {
            $this->assertSame($value, Base62::decode(Base62::encode($value)));
        }
    }

    public function test_encoding_preserves_ordering_for_equal_width_values(): void
    {
        // The digit-first alphabet is what makes counter-derived slugs sort in
        // the same order as their ids, keeping the MySQL index append-only.
        $previous = null;

        for ($i = 100_000; $i < 100_050; $i++) {
            $encoded = Base62::encodePadded($i, 7);

            if ($previous !== null) {
                $this->assertGreaterThan(0, strcmp($encoded, $previous));
            }

            $previous = $encoded;
        }
    }

    public function test_padded_encoding_hits_the_requested_width(): void
    {
        $this->assertSame('0000001', Base62::encodePadded(1, 7));
        $this->assertSame(7, strlen(Base62::encodePadded(123456, 7)));
    }

    public function test_random_slugs_avoid_ambiguous_characters(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $slug = Base62::random(8);

            $this->assertSame(8, strlen($slug));
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $slug);
            $this->assertTrue(Base62::isValid($slug));
        }
    }

    public function test_it_rejects_negative_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::encode(-1);
    }

    public function test_it_rejects_characters_outside_the_alphabet(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base62::decode('abc-def');
    }

    public function test_validity_check(): void
    {
        $this->assertTrue(Base62::isValid('aZ09'));
        $this->assertFalse(Base62::isValid(''));
        $this->assertFalse(Base62::isValid('has space'));
        $this->assertFalse(Base62::isValid('slash/es'));
    }
}
