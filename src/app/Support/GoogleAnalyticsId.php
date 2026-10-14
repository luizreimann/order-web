<?php

namespace App\Support;

/**
 * ID de medição do Google Analytics 4 (E5.2 / UI-008).
 *
 * O valor entra em `src` de um <script> e em JS inline, então só o formato exato
 * `G-XXXXXXXX` é aceito — na escrita (SeoSettings) e na leitura (TenantHelper::getSeoData).
 *
 * Só GA4 (`G-`): `UA-…` é do Universal Analytics, descontinuado em jul/2023 (não coleta
 * mais nada, então aceitá-lo só ampliaria a superfície sem benefício). `GT-…` (Google tag)
 * é válido no gtag.js, mas o produto hoje só oferece GA4; para suportá-lo basta ampliar
 * {@see self::PATTERN} e o teste.
 */
final class GoogleAnalyticsId
{
    /** `\z` e não `$`: `$` aceitaria "G-ABCD\n". */
    public const PATTERN = '/\AG-[A-Z0-9]{4,20}\z/';

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }

    /** O ID se for válido; senão `null` (nada é emitido). */
    public static function valid(mixed $value): ?string
    {
        return self::isValid($value) ? $value : null;
    }
}
