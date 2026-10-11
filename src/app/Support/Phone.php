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
 * Validade ({@see isValid()}): 10 a 15 dígitos após a normalização (10–11 para
 * números nacionais, até 15 = limite E.164 para estrangeiros).
 */
final class Phone
{
    public const MIN_DIGITS = 10;

    public const MAX_DIGITS = 15;

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
     * Só caracteres de telefone e entre 10 e 15 dígitos após normalizar.
     */
    public static function isValid(?string $phone): bool
    {
        if ($phone === null || $phone === '' || preg_match(self::ALLOWED_CHARACTERS, $phone) !== 1) {
            return false;
        }

        $length = strlen(self::normalize($phone));

        return $length >= self::MIN_DIGITS && $length <= self::MAX_DIGITS;
    }
}
