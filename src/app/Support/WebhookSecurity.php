<?php

namespace App\Support;

/**
 * Política de autenticação de webhooks (E3.3).
 *
 * Webhook sem secret para validar FALHA FECHADO, exceto em `local` e `testing`, onde
 * não há como configurar o gateway de verdade. Uma única definição, usada pelo gateway
 * (secret ausente) e pelo controller (tenant não resolvido na rota sem tenant).
 */
final class WebhookSecurity
{
    public static function secretlessBypassAllowed(): bool
    {
        return app()->environment('local', 'testing');
    }
}
