# Services

Cobre o fechamento do pedido (`CartService`, `OrderService`, `FulfillmentService`, `FulfillmentQuote`), o pagamento online (`PaymentService`, `PaymentWebhookProcessor`, `PaymentReconciler`, `PixExpiryEnforcer`) e o login do cliente (`CustomerAuthService`). O Mercado Pago em si está em `src/app/Gateways/CLAUDE.md`. A vitrine que chama o carrinho está em `src/app/Livewire/Tenant/CLAUDE.md`.

Livewire e controller chamam estes serviços. Preço, taxa, mínimo e estoque não se decidem em propriedade pública de componente: o cliente edita esse estado.

## Carrinho

`CartService` é a única leitura e escrita do carrinho (ARC-008).

A sessão guarda só `cart.{tenant_id}`: lista de `{product_id, qty}`. Preço, nome e estoque vêm do banco em `resolveLines()`. Entram produto da loja, ativo e não excluído. O resto é descartado, sem preço.

`sanitize()` exige `product_id` UUID e `qty` >= 1. `count()` conta linhas válidas, não a soma das quantidades. `decrement()` para em 1; sair da linha é `remove()`. `clear()` apaga a chave da sessão.

`add` / `increment` recusam passar de `availableQuantity()` quando `track_inventory` está ligado (`CartStockLimitException`). Sem controle, não há teto. Essa checagem é da sessão, sem lock. A venda é o `OrderService`.

`totalCents()` hoje é o subtotal. Frete e desconto não entram no carrinho. Quem for cobrar soma pela cotação, no pedido.

## Cotação (`tenants.settings`)

`FulfillmentService` lê o JSON. Sem chave, a loja só retira, sem taxa e sem mínimo. Valores negativos viram 0. O teto de 100 bairros (`MAX_REGIONS`) é aplicado no Livewire `Admin/Tenant/DeliverySettings`, não dentro de `quote()`.

| Chave | Padrão | Efeito |
|---|---|---|
| `pickup.enabled` | `true` | retirada disponível |
| `pickup.fee_cents` | `0` | taxa de retirada |
| `delivery.enabled` | `false` | entrega disponível |
| `delivery.minimum_order_cents` | `0` | mínimo sobre o subtotal, nos dois tipos |
| `delivery.default_fee_cents` | `0` | taxa de entrega quando não há bairro ativo |
| `delivery.free_delivery_above_cents` | `0` | `0` desliga; senão zera a taxa de entrega |
| `delivery.estimated_time_minutes` | ausente | só na cotação de entrega, se > 0 |
| `delivery.regions[]` | `[]` | `id`, `name`, `fee_cents`, `active` (padrão `true`) |

Os dois desligados caem em retirada. O tipo padrão é o primeiro de `availableTypes()` (retirada, se existir). Tipo vazio usa o padrão. Tipo desconhecido ou desligado lança `FulfillmentException` no campo `fulfillment_type`.

`quote($tenant, $subtotalCents, $type, $regionId)` devolve um `FulfillmentQuote` imutável. `fee_kind`:

| Situação | `fee_kind` |
|---|---|
| retirada sem taxa | `none` |
| retirada com taxa | `pickup` |
| entrega com bairro ativo escolhido | `region` |
| entrega sem bairro ativo | `default` |
| subtotal atingiu o frete grátis | `free` (taxa 0; bairro escolhido continua no snapshot) |

Com bairro ativo, `region_id` fora da lista lança no campo `region_id`. Região sem `id` string ou sem nome é ignorada. A lista volta em ordem alfabética do nome.

O mínimo incide no subtotal, antes da taxa, para retirada e para entrega. A chave mora debaixo de `delivery` mesmo quando o pedido é retirada. `meetsMinimum()` é `shortfallCents === 0`. `totalCents($subtotal)` soma a taxa. O snapshot gravado em `orders.meta.fulfillment` é `snapshot()`.

Endereço só na entrega, por `validateAddress()`. Rua 3–160, complemento até 160, cidade obrigatória até 100, UF com 2 letras. CEP vazio passa; se vier, são 8 dígitos e grava `00000-000`. Erros voltam todos de uma vez, na chave da coluna (`address_line1`, …).

## Gravar o pedido

`OrderService::createFromCart` é uma transação. Qualquer exceção desfaz cliente, estoque, número e pedido.

Ordem: resolver linhas, cotar, mínimo, endereço, aí sim cliente, baixa de estoque, `Order::create`, itens. Carrinho vazio (`EmptyCartException`) e cotação inválida saem antes de escrever.

O que o cliente manda em `$fulfillment` é só `type`, `region_id` e `address`. Taxa e total são os da cotação. `discount_cents` nasce 0. A taxa, inclusive a de retirada, vai em `delivery_fee_cents`. Nasce `pending` / `unpaid`. `payment_method` é a string recebida; este serviço não valida o método. `meta.payment` guarda `$paymentMeta`.

