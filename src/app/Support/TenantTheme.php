<?php

namespace App\Support;

/**
 * Fonte ÚNICA do que o tema do tenant pode colocar em CSS (E5.1 / UI-007).
 *
 * `{{ }}` do Blade escapa HTML, não contexto CSS: um valor como
 * `"a"; } body { background: url(https://evil/?x) } /*` fecha a regra e injeta
 * declarações. Por isso a VIEW não interpola o que veio do banco/estado do cliente:
 * passa por aqui, que só devolve
 *
 *  - uma *font stack* que é CHAVE exata da allow-list {@see self::FONTS}, ou o padrão;
 *  - uma cor hex válida (#rgb / #rrggbb), ou o fallback.
 *
 * Qualquer outra coisa (inclusive "quase válida") cai no padrão — nunca é "limpa".
 * A mesma lista alimenta a validação de escrita (`Customization`), o seletor da UI e
 * o carregamento do Google Fonts, então não há três listas para divergir.
 */
final class TenantTheme
{
    public const DEFAULT_PRIMARY = '#0d6efd';

    public const DEFAULT_SECONDARY = '#6c757d';

    private const SYSTEM_STACK = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

    public const DEFAULT_FONT = self::SYSTEM_STACK;

    /**
     * font stack (valor gravado e emitido em CSS) => rótulo e URL do Google Fonts.
     * Para suportar uma fonte nova, acrescente UMA entrada aqui.
     *
     * @var array<string, array{label: string, google: string|null}>
     */
    public const FONTS = [
        self::SYSTEM_STACK => [
            'label' => 'System (padrão)',
            'google' => null,
        ],
        'Inter, '.self::SYSTEM_STACK => [
            'label' => 'Inter',
            'google' => 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        ],
        'Poppins, '.self::SYSTEM_STACK => [
            'label' => 'Poppins',
            'google' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap',
        ],
        'Montserrat, '.self::SYSTEM_STACK => [
            'label' => 'Montserrat',
            'google' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap',
        ],
        'Roboto, '.self::SYSTEM_STACK => [
            'label' => 'Roboto',
            'google' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap',
        ],
    ];

    /** @return list<string> */
    public static function fontStacks(): array
    {
        return array_keys(self::FONTS);
    }

    /** @return array<string, string> stack => rótulo (para o `<select>`) */
    public static function fontOptions(): array
    {
        return array_map(fn (array $font) => $font['label'], self::FONTS);
    }

    /** Stack da allow-list ou o padrão. Comparação estrita: sem normalização. */
    public static function fontStack(mixed $value): string
    {
        return is_string($value) && array_key_exists($value, self::FONTS)
            ? $value
            : self::DEFAULT_FONT;
    }

    /** URL do Google Fonts da stack JÁ validada (nunca derivada de valor cru). */
    public static function googleFontUrl(mixed $value): ?string
    {
        return self::FONTS[self::fontStack($value)]['google'];
    }

    public static function isHexColor(mixed $value): bool
    {
        // `\z` (não `$`): `$` aceitaria "#fff\n".
        return is_string($value) && preg_match('/\A#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})\z/', $value) === 1;
    }

    public static function color(mixed $value, string $fallback): string
    {
        return self::isHexColor($value) ? $value : $fallback;
    }

    public static function primary(mixed $value): string
    {
        return self::color($value, self::DEFAULT_PRIMARY);
    }

    public static function secondary(mixed $value): string
    {
        return self::color($value, self::DEFAULT_SECONDARY);
    }
}
