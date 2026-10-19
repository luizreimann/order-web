<?php

namespace App\Support;

/**
 * Parser/formatador ÚNICO de dinheiro digitado em reais (pt-BR) <-> centavos inteiros.
 *
 * Existia uma conversão por campo (`str_replace(',', '.')` + `(float)`), que lia "1.250,00" como
 * 1,25 e "abc" como 0. Tudo que converte reais digitados em centavos passa por aqui.
 *
 * REGRAS de {@see self::toCents()} (entrada string; tudo que não casar é INVÁLIDO => `null`):
 *
 *  1. "R$" (qualquer caixa) e espaços nas pontas / entre "R$" e o número são removidos (inclusive o
 *     espaço não separável); espaço NO MEIO do número é inválido;
 *  2. COM vírgula: a vírgula é o decimal (1 ou 2 casas) e os pontos são separadores de milhar, que
 *     precisam estar bem agrupados de 3 em 3: "1.250,00" => 125000, "1.250,5" => 125050,
 *     "19,90" => 1990, "1250,00" => 125000. "1.25,00", "1,250.00", "1,2,3", "19," => inválido;
 *  3. SEM vírgula, com ponto: o ponto é o decimal (1 ou 2 casas): "19.90" => 1990, "19.9" => 1990.
 *     É o formato que o seeder e a API legada usam (floats em reais);
 *  4. SÓ dígitos: reais inteiros: "20" => 2000;
 *  5. negativos, sinal "+", letras, notação científica, vazio, mais de 2 casas decimais, valor acima
 *     de {@see self::MAX_CENTS} => inválido.
 *
 * DECISÃO sobre "1.250" (ponto, sem vírgula, 3 dígitos depois): é AMBÍGUO (pt-BR: mil duzentos e
 * cinquenta; formato de máquina: 1,250) e errar aqui corrompe preço. Não adivinhamos: é INVÁLIDO
 * (cai na regra "mais de 2 casas decimais") e o usuário precisa digitar "1.250,00" ou "1250".
 * O formulário nunca gera esse formato (exibe com {@see self::format()}) e o seeder/factories só
 * passam floats com até 2 casas ("109.90") ou centavos inteiros.
 *
 * Entrada `int` = reais inteiros. Entrada `float` = reais, arredondada ao centavo (corrige o
 * truncamento de `(int) (1.15 * 100)` = 114); float negativo/NaN/INF => inválido.
 */
final class Money
{
    /** R$ 999.999.999,99 — folga larga para `unsignedBigInteger` e para o `float` do PHP. */
    public const MAX_CENTS = 99_999_999_999;

    public static function toCents(string|int|float|null $value): ?int
    {
        return match (true) {
            $value === null => null,
            is_int($value) => self::within($value >= 0 ? $value * 100 : null),
            is_float($value) => is_finite($value) && $value >= 0 ? self::within((int) round($value * 100)) : null,
            default => self::parseString($value),
        };
    }

    public static function isValid(string|int|float|null $value): bool
    {
        return self::toCents($value) !== null;
    }

    /** Centavos => "1.250,00" (o formato que o formulário exibe e o parser aceita de volta). */
    public static function format(int $cents): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException('Valor monetário negativo: '.$cents);
        }

        return number_format(intdiv($cents, 100), 0, '', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function parseString(string $raw): ?int
    {
        // Espaço (inclusive NBSP, U+00A0, que o `NumberFormatter` põe depois de "R$") só é tolerado
        // nas pontas e entre "R$" e o número; espaço NO MEIO ("12 50") é inválido: não adivinhamos.
        $s = preg_replace('/^[\s\x{00A0}]*(?:R\$[\s\x{00A0}]*)?|[\s\x{00A0}]+\z/iu', '', $raw);

        if ($s === null || $s === '') {
            return null;
        }

        if (str_contains($s, ',')) {
            if (! preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+),(\d{1,2})\z/', $s, $m)) {
                return null;
            }

            return self::build(str_replace('.', '', $m[1]), $m[2]);
        }

        if (preg_match('/^(\d+)\.(\d{1,2})\z/', $s, $m)) {
            return self::build($m[1], $m[2]);
        }

        if (preg_match('/^\d+\z/', $s)) {
            return self::build($s, '');
        }

        return null;
    }

    private static function build(string $reais, string $decimals): ?int
    {
        if (strlen(ltrim($reais, '0')) > 10) {
            return null;
        }

        return self::within(((int) $reais) * 100 + (int) str_pad($decimals, 2, '0'));
    }

    private static function within(?int $cents): ?int
    {
        return $cents !== null && $cents <= self::MAX_CENTS ? $cents : null;
    }
}