Cliente (E3.2b): telefone válido acha ou cria por `Customer::firstOrCreateByPhone`. Cliente já existente só ganha nome ou e-mail se o campo estava vazio. O checkout não reescreve cadastro. O pedido guarda o nome e o telefone informados (`customer_name`, `customer_phone`), que podem divergir do cadastro. Telefone vazio ou inválido cria um cliente novo com `phone` null e não agrupa com outro.

Estoque em `reserveStock`, dentro da transação:

- soma a quantidade do mesmo produto antes de validar;
- `SELECT … FOR UPDATE ORDER BY id` nos que têm `track_inventory`;
- lê o saldo da linha travada, não o model do carrinho;
- some entre a leitura e o lock vira `InsufficientStockException` (available 0);
- `track_inventory` desligado no meio do caminho deixa de baixar;
- produto sem controle não entra no lock;
- `stock_deducted_quantity` do item é a qty da linha só para quem entrou na baixa.

`updateOrder` trava a linha do pedido. Entrar em `cancelled` devolve o estoque uma vez (`stock_released_at`). Sair de `cancelled` baixa de novo e, sem saldo, lança e não muda o pedido. Cancelar de novo não mexe no estoque. Devolução e rebaixa ignoram produto excluído ou que já não controla estoque. Na devolução a marca `stock_released_at` fica mesmo assim.

## Pagamento online

Método manual (`PaymentMethod::isManual()`) não passa por gateway. `PaymentGatewayManager::forMethod()` lança para ele. Online: `begin()` grava o pedido e a tentativa; `charge()` chama o gateway fora de transação e depois consolida. Nenhuma transação de banco fica aberta durante HTTP.

`checkoutKey` é o SHA-256 (40 hex) de tenant, nonce da sessão, linhas ordenadas por `product_id` e, se houver, tipo, bairro e endereço. Método e dados do cliente ficam de fora: trocar Pix por cartão é o mesmo checkout. Outro endereço é outra chave. A primeira tentativa usa a chave pura; cada recusa seguinte ganha sufixo `:n` (`checkoutKey:2`).

`begin()` reencontra tentativa não falha do mesmo par (tenant, gateway, chave) e devolve `PaymentAttempt` com `duplicate = true`, sem criar pedido nem cobrar. A tentativa nasce `created`, `amount_cents` = `order.total_cents`, `idempotency_key` persistida. Unique da chave: a corrida reverte a transação inteira (estoque junto) e devolve quem venceu.

`charge()` não lança por falha do gateway. Repassa `idempotency_key` em `$data`.

| `ChargeOutcome` | O que aconteceu |
|---|---|
| `settled` | gateway aceitou (pago ou pendente) e o banco gravou |
| `failed` | recusa, timeout ou exceção: payment `failed`/`rejected`, pedido `cancelled`, estoque devolvido uma vez |
| `unconsolidated` | o gateway aceitou e a gravação falhou. Log `payment.incident.charge_not_consolidated`. Não cancela: o webhook adota a tentativa |

Se, ao compensar, o pedido já está `paid` (webhook no meio do timeout), não cancela. Log de falha de compensação: `payment.incident.compensation_failed`.

Consolidar Pix online ainda não pago grava `payments.meta.pix_expires_at` (`PixWindow`), se ainda não houver. Não sobrescreve status que o webhook já tirou de `created`. `gateway_payment_id` só é preenchido se estava vazio.

## Transição (webhook e consulta)

`PaymentWebhookProcessor` é o único lugar que muda status a partir do gateway. `parseWebhook()` não devolve status. O status vem de `getPayment()` com o token do tenant, antes da transação.

Lock sempre pedido e depois pagamento. A mesma ordem de `PaymentService` e de `OrderService::updateOrder`.

Cada par `(gateway, gateway_payment_id, status)` entra uma vez em `payment_webhook_events` (`insertOrIgnore`). Inserir zero linhas é no-op. Reenvio com o mesmo `x-request-id` também é no-op, antes da API. Consulta atrasada que ainda diz "em aberto" não reabre pagamento já decidido (`PaymentReconciler::openStatuses()`: `created`, `pending`, `in_process`, `authorized`).

Efeito no pedido, via `GatewayPaymentStatus::toPaymentStatus()`:

| Status do gateway | `orders.payment_status` |
|---|---|
| `approved` | `paid` |
| `pending`, `in_process` | `unpaid` |
| `refunded`, `charged_back` | `refunded` |
| o resto (`authorized`, `rejected`, `cancelled`, `created`, `failed`, desconhecido) | não altera |

`rejected` ou `cancelled` com pedido `pending` e pagamento `unpaid` cancela o pedido e devolve o estoque (`updateOrder`). Dinheiro em pedido já `cancelled` não reabre: log `payment.incident.paid_on_cancelled_order`.

Pagamento avisado sem id gravado adota a tentativa mais recente do pedido com `gateway_payment_id` null. Sem tentativa, cria uma com `meta.origin = webhook`. Metadata `tenant_id` / `order_id` do resultado, se vier, tem de bater com o pedido; senão a transição é ignorada.

