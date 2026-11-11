# Gateways

Um provedor online atrás de `PaymentGatewayInterface`. Quem cobra e quem aplica status não é esta pasta: é `PaymentService` e `PaymentWebhookProcessor` (`src/app/Services/CLAUDE.md`). Gateway não persiste pedido nem pagamento.

## Resolução

`PaymentGatewayManager` é singleton. A chave é o provedor (`PaymentMethod::provider()`), não o método. Hoje só `mercadopago` está registrado. `manual` não tem gateway; `forMethod()` lança `UnsupportedPaymentMethodException`.

Provedor de um método, para um tenant:

1. `settings.payment.methods[]` com o mesmo `id`, se `provider` for string não vazia e diferente de `manual`;
2. senão `PaymentMethod::provider()`.

Provedor sem `extend()` não resolve. Configuração do tenant não instancia classe arbitrária. Gateway novo: `extend('chave', fn () => ...)` num provider. Teste registra `FakeGateway` pelo mesmo `extend()`. A rota `POST /webhooks/{gateway}` usa `forProvider()`.

`handleWebhook()` padrão está na trait `ProcessesWebhooks`: delega ao processador.

## Mercado Pago

`MercadoPagoGateway` fala com a API de Orders (`https://api.mercadopago.com/v1/orders`), Checkout Transparente. O id guardado em `payments.gateway_payment_id` é o da order (`ORD` + alfanumérico; sandbox `ORDTST…`). Id numérico é da API antiga de Payments e `isLookupableId()` o recusa: não há consulta nem cancelamento. O id da transação (`PAY…`) fica em `meta`.

`POST /v1/orders` não aceita `metadata` nem `notification_url`. O vínculo com o pedido é `external_reference` (UUID do pedido, 36 caracteres). A URL de notificação é a cadastrada no painel. O admin mostra `WebhookUrl::for` (`POST /webhooks/mercadopago/{tenantId}`) só quando `APP_URL` é https público; senão não mostra. Ver `src/app/Livewire/Admin/Tenant/CLAUDE.md`.

Corpo da order é montado no gateway, não aceito do navegador. Pix: `payment_method` `{id: pix, type: bank_transfer}` mais `expiration_time` (`PixExpiration`). Cartão: `token` e bandeira vêm do Brick; `type` (`credit_card` ou `debit_card`) vem do `PaymentMethod` escolhido no checkout. Sem token não cobra. Parcelas fixas em 1. Dados de cartão não passam pelo servidor nem são gravados. `checkoutPayload()` manda a public key e o valor em reais; nunca o access token.

HTTP 402 com a order em `data` e status `failed` é cobrança recusada, não erro de requisição. Pix pode nascer `processing/in_process` sem QR; o gateway espera o QR em passos de 1–2 s (`QR_WAIT_STEPS`) antes de devolver.

Tradução de status: `MercadoPago/OrderStatusMapper`. Aprovado só se order e transação dizem aprovado. Contradição (transação aprovada e order recusada) fica `in_process` com `note = conflict`: não paga e não cancela. Valor fora da tabela vira `in_process` com `note = unknown`: nunca paga sozinho. O bruto fica em `meta`. `toPaymentStatus()` é que projeta isso no pedido.

Cancelar: `POST /v1/orders/{id}/cancel`, sem corpo, `X-Idempotency-Key` estável (`pix-expiry:{paymentId}`, cortada em 128). A documentação só cancela `created` e `action_required`. `order_already_canceled` = já estava. `cannot_cancel_order` = o estado mudou; quem chama reconsulta. Rede, 5xx, 401/403 e o resto = indisponível, e o pedido local não muda. 404 com `errors[].code = order_not_found` é `paymentIsMissing()`. Outro 404 não é.

Webhook de Orders: `type` `order` ou `orders`, `data.id` consultável. Outro tópico ou id antigo é ignorado (HTTP 200). A assinatura usa o `data.id` da query (`data_id`); se o corpo divergir, rejeita. Manifesto `id:{data.id};request-id:{x-request-id};ts:{ts};`, HMAC-SHA256. Id alfanumérico é aceito minúsculo e literal. Sem `webhook_secret`, falha fechado fora de `local`/`testing`.

## Credenciais do tenant

Em `settings.payment.mercadopago`:

| Chave | Uso |
|---|---|
| `public_key` | Brick e `isConfigured()` |
| `access_token` | API, cifrado (`EncryptionHelper`) |
| `webhook_secret` | assinatura, cifrado |

`isConfigured()` exige public key e access token decifrável. No Livewire `Admin/Tenant/PaymentSettings` os dois segredos são write-only: não há propriedade pública com o valor. Não os coloque em log, payload de checkout nem resposta de webhook.

## Como Usar

- O `mercadopago` é registrado no construtor de `PaymentGatewayManager`. Gateway novo implementa `PaymentGatewayInterface` e entra por `extend('chave', fn () => ...)` num provider, como o docblock do manager descreve. `settings` não instancia classe.
- Esta pasta não grava `Order` nem `Payment`. Quem persiste é `PaymentService`.
- O corpo da order é montado aqui. Total, itens e access token não vêm do JSON do navegador.
- HTTP segue o client já existente: `Http::timeout(30)`, TLS ligado, token da loja. Não use `verify => false`. Não logue token nem secret.
- Status fora da tabela vira `in_process` (`note = unknown`). Nunca vira pago sozinho.
- Teste usa `FakeGateway` via `extend()`. Não coloque `if (testing)` dentro do gateway de produção.
- Cancelar no provedor é opt-in (`CancelsPayments`). Método que não cancela não ganha implementação vazia que finge sucesso.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Gateways/PaymentGatewayManager.php` — `extend`, `forMethod`, `forProvider`.
> - `src/app/Gateways/MercadoPagoGateway.php` — Orders API, timeout, corpo e o 402 de recusa.
> - `src/app/Gateways/MercadoPago/OrderStatusMapper.php` — approved só com os dois lados, conflito fica `in_process`.
> - `src/tests/Support/Gateways/FakeGateway.php` — o dobro que a suíte registra.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `gateways-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
