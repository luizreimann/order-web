<?php

namespace App\Http\Controllers;

use App\Gateways\PaymentGatewayManager;
use App\Models\Tenant;
use App\Services\PaymentWebhookProcessor;
use App\Support\WebhookSecurity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Endpoint ÚNICO de webhooks de pagamento (D4/E3.5): `POST /webhooks/{gateway}[/{tenantId}]`.
 * O controller não conhece nenhum provedor: resolve o gateway pelo parâmetro.
 *
 * ## Autenticação ANTES de qualquer consulta (E3.3)
 *
 * A assinatura usa o `webhook_secret` do TENANT. Para descobrir o tenant SEM olhar o
 * payload, a `notification_url` enviada ao criar cada pagamento já carrega o id dele:
 *
 *     /webhooks/{gateway}/{tenantId}
 *
 * Fluxo da rota com tenant:
 *  1. gateway desconhecido → 404 (nada consultado);
 *  2. `Tenant::find(id da URL)` — dado da URL, não do payload; tenant inexistente → 401;
 *  3. `validateWebhook()` com o secret desse tenant (puro: sem banco, sem rede) → 401;
 *  4. só então `handleWebhook()`: consulta à API com o token do tenant, lock e transição.
 * Assinatura inválida nunca toca pedidos/pagamentos nem a API do gateway, exista o pedido
 * ou não.
 *
 * `/webhooks/{gateway}` (sem tenant) é o caminho de compatibilidade: o tenant só pode vir
 * do pagamento já registrado (UMA leitura de `payments.tenant_id`, sem pedido nem API) e a
 * assinatura é validada com o secret dele antes de processar. Se não há tenant para
 * autenticar a notificação, falha fechado (401), salvo local/testing.
 *
 * Códigos: 200 para tudo que é definitivo (processado, reenvio, ignorado — o gateway não
 * deve reenviar), 401 assinatura inválida, 404 gateway desconhecido, 503 só para falha
 * transitória que deva ser reenviada.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly PaymentWebhookProcessor $processor,
    ) {}

    public function handle(Request $request, string $gateway, ?string $tenantId = null): JsonResponse
    {
        if (! $this->gateways->has($gateway)) {
            abort(404);
        }

        $gw = $this->gateways->forProvider($gateway);

        /** @var array<string, mixed> $payload */
        $payload = $request->all();
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }

        if ($tenantId !== null) {
            $tenant = Str::isUuid($tenantId) ? Tenant::query()->find($tenantId) : null;
        } else {
            $event = $gw->parseWebhook($payload, $headers);
            $tenant = $event->paymentId !== null
                ? $this->processor->tenantOfRecordedPayment($gw, $event->paymentId)
                : null;

            if ($tenant === null && WebhookSecurity::secretlessBypassAllowed()) {
                // Sem tenant não há secret: só local/testing aceitam seguir (e não há o que processar).
                return response()->json(['ok' => false, 'transitioned' => false]);
            }
        }

        if ($tenant === null || ! $gw->validateWebhook($tenant, $payload, $headers)) {
            Log::warning('payment.webhook.rejected', [
                'gateway' => $gateway,
                'tenant_id' => $tenant !== null ? $tenant->id : $tenantId,
                'reason' => $tenant === null ? 'tenant_unresolved' : 'invalid_signature',
            ]);

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $result = $gw->handleWebhook($payload, $headers, $tenant);

        if ($result->retryable) {
            return response()->json(['ok' => false, 'retry' => true], 503);
        }

        return response()->json(['ok' => $result->processed, 'transitioned' => $result->transitioned]);
    }
}
