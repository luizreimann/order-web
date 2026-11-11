# Contracts

Fronteira de um gateway. Quem cobra é `PaymentService`. Quem aplica status é `PaymentWebhookProcessor`. Os dois só conhecem estas interfaces. Detalhe do Mercado Pago: `src/app/Gateways/CLAUDE.md`.

`PaymentGatewayInterface`: `createPayment` não persiste. `validateWebhook` é pura (sem banco, sem rede) e falha fechado sem secret. `parseWebhook` não devolve status. `getPayment` consulta com o token da loja. `handleWebhook` delega ao processador (`ProcessesWebhooks`).

`PaymentResult::$status` usa os valores de `GatewayPaymentStatus`. Provedor com outro vocabulário traduz aqui. `success` com status recusado é recusa, não erro de transporte.

`WebhookResult`: `processed` mudou estado; `unchanged` é reenvio; `ignored` e `failed` são definitivos (HTTP 200); `retry` pede 503.

`CancelsPayments`, `ReportsMissingPayments` e `ValidatesPaymentIds` são opt-in. O Pix vencido só cancela no gateway se a implementação for `CancelsPayments`.

## Como Usar

- Comportamento novo da fronteira entra na interface. `PaymentService` e `PaymentWebhookProcessor` não ganham `instanceof MercadoPagoGateway`.
- `createPayment` não grava. `validateWebhook` não toca banco nem rede e falha fechado sem secret. `parseWebhook` não devolve status.
- Vocabulário do provedor traduz para `GatewayPaymentStatus` no `PaymentResult`. `success` com status recusado é recusa, não erro de transporte.
- Capacidade que nem todo gateway tem é interface à parte. Quem não cancela não implementa `CancelsPayments`.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Contracts/PaymentGatewayInterface.php` — o que todo gateway implementa.
> - `src/app/Contracts/PaymentResult.php` — status e o que `success` significa.
> - `src/app/Contracts/WebhookResult.php` — `processed`, `unchanged`, `ignored`, `failed`, `retry`.
> - `src/app/Contracts/CancelsPayments.php` — o opt-in que o Pix vencido exige.
> - `src/app/Gateways/Concerns/ProcessesWebhooks.php` — `handleWebhook` delega ao processador.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `contracts-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
