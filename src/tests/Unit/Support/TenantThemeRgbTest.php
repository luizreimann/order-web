<?php

namespace Tests\Unit\Support;

use App\Support\TenantTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `TenantTheme::rgb()` alimenta `--bs-primary-rgb` (o Bootstrap compõe `rgba(var(--bs-primary-rgb), a)`).
 * Só pode devolver inteiros: é a barreira entre o valor do banco e o CSS, como `color()`.
 */
class TenantThemeRgbTest extends TestCase
{
    /** @return array<string, array{mixed, string}> */
    public static function colors(): array
    {
        return [
            'hex longo' => ['#ec4899', '236, 72, 153'],
            'hex curto expande' => ['#ABC', '170, 187, 204'],
            'maiúsculas' => ['#0D6EFD', '13, 110, 253'],
            'preto' => ['#000', '0, 0, 0'],
            'branco' => ['#ffffff', '255, 255, 255'],
        ];
    }

    #[DataProvider('colors')]
    public function test_rgb_of_valid_hex(mixed $hex, string $expected): void
    {
        $this->assertSame($expected, TenantTheme::primaryRgb($hex));
    }

    /** @return array<string, array{mixed}> */
    public static function invalid(): array
    {
        return [
            'injeção' => ['#fff;} body{background:url(https://evil.test/)}'],
            'nome de cor' => ['red'],
            'curto demais' => ['#12'],
            'nulo' => [null],
            'array' => [['#fff']],
            'quebra de linha' => ["#fff\n"],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_value_falls_back_to_the_default_triplet(mixed $value): void
    {
        $this->assertSame('13, 110, 253', TenantTheme::primaryRgb($value));
        $this->assertMatchesRegularExpression('/\A\d{1,3}, \d{1,3}, \d{1,3}\z/', TenantTheme::rgb($value, '#6c757d'));
        $this->assertSame('108, 117, 125', TenantTheme::rgb($value, '#6c757d'));
    }
}
