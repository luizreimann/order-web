<?php

namespace Tests\Unit\Support;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sameNumber(): array
    {
        return [
            'mascara' => ['(11) 99999-9999', '11999999999'],
            'digitos' => ['11999999999', '11999999999'],
            'DDI com +' => ['+55 11 99999-9999', '11999999999'],
            'DDI sem +' => ['5511999999999', '11999999999'],
            'DDI com pontos' => ['+55 (11) 99999.9999', '11999999999'],
            'fixo com mascara' => ['(11) 3333-4444', '1133334444'],
            'fixo com DDI' => ['+55 11 3333-4444', '1133334444'],
            'espacos nas pontas' => ["  11 99999-9999\n", '11999999999'],
        ];
    }

    #[DataProvider('sameNumber')]
    public function test_normalize_maps_every_spelling_to_the_canonical_form(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function test_ddd_55_is_never_mistaken_for_the_country_code(): void
    {
        // DDD 55 (RS), 11 e 10 dígitos: o "55" é DDD, não DDI.
        $this->assertSame('55999999999', Phone::normalize('(55) 99999-9999'));
        $this->assertSame('5533334444', Phone::normalize('(55) 3333-4444'));
        // DDI + DDD 55
        $this->assertSame('55999999999', Phone::normalize('+55 55 99999-9999'));
    }

    public function test_different_numbers_never_collide(): void
    {
        $numbers = [
            '(11) 99999-9999', '(21) 99999-9999', '(55) 99999-9999', '(11) 99999-9998',
            '+1 202 555 0123', '+351 912 345 678', '(11) 3333-4444', '(55) 3333-4444',
        ];

        $normalized = array_map(Phone::normalize(...), $numbers);

        $this->assertSame($normalized, array_values(array_unique($normalized)));
    }

    public function test_foreign_numbers_keep_all_their_digits(): void
    {
        $this->assertSame('12025550123', Phone::normalize('+1 202 555 0123'));
        $this->assertSame('351912345678', Phone::normalize('+351 912 345 678'));
    }

    public function test_55_prefix_is_kept_when_the_remaining_ddd_is_not_a_valid_ddd(): void
    {
        // 12 dígitos começando com 55, mas "05..." não é DDD: não é número brasileiro com DDI.
        $this->assertSame('550599999999', Phone::normalize('550599999999'));
    }

    public function test_empty_inputs(): void
    {
        $this->assertSame('', Phone::normalize(null));
        $this->assertSame('', Phone::normalize(''));
        $this->assertSame('', Phone::normalize('abc'));
        $this->assertNull(Phone::normalizeOrNull(null));
        $this->assertNull(Phone::normalizeOrNull('  '));
        $this->assertSame('11999999999', Phone::normalizeOrNull('(11) 99999-9999'));
    }

    public function test_normalize_is_idempotent(): void
    {
        foreach (self::sameNumber() as [$input]) {
            $once = Phone::normalize($input);
            $this->assertSame($once, Phone::normalize($once));
        }
    }

    public function test_is_valid(): void
    {
        foreach (['(11) 99999-9999', '11999999999', '+55 11 99999-9999', '(11) 3333-4444', '+1 202 555 0123'] as $ok) {
            $this->assertTrue(Phone::isValid($ok), $ok);
        }

        foreach ([null, '', '12345', 'abcdefghijk', '11 99999-999x', '1234567890123456', '(11) 9999'] as $bad) {
            $this->assertFalse(Phone::isValid($bad), (string) $bad);
        }
    }
}
