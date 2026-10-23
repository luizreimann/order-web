<?php

namespace App\Support;

/**
 * Device ID do Mercado Pago (antifraude): o `security.js` da página de checkout expõe
 * `window.MP_DEVICE_SESSION_ID` e o servidor o repassa no cabeçalho `X-meli-session-id`.
 *
 * Vem do NAVEGADOR (não confiável) e vai parar num cabeçalho HTTP de saída, então só passa
 * o que tem cara de identificador: letras, dígitos, `_`, `-` e `.`, de 8 a 512 caracteres.
 * O ponto é necessário: os valores reais têm a forma `armor.<hex>.<hex>` (~250 caracteres);
 * sem ele TODO Device ID legítimo seria descartado. `\z` (e não `$`) impede o `\n` final;
 * CR/LF, espaços e qualquer outro caractere descartam o valor INTEIRO (nunca "limpamos").
 *
 * Inválido ou ausente => null: o pagamento segue sem o Device ID (ele só melhora a aprovação;
 * nunca pode bloquear a cobrança).
 */
final class DeviceSessionId
{
    public const PATTERN = '/\A[A-Za-z0-9._-]{8,512}\z/';

    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        return $value;
    }
}
