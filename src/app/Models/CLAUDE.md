# Models

Contrato do que está em `src/app/Models`. Cobrança, carrinho, estoque e checkout ficam em `src/app/Services`. O racional longo do isolamento está no docblock de `Concerns/BelongsToTenant.php`.

## Isolamento

Model de loja implementa `TenantScoped` e usa `BelongsToTenant`. O contexto da execução é `App\Support\TenantContext` (binding scoped; o middleware `ResolveTenantFromPath` chama `set()` no HTTP da loja).

| Contexto | Leitura | Escrita de instância | Preenche `tenant_id` |
|---|---|---|---|
| `set` / `runFor` | filtra `tenant_id` | só a linha desse tenant; senão `CrossTenantWriteException` | sim, se a coluna veio vazia |
| `usePlatformScope` | não filtra | livre | não |
| indefinido (job, seeder, comando, webhook) | não filtra | livre | não |

Três barreiras, e só essas:

1. Leitura pelo `TenantScope` (query builder). Coluna qualificada, para o join dos pivôs não ficar ambíguo.
2. `save` / `update` / `delete` / `restore` / `forceDelete` passam por `assertWritableInCurrentTenant` (a linha atual e a original).
3. Re-hidratação Livewire (`newQueryForRestoration`) respeita o scope e o soft delete.

Fora delas, de propósito: SQL cru (`DB::table`), `withoutTenantScope()` e qualquer código sem tenant no contexto. Cada um desses em `app/` entra na lista fechada de `TenantIsolationAuditTest`. Model novo sem a trait, opt-out novo ou SQL cru novo derrubam o teste.

Opt-out de leitura: `Model::withoutTenantScope()`. `withoutGlobalScopes()` derruba os outros scopes junto.

Isolamento não é autorização. Policies e os middlewares de admin/cliente dizem quem pode escrever.

Não usam a trait:

| Model | Motivo |
|---|---|
| `Tenant` | é a própria loja; `slug` único global (prefixo da URL) |
| `User` | `tenant_id` nullable; `platform_admin` não tem loja. Papel em `App\Enums\Role` (`resolvedRole()` devolve null se o valor for legado) |
| `TenantCounter` | PK composta `(tenant_id, key)`, que o Eloquent não persiste |

`TenantCounter`: nunca `save()`, `update()` nem `delete()` de instância. Isso gera UPDATE sem WHERE e corrompe o contador das outras lojas (BUG-002). Incremento só por `nextValue()`. Outra escrita: `queryFor($tenantId, $key)`.

`Order`, no `creating`, preenche `number` com `TenantCounter::nextValue($tenantId, 'orders')` quando o número veio vazio. Unique `(tenant_id, number)`.

## Identidade

UUID (`HasUuids`) em tudo desta pasta, exceto `User` (id inteiro) e `TenantCounter`.

`Product`, `Category` e `Showcase` usam `GeneratesUniqueSlug`. Slug vazio no `saving` nasce do `name`, único por tenant entre não excluídos (`bolo`, `bolo-2`). Slug informado não é reescrito; colisão estoura a unique. Nome sem caractere de slug cai no nome da classe (`product`, `category`, `showcase`). Duas gravações simultâneas podem colidir; a unique parcial barra a segunda.

## Apagar

Soft delete: `Product`, `Category`, `Customer`, `Showcase`. A unique de slug e a de telefone são parciais (`WHERE deleted_at IS NULL`), então o valor pode ser reusado depois da exclusão.

`Order`, `OrderItem`, `Payment` e `PaymentWebhookEvent` não têm soft delete. Pedido é documento. `Order::customer()` usa `withTrashed()`: o cliente pode sumir e o pedido continua apontando para ele. `order_items.product_id` é `nullOnDelete`; nome, sku e preço do item são snapshot.

## Dinheiro e telefone

Colunas de valor são inteiros em centavos (`*_cents`).

`Product` expõe `price` e `compare_at` em reais por accessor. A coluna é `price_cents` / `compare_at_cents`, e o mutator passa por `Money::toCents`. Valor inválido lança; não vira zero. `price_cents` não está no fillable.

`Customer.phone` grava no formato de `Phone::normalize`. Vazio vira null. Unique parcial `(tenant_id, phone) WHERE deleted_at IS NULL`. Busca e criação: `findByPhone` e `firstOrCreateByPhone` (savepoint: a corrida devolve quem venceu, sem abortar a transação de fora).

## Catálogo

`Product.track_inventory` falso significa sob encomenda: `availableQuantity()` devolve null. Essa leitura é de vitrine, sem lock. A baixa é do `OrderService`.

