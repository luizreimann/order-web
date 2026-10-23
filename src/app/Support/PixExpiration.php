<?php

namespace App\Support;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validade do Pix: `services.mercadopago.pix_expiration` (duração ISO 8601, padrão `PT30M`).
 *
 * A documentação do Mercado Pago (Orders) pede de 30 minutos a 30 dias para
 * `transactions.payments[].expiration_time`. A SANDBOX aceitou `PT10M`, `PT29M` e `P31D`,
 * mas a produção segue a documentação, então o que não for uma duração válida nessa faixa
 * cai em `PT30M` (e avisa uma vez no log) em vez de derrubar o checkout.
 *
 * Uma única fonte para o corpo da order, o polling/mensagem do front e a janela em que a tela
 * de espera do Pix é restaurada ao recarregar a página.
 */
final class PixExpiration
{
    public const DEFAULT = 'PT30M';

    public const MIN_SECONDS = 1800;

    public const MAX_SECONDS = 2592000;

    private static bool $noticed = false;

    public static function iso(): string
    {
        $configured = config('services.mercadopago.pix_expiration');
        $value = is_string($configured) ? trim($configured) : '';

        $seconds = self::durationSeconds($value);

        if ($seconds !== null && $seconds >= self::MIN_SECONDS && $seconds <= self::MAX_SECONDS) {
            return $value;
        }

        if (! self::$noticed) {
            self::$noticed = true;
            Log::warning('MERCADOPAGO_PIX_EXPIRATION inválida (use uma duração ISO 8601 entre PT30M e P30D); usando '.self::DEFAULT.'.');
        }

        return self::DEFAULT;
    }

    public static function seconds(): int
    {
        return (int) self::durationSeconds(self::iso());
    }

    /** Só para os testes: o aviso de configuração inválida sai uma vez por processo. */
    public static function resetNotice(): void
    {
        self::$noticed = false;
    }

    /** Segundos de uma duração ISO 8601 (`PT30M`, `P1DT2H`...); null se não for uma. */
    public static function durationSeconds(string $value): ?int
    {
        // `P` + ao menos um componente; `T` só com hora/minuto/segundo depois.
        if (preg_match('/\AP(?=\d|T\d)(?:\d+Y)?(?:\d+M)?(?:\d+W)?(?:\d+D)?(?:T(?=\d)(?:\d+H)?(?:\d+M)?(?:\d+S)?)?\z/', $value) !== 1) {
            return null;
        }

        try {
            return (int) CarbonInterval::make($value)->totalSeconds;
        } catch (Throwable) {
            return null;
        }
    }
}
