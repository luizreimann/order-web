<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mutators `price` / `compare_at` do Product: usam o parser único {@see \App\Support\Money} e NUNCA
 * transformam entrada inválida em R$ 0,00 em silêncio.
 */
class ProductPriceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string|int|float, int}> */
    public static function valid(): array
    {
        return [
            'milhar pt-BR (o bug: virava 125)' => ['1.250,00', 125000],
            'vírgula' => ['19,90', 1990],
            'ponto decimal (seeder)' => ['19.90', 1990],
            'float (seeder)' => [109.90, 10990],
            'int' => [15, 1500],
            'R$' => ['R$ 2.000,50', 200050],
        ];
    }

    #[DataProvider('valid')]
    public function test_price_and_compare_at_parse_pt_br(string|int|float $input, int $cents): void
    {
        $product = (new Product)->fill(['price' => $input, 'compare_at' => $input]);

        $this->assertSame($cents, $product->price_cents);
        $this->assertSame($cents, $product->compare_at_cents);
    }

    public function test_price_of_zero_is_valid(): void
    {
        $product = (new Product)->fill(['price' => '0,00']);

        $this->assertSame(0, $product->price_cents);
    }

    public function test_compare_at_null_or_empty_clears_it(): void
    {
        $product = (new Product)->fill(['compare_at' => '10,00']);
        $this->assertSame(1000, $product->compare_at_cents);

        $product->fill(['compare_at' => null]);
        $this->assertNull($product->compare_at_cents);

        $product->fill(['compare_at' => '10,00']);
        $product->fill(['compare_at' => '']);
        $this->assertNull($product->compare_at_cents);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidForCompareAt(): array
    {
        return [
            'letras' => ['abc'], 'negativo' => ['-1,00'], 'ambíguo' => ['1.250'],
            'mais de 2 decimais' => ['1,999'], 'array' => [['1']],
        ];
    }

    /**
     * `price` é obrigatório: vazio e null também são inválidos (compare_at os trata como "sem preço riscado").
     *
     * @return array<string, array{mixed}>
     */
    public static function invalid(): array
    {
        return self::invalidForCompareAt() + ['vazio' => [''], 'null' => [null]];
    }

    #[DataProvider('invalid')]
    public function test_invalid_price_throws_instead_of_becoming_zero(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Product)->fill(['price' => $input]);
    }

    #[DataProvider('invalidForCompareAt')]
    public function test_invalid_compare_at_throws_instead_of_becoming_zero(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Product)->fill(['compare_at' => $input]);
    }

    public function test_invalid_price_does_not_touch_the_persisted_value(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 125000]);

        $this->assertThrows(fn () => $product->fill(['price' => 'abc']), \InvalidArgumentException::class);

        $this->assertSame(125000, (int) $product->fresh()->price_cents);
    }
}
