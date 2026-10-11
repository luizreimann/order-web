<?php

namespace App\Contracts;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Contrato de um provedor de pagamento online (D4: múltiplos gateways por tenant).
 *
 * O fluxo de checkout ({@see \App\Services\PaymentService}) e o de webhook
 * ({@see \App\Services\PaymentWebhookProcessor}) só conhecem esta interface. Tudo o que é
 * específico do provedor (API, assinatura, formato do payload, widget do front) fica
 * atrás dela.
 *
 * Vocabulário de status: os gateways devolvem em {@see PaymentResult::$status} os valores
 * de {@see \App\Enums\GatewayPaymentStatus} (pending, approved, rejected, ...). Um gateway
 * cujo provedor usa outros nomes deve traduzi-los na fronteira.
 */
interface PaymentGatewayInterface
{
    /** Chave do provedor: a de `PaymentMethod::provider()` e a de `/webhooks/{gateway}`. */
    public function key(): string;

    /** O tenant tem as credenciais necessárias para cobrar por este gateway? */
    public function isConfigured(Tenant $tenant): bool;

    /**
     * Estado inicial que o front precisa para montar o formulário de pagamento do
     * gateway (chaves públicas, restrições de meio de pagamento, ...). Nunca segredos.
     *
     * @return array<string, mixed>
     */
    public function checkoutPayload(Tenant $tenant, PaymentMethod $method, int $totalCents, string $processPaymentUrl): array;

    /**
     * Cobra o pedido. Faz SÓ a chamada externa: não persiste nada (a tentativa de
     * {@see \App\Models\Payment} já foi registrada pelo chamador, que consolida o
     * resultado). `$data['idempotency_key']` é a chave persistida da tentativa e deve ser
     * repassada ao provedor.
     *
     * @param  array<string, mixed>  $data
     */
    public function createPayment(Tenant $tenant, Order $order, array $data): PaymentResult;

    /** Consulta o pagamento no provedor com as credenciais DO tenant (fonte da verdade). */
    public function getPayment(Tenant $tenant, string $paymentId): ?PaymentResult;

    /**
     * Autentica a notificação com o secret do tenant. Deve ser PURA: sem banco e sem
     * rede. Sem secret configurado, falha fechado fora de local/testing.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  chaves em minúsculas
     */
    public function validateWebhook(Tenant $tenant, array $payload, array $headers): bool;

    /**
     * Interpreta a notificação sem I/O: qual pagamento mudou (e o id da entrega, se o
     * provedor manda um). O STATUS nunca sai daqui: vem de {@see self::getPayment()}.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function parseWebhook(array $payload, array $headers): WebhookEvent;

    /**
     * Processa uma notificação JÁ autenticada. Implementação padrão: delegar a
     * `PaymentWebhookProcessor::process($this, ...)` (idempotência e locks vivem lá).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  Tenant|null  $tenant  tenant já resolvido (e autenticado) pela rota; null = descobrir pelo pagamento
     */
    public function handleWebhook(array $payload, array $headers = [], ?Tenant $tenant = null): WebhookResult;
}
