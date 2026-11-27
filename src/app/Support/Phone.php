<?php

namespace App\Support;

/**
 * Normalizador ÚNICO de telefone (ARC-009).
 *
 * Todo caminho que grava ou procura um telefone (checkout, login, admin, perfil)
 * passa por aqui. O formato canônico — o que vai para `customers.phone` e é
 * comparado pela unique parcial `(tenant_id, phone)` — é: SOMENTE DÍGITOS, sem DDI
 * brasileiro.
 *
 * Regra de DDI (+55):
 *
 * - Remove tudo que não é dígito (`(11) 99999-9999`, `11 99999.9999`, `+55 11 …`).
 * - O prefixo `55` só é descartado quando o resultado é inequivocamente um número
 *   brasileiro: o total tem 12 ou 13 dígitos (DDI + DDD + 8/9 dígitos), começa com
 *   `55` e o DDD restante é válido (11–99, nenhum dígito `0`).
 * - Por que não colide: um número nacional brasileiro tem 10 ou 11 dígitos, então
 *   `5511999999999` (13) nunca é confundido com um número nacional, e o DDD 55
 *   (RS) com 10/11 dígitos (`55999999999`) NUNCA perde o `55`. Nenhum outro país tem
 *   código começando em `55`, então um número estrangeiro com DDI (`+1…`, `+351…`)
 *   nunca é alterado: fica com todos os dígitos e não colide com um brasileiro.
 * - Não há tratamento de zero de tronco (`011…`) nem de outros DDIs: números fora
 *   do padrão ficam como digitados (só dígitos). Isso é intencional — adivinhar
 *   mais do que isso arrisca juntar dois números diferentes num cliente só.
 *
 * Validade ({@see isValid()}): telefone brasileiro com DDD, fixo (10) ou celular
 * (11), depois de normalizar. Os dois dígitos do DDD são de 1 a 9. `+` só passa
 * se for o DDI `+55` (o prefixo que {@see normalize()} remove). Vazio, letra,
 * DDD com zero, outro país e comprimento fora de 10 ou 11 não passam.
 *
 * {@see format()} devolve a máscara nacional: `(11) 3333-4444` ou
 * `(11) 99999-9999`. Vazio devolve `''`. Outro comprimento devolve os dígitos
 * canônicos, sem máscara.
 */
final class Phone
{
    public const MIN_DIGITS = 10;

    public const MAX_DIGITS = 11;

    /** Caracteres aceitos na digitação: dígitos, espaço, parênteses, hífen, ponto e `+`. */
    public const ALLOWED_CHARACTERS = '/^[0-9\s().\-+]+$/';

    private function __construct() {}

    /**
     * Formato canônico; string vazia quando não há dígitos.
     */
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55')) {
            $national = substr($digits, 2);

            if ($national[0] !== '0' && $national[1] !== '0') {
                return $national;
            }
        }

        return $digits;
    }

    /**
     * Como {@see normalize()}, mas `null` quando vazio — o que o banco guarda para "sem telefone".
     */
    public static function normalizeOrNull(?string $phone): ?string
    {
        $normalized = self::normalize($phone);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Máscara nacional. 10 dígitos: `(11) 3333-4444`. 11: `(11) 99999-9999`.
     * Vazio devolve `''`. Outro comprimento devolve os dígitos canônicos.
     */
    public static function format(?string $phone): string
    {
        $digits = self::normalize($phone);
        $length = strlen($digits);

        if ($length === 11) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2, 5).'-'.substr($digits, 7, 4);
        }

        if ($length === 10) {
            return '('.substr($digits, 0, 2).') '.substr($digits, 2, 4).'-'.substr($digits, 6, 4);
        }

        return $digits;
    }

    /**
     * O que o campo mostra ao reabrir. Válido: {@see format()}. Vazio fica vazio.
     * Texto que não é telefone fica como está: {@see format()} sem dígito devolve
     * `''` e apagaria o que já estava gravado.
     */
    public static function display(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }

        if ($phone === '') {
            return '';
        }

        return self::isValid($phone) ? self::format($phone) : $phone;
    }

    /**
     * Telefone brasileiro com DDD: 10 (fixo) ou 11 (celular), DDD sem zero.
     * `+` só se for `+55`.
     */
    public static function isValid(?string $phone): bool
    {
        if ($phone === null || $phone === '' || preg_match(self::ALLOWED_CHARACTERS, $phone) !== 1) {
            return false;
        }

        $national = self::normalize($phone);
        $length = strlen($national);

        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return false;
        }

        if ($national[0] === '0' || $national[1] === '0') {
            return false;
        }

        if (str_contains($phone, '+')) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';

            return $digits === '55'.$national;
        }

        return true;
    }
}
