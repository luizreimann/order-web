<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parser único de dinheiro digitado (pt-BR). Regra completa em {@see Money}.
 */
class MoneyTest extends TestCase
{
    /** @return array<string, array{string|int|float|null, int|null}> */
    public static function cases(): array
    {
        return [
            // vírgula presente: pontos são milhar, a vírgula é o decimal
            'milhar e decimal' => ['1.250,00', 125000],
            'um decimal' => ['1.250,5', 125050],
            'sem milhar' => ['19,90', 1990],
            'sem milhar, valor grande' => ['1250,00', 125000],
            'dois milhares' => ['1.234.567,89', 123456789],
            'centavos' => ['0,99', 99],
            'um centavo digitado como 0,5' => ['0,5', 50],
            'zero' => ['0,00', 0],

            // só ponto: decimal (seeder/factories/API legada)
            'ponto decimal' => ['19.90', 1990],
            'ponto decimal curto' => ['19.9', 1990],
            'ponto decimal de milhar' => ['1250.00', 125000],

            // só dígitos: reais inteiros
            'inteiro' => ['20', 2000],
            'inteiro grande' => ['1250', 125000],
            'zero inteiro' => ['0', 0],

            // símbolo, espaços
            'R$ com espaço' => ['R$ 1.250,00', 125000],
            'R$ colado' => ['R$19,90', 1990],
            'r$ minúsculo' => ['r$ 5', 500],
            'espaços nas pontas' => ["  19,90 \n", 1990],
            'espaço não separável' => ["R\$\u{00A0}19,90", 1990],

            // números nativos (seeder, factories, testes)
            'int' => [15, 1500],
            'float' => [19.9, 1990],
            'float com erro binário' => [109.90, 10990],
            'float 1.15' => [1.15, 115],
            'float 0.29' => [0.29, 29],
            'float 42.5' => [42.50, 4250],

            // inválidos
            'null' => [null, null],
            'vazio' => ['', null],
            'só espaços' => ['   ', null],
            'só R$' => ['R$', null],
            'letras' => ['abc', null],
            'letras coladas' => ['12a', null],
            'negativo' => ['-5', null],
            'negativo com vírgula' => ['-5,00', null],
            'positivo com sinal' => ['+5', null],
            'múltiplas vírgulas' => ['1,2,3', null],
            'duas vírgulas decimais' => ['1,50,00', null],
            'mais de 2 decimais com vírgula' => ['19,999', null],
            'mais de 2 decimais com ponto' => ['19.999', null],
            'ambíguo: 1.250 sem vírgula' => ['1.250', null],
            'ambíguo: 1.250.000 sem vírgula' => ['1.250.000', null],
            'milhar mal agrupado' => ['1.25,00', null],
            'milhar mal agrupado 2' => ['12.3456,00', null],
            'vírgula sem decimais' => ['19,', null],
            'vírgula inicial' => [',50', null],
            'ponto inicial' => ['.5', null],
            'ponto e vírgula invertidos' => ['1,250.00', null],
            'notação científica' => ['1e3', null],
            'hexa' => ['0x1F', null],
            'int negativo' => [-1, null],
            'float negativo' => [-0.01, null],
            'float NaN' => [NAN, null],
            'float INF' => [INF, null],
            'grande demais' => ['1.000.000.000,00', null],
            'quebra de linha no meio' => ["19,\n90", null],
            'espaço no meio' => ['12 50', null],
            'espaço depois da vírgula' => ['19, 90', null],
            'R$ no meio' => ['19 R$ 90', null],
            'R$ duplo' => ['R$ R$ 5', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_to_cents(string|int|float|null $input, ?int $expected): void
    {
        $this->assertSame($expected, Money::toCents($input), 'entrada: '.var_export($input, true));
    }

    /** @return array<string, array{int}> */
    public static function roundTrip(): array
    {
        $sets = [];
        foreach ([0, 1, 99, 100, 101, 1999, 99900, 100000, 100001, 125000, 123456789, 99999999, Money::MAX_CENTS] as $cents) {
            $sets["{$cents} centavos"] = [$cents];
        }

        return $sets;
    }

    #[DataProvider('roundTrip')]
    public function test_format_then_parse_is_idempotent(int $cents): void
    {
        $this->assertSame($cents, Money::toCents(Money::format($cents)));
    }

    public function test_format_uses_pt_br_separators(): void
    {
        $this->assertSame('0,00', Money::format(0));
        $this->assertSame('0,99', Money::format(99));
        $this->assertSame('1,00', Money::format(100));
        $this->assertSame('19,90', Money::format(1990));
        $this->assertSame('1.000,00', Money::format(100000));
        $this->assertSame('1.250,00', Money::format(125000));
        $this->assertSame('999.999,99', Money::format(99999999));
    }

    public function test_format_rejects_negative_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::format(-1);
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Money::isValid('1.250,00'));
        $this->assertFalse(Money::isValid('1.250'));
        $this->assertFalse(Money::isValid(null));
    }
}
