<?php

namespace App\Contracts;

/**
 * Capacidade OPCIONAL de um gateway: dizer se um id de pagamento gravado é consultável.
 *
 * Existe para a migração do Mercado Pago da API de Payments (ids numéricos) para a de Orders
 * (ids `ORD...`): um `payments.gateway_payment_id` antigo não pode ser consultado na API nova
 * (ela responde 400), então o reconciliador o ignora em vez de consultar e registrar falha a
 * cada polling. Gateways que não implementam isto são tratados como "todo id é consultável".
 */
interface ValidatesPaymentIds
{
    public function isLookupableId(string $paymentId): bool;
}
