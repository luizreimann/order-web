<?php

namespace App\Contracts;

use App\Models\Tenant;

/**
 * Capacidade OPCIONAL de um gateway: cancelar, no provedor, um pagamento ainda não pago.
 *
 * Existe para a janela de pagamento do Pix ({@see \App\Services\PixExpiryEnforcer}): o provedor
 * (Mercado Pago) não aceita validade curta, então o NOSSO sistema cancela a cobrança quando a
 * janela fecha. Gateways que não implementam isto não têm a janela imposta (o pedido segue o
 * prazo do próprio provedor). Segue o padrão de {@see ValidatesPaymentIds}.
 */
interface CancelsPayments
{
    /**
     * Pede o cancelamento. NUNCA lança por falha do provedor: o resultado é tipado.
     *
     * @param  string  $idempotencyKey  estável por pagamento: repetir a chamada é seguro
     */
    public function cancelPayment(Tenant $tenant, string $paymentId, string $idempotencyKey): PaymentCancellation;
}
