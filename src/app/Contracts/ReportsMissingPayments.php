<?php

namespace App\Contracts;

use App\Models\Tenant;

/**
 * Capacidade OPCIONAL de um gateway: dizer se um pagamento DEIXOU de existir para as credenciais
 * atuais do tenant (resposta definitiva "não encontrado"), diferente de "não consegui consultar"
 * (rede, 5xx, credencial inválida), que nunca é definitivo.
 *
 * Usado só pela expiração do Pix: um Pix vencido cujo pagamento não existe mais (ex.: o tenant
 * trocou as credenciais de teste pelas de produção) nunca vai ser pago por aqui, e sem isto o
 * pedido e o estoque ficariam presos para sempre.
 */
interface ReportsMissingPayments
{
    public function paymentIsMissing(Tenant $tenant, string $paymentId): bool;
}