Rota com tenant (`POST /webhooks/{gateway}/{tenantId}`) autentica o secret desse tenant antes de consultar API ou pedido. Tenant inexistente ou assinatura ruim: 401. Gateway desconhecido: 404. Definitivo (processado, reenvio, ignorado): 200. Falha transitória (`WebhookResult::retry`): 503. A rota sem tenant é legado: uma leitura `Payment::withoutTenantScope()` por `(gateway, gateway_payment_id)`, assina com o secret dele e processa dentro de `TenantContext::runFor`. Sem tenant para autenticar, 401, salvo `local`/`testing` (`WebhookSecurity`).

`PaymentReconciler` é a mesma transição, por consulta. Throttle de 3 s por pagamento (`Cache::add`); o comando passa `throttle: false`. Erro de rede devolve null e não propaga. `payments:reconcile --pix-only` roda todo minuto (`routes/console.php`, `withoutOverlapping(10)`). Sem `--pix-only`, o resto em aberto só entra se foi criado há pelo menos `--minutes` (padrão 10).

## Pix: dois prazos

`PixExpiration` (`services.mercadopago.pix_expiration`, padrão `PT30M`, faixa 30 min–30 dias) é o `expiration_time` enviado ao Mercado Pago. Abaixo ou acima cai em `PT30M`.

`PixWindow` (`services.mercadopago.pix_window`, padrão e teto 300 s, piso 60 s) é o prazo nosso. Mais `GRACE_SECONDS` (10) antes de cancelar. Sem `pix_expires_at`, um Pix online vence `janela + tolerância` depois de `payments.created_at`. Cartão nunca vence por esta regra.

`PixExpiryEnforcer`, chamado pelo reconciler quando o Pix aberto está vencido: consulta, aplica, e se ainda `pending` cancela no gateway (`CancelsPayments`) e reconsulta. O pedido local só muda quando o gateway mostra o estado. Cancelamento com rede/5xx não mexe no pedido. Pago não é cancelado. Order 404 `order_not_found` vira `cancelled` local. `in_process` sem QR nenhum é cancelado localmente (`pix_expired_without_qr`); se já houve QR, espera o gateway. Aprovação tardia cai no incidente de pago sobre cancelado.

## Login do cliente

`CustomerAuthService` é o único emissor da sessão. Decisão D3: o fator é o telefone, sem prova de posse. O guard `customer` (`CustomerSessionGuard`) só valida o cookie; `validate()` devolve falso.

`login($tenant, $phone)` normaliza com `Phone::normalizeOrNull`. Inválido lança `InvalidArgumentException`. `firstOrCreateByPhone` acha ou cria (nome e e-mail vazios, `is_active` true). Grava `session_token` novo (`hash('sha256', Str::random(60).time())`) e `last_login_at`. Um segundo login invalida o cookie anterior. Não consulta `is_active`: cliente desativado ganha token novo e o guard recusa na hora.

Cookie `customer_session_{tenantId}`, 90 dias, path `/`, httpOnly, SameSite `lax`. `Secure` segue `session.secure`; se for null, liga em produção ou em request já https. `logout($tenant, $token)` zera `session_token` daquela loja. Não há tela de sair: a revogação é esse método. Cookie de dispensar o popup: `login_popup_dismissed_{tenantId}`, 1 dia, mesmos atributos.

`validateSessionToken` exige token, loja e `is_active`. O guard lê o cookie do request real e o tenant do `TenantContext`, não do snapshot. Memo só dentro do mesmo request, tenant e token. `forgetUser()` depois de login ou logout.

## Como Usar

- Tela chama serviço. Preço, taxa, mínimo e estoque não se recalculam no Livewire nem no controller.
- Pedido novo: `OrderService::createFromCart`. Mudança de status: `updateOrder`. Cancelar por `save()` no model pula a devolução de estoque.
- Pagamento online: `begin()` na transação curta, HTTP dentro de `charge()`, consolidação numa transação nova. Não envolva o HTTP numa transação aberta.
- Quem muda status a partir do gateway é `PaymentWebhookProcessor`. Não leia status do corpo do webhook.
- Carrinho só por `CartService`. A sessão guarda `product_id` e `qty`.
- Taxa e mínimo só por `FulfillmentService::quote`. O request não traz o valor.
- Cookie de cliente só por `CustomerAuthService`. O guard valida; não emite.
- O toast de pausa diz que os pedidos pararam. `TenantHelper::isOpen()` não recusa pedido. Não faça este serviço recusar checkout para combinar com o toast: isso é dívida de texto, não regra de venda.
- Comando `payments:reconcile` é a casca do `PaymentReconciler`. A regra nova entra no serviço, não no comando.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Services/OrderService.php:createFromCart` — ordem da transação e `reserveStock`.
> - `src/app/Services/PaymentService.php:begin` e `:charge` — tentativa, HTTP e `ChargeOutcome`.
> - `src/app/Services/PaymentWebhookProcessor.php:process` — consulta antes da transação, lock pedido-depois-pagamento.
> - `src/app/Services/FulfillmentService.php:quote` — chaves de `settings` e o mínimo que vale para os dois tipos.
> - `src/app/Services/CartService.php` — sessão `cart.{tenant_id}`.
> - `src/app/Services/CustomerAuthService.php` — emissão do cookie.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `services-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