Pivôs `category_product` e `showcase_product` carregam `tenant_id`. As relações passam por `withTenantPivot`. A FK composta impede a outra ponta ser de outra loja.

Vitrine: no máximo `Showcase::MAX_SHOWCASES_PER_TENANT` (10). Criar por `createWithinLimit` (trava a linha do tenant com `FOR NO KEY UPDATE`). Tipos: `all`, `featured`, `best_sellers`, `recent`, `category`, `custom`. A resolução está em `getProducts()`. O tipo `all` limita com `min(max_items, ALL_TYPE_CEILING)` e o teto é 100. `best_sellers` ignora pedido `cancelled`. Quem grava catálogo, cliente e pedido no admin está em `src/app/Livewire/Admin/CLAUDE.md`.

## Pedido e pagamento

Dois status, mais o do gateway:

| Coluna | Tipo | Valores |
|---|---|---|
| `orders.status` | `OrderStatus` (cast e CHECK) | `pending`, `confirmed`, `done`, `cancelled` |
| `orders.payment_status` | `PaymentStatus` (cast e CHECK) | `unpaid`, `paid`, `refunded` |
| `payments.status` | string bruta | `GatewayPaymentStatus` só interpreta com `tryFrom`. Sem cast e sem CHECK |

`created` e `failed` em `payments.status` são locais (a tentativa é registrada antes da chamada externa). Não vêm do Mercado Pago.

`orders.payment_method` é `PaymentMethod`: manuais `cash_on_delivery`, `card_on_delivery`, `pix_manual`; Mercado Pago `mp_pix`, `mp_credit_card`, `mp_debit_card`.

`orders.fulfillment_type` é `pickup` ou `delivery`. A cotação fica em `orders.meta.fulfillment`. Totais (`subtotal_cents`, `discount_cents`, `delivery_fee_cents`, `total_cents`) são gravados pelo serviço.

`Payment` é único em `(gateway, gateway_payment_id)` e `(gateway, idempotency_key)`. `PaymentWebhookEvent` é único em `(gateway, gateway_payment_id, status)`: uma linha por transição já aplicada.

Estoque no item: `stock_deducted_quantity` e `stock_released_at`. Quem altera é o serviço de pedido.

## Tenant.settings

`settings` é array. Plano e capability futuros não entram nesse JSON (D6 em `.sprint/06-refactor/04-decisions.md`). Retirada e entrega (`pickup.*`, `delivery.*`) estão em `src/app/Services/CLAUDE.md`. Pagamento (`payment.methods`, `payment.mercadopago`) está em `src/app/Gateways/CLAUDE.md`. `business_hours`, `address`, `theme`, `contact`, `social`, `seo` e `store` são gravados em `src/app/Livewire/Admin/Tenant/CLAUDE.md`. A vitrine só lê horário e entrega.

## Model novo de loja

`implements TenantScoped`, `BelongsToTenant`, `tenant_id` uuid. Se outra tabela for apontar com FK composta, unique `(tenant_id, id)`. `scopeForTenant` qualifica a coluna. A suíte de isolamento tem de continuar passando.

## Como Usar

- Model de loja novo segue o parágrafo acima. `Tenant`, `User` e `TenantCounter` continuam fora da trait.
- `TenantCounter`: só `nextValue()` e `queryFor()`. `save()` / `update()` / `delete()` de instância é o BUG-002, não um atalho.
- Opt-out de leitura: `withoutTenantScope()`, justificado, e na lista de `TenantIsolationAuditTest`. `withoutGlobalScopes()` não serve.
- Coluna de valor é `*_cents` inteiro. Reais só num accessor que passa por `Money::toCents`. `price_cents` fica fora do `$fillable`.
- Soft delete só onde a tabela já tem `deleted_at` (`Product`, `Category`, `Customer`, `Showcase`). Pedido, item, pagamento e evento de webhook são documento: não ganham `SoftDeletes`.
- Unique de slug e de telefone, com soft delete, é parcial (`WHERE deleted_at IS NULL`).
- Copie o vizinho da mesma ideia: `Product` para catálogo, `Order` para documento, `Payment` para tentativa de gateway.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Models/Concerns/BelongsToTenant.php` — o racional do scope, da escrita e de quem fica de fora.
> - `src/app/Models/Scopes/TenantScope.php:apply` — filtro de leitura.
> - `src/app/Models/TenantCounter.php:nextValue` — o incremento que não usa `save()`.
> - `src/app/Models/Product.php` — centavos, slug e soft delete.
> - `src/app/Models/Order.php` — número, os dois status do pedido, `customer()` com `withTrashed()`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `models-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
