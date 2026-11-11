# Livewire da loja

Checkout, vitrine, carrinho e conta do cliente. O HTTP do pagamento online continua em `src/app/Http/Controllers/CLAUDE.md`. Login e cookie estão em `CustomerAuthService` (`src/app/Services/CLAUDE.md`) e nos middlewares `tenant`, `tenant.customer`, `tenant.customer.auth`.

`Checkout` não grava pagamento online. `payOnline()` valida e dispara `checkout-mp-pay`. O script em `resources/views/livewire/tenant/checkout.blade.php` escuta esse evento e faz `POST /{tenant}/process_payment` (`TenantPaymentController`). Pix segue sem Brick (`flow: direct`, `formData` vazio). Cartão pede `cardBrick.getFormData()` e manda o token em `formData`. O servidor monta o corpo da cobrança.

Eventos: `checkout-mp-init` (remonta o Brick com o payload do gateway), `checkout-mp-reset` (método manual), `checkout-retry` (tenta de novo depois de cancelado).

## O que é estado e o que é conta

Públicos e editáveis pelo cliente: nome, telefone, e-mail, método, tipo de recebimento, bairro, endereço. Total, linhas, taxa e mínimo são `#[Computed]`, relidos de `CartService` e `FulfillmentService` a cada request. O valor passado ao Brick é `totalCents` (subtotal + taxa da cotação). `createFromCart` refaz a conta.

Métodos vêm de `settings.payment.methods` com `enabled`. Online só entra se `forMethod()->isConfigured()`. O primeiro habilitado começa selecionado. Trocar método, bairro, tipo ou e-mail reenvia `checkout-mp-init` com `payerEmail` só se o e-mail passa em `PaymentMethod::EMAIL_RULES`.

Cidade e UF nascem de `settings.address.city` / `address.state`. Cliente logado no guard `customer` preenche nome, telefone e e-mail.

`placeOrderManual()` chama `OrderService::createFromCart` com `provider: manual` e o snapshot das instruções (no Pix manual, a chave entra no texto). E-mail obrigatório (`email`, max 180). Telefone opcional (`PhoneNumber`). Método tem de ser manual. Sucesso limpa o carrinho e volta para a home. Estoque curto devolve erro e mantém o carrinho. Não grava `checkout.order.*`.

`payOnline()` exige e-mail com `PaymentMethod::EMAIL_RULES` e a mensagem do método. Telefone opcional. Não cria pedido.

## Sessão, por tenant

| Chave | Quem escreve | Para quê |
|---|---|---|
| `checkout.nonce.{id}` | `mount` do checkout, ou o controller se faltar | idempotência; some quando a cobrança é aceita |
| `checkout.order.{id}` | `finishCheckout` no controller | polling e a tela de espera |
| `checkout.retry_cart.{id}` | o mesmo | itens para repor no carrinho |

`awaitingPayment` só com o carrinho já vazio (recarga depois da cobrança). Pix em aberto vale até `pix_expires_at` + 10 min. Em aberto sem essa data (cartão, Pix antigo) vale `PixExpiration` + 5 min. Pago ou cancelado: 30 min; depois a tela volta a ser carrinho vazio.

`retryAfterCancellation` só repõe o carrinho se o pedido da sessão está `cancelled` e não `paid`. Estoque que voltou pode já ter acabado: `add()` que falha é ignorado. Em seguida esquece order e retry cart e redireciona ao checkout.

## Vitrine

Rotas no grupo `{tenant:slug}` com `tenant` + `tenant.customer`: `/` home, `/p/{productSlug}`, `/categoria/{categorySlug}`, `/carrinho`. Loja com `is_active` falso é 404 no middleware, antes do componente.

Home lista vitrines ativas da loja, `sort_order`, e descarta as que `getProducts()` devolve vazias. Card de produto: id, nome, slug, `price_cents`, imagem `Storage::url(meta.image_path)`. Banner: `settings.theme.banner_path`. Categoria: slug ativo, senão 404; produtos ativos daquela categoria, `sort_order` e nome. Produto: slug ativo da loja, senão 404.

`TenantHelper::isOpen()` é texto de header e home. Não trava carrinho nem checkout. Sem `business_hours.enabled` (padrão falso) a loja aparece aberta. `paused` fecha. `auto_close` falso deixa aberta. Com auto close, o dia é o nome inglês em minúsculas (`monday`), fuso `America/Sao_Paulo` se não houver outro, e `open`/`close` em `H:i`. Dia `closed`, ou fora da janela, aparece fechada. Falta `open` ou `close`: aberta.

`getDeliveryInfo()` só exibe: mínimo em reais, se entrega, menor taxa entre bairros ativos (ou a padrão), se essa taxa varia, prazo e frete grátis. A cobrança continua sendo `FulfillmentService::quote()`.

## Carrinho na tela

`Cart` e `ProductShow` não têm regra própria. `lines` e `subtotalCents` são computadas de `CartService`. `shortfallCents` é o mínimo da loja menos o subtotal, sem taxa.

`ProductShow.qty` é público. `add()` faz `max(1, qty)`, recusa estourar o estoque (`CartStockLimitException` no campo `qty`) e, se o produto deixou de estar ativo, o `firstOrFail` do serviço responde 404. Sucesso grava o flash e vai para `/carrinho`. `increment` / `decrement` / `remove` / `clear` são o serviço; estoque no incremento vira erro `stock`.

## Conta do cliente

`/conta` e `/conta/perfil` exigem `tenant.customer.auth`. Sem cliente: página redireciona à home com flash; `POST /livewire/update` é 403.

Os dois usam `AuthenticatesTenantCustomer`. O snapshot do Livewire reidrata `Customer` sem o scope e sobrevive a logout. Escrever ou ler dados da conta usa a instância que o guard devolve nesta request, nunca `$this->customer` do snapshot. Cliente da sessão diferente do snapshot, ou de outra loja: 403.

`Index` lista 5 pedidos do cliente re-resolvido, mais recentes primeiro, e repete a checagem no `render`. `Profile::save()` grava nome, e-mail e telefone nessa instância. Telefone aqui é obrigatório, canônico e único na loja (`UniqueCustomerPhone`, ignora o próprio). No checkout o telefone é opcional: quem edita cadastro já preenchido é o perfil (e o admin), não o checkout.

`LoginPopup` abre se não há cookie `customer_session_{tenantId}` nem `login_popup_dismissed_{tenantId}`. Login exige `PhoneNumber`, chama `CustomerAuthService::login` e enfileira o cookie de 90 dias. `dismiss` enfileira o cookie de 1 dia. Não há rota de logout do cliente; `logout()` no serviço zera `session_token` e os testes de revogação chamam isso direto.

## Como Usar

- Propriedade pública é o que o cliente pode alterar. Total, linhas, taxa e mínimo são `#[Computed]`, relidos de `CartService` e `FulfillmentService`.
- Pagamento online não nasce no componente. `payOnline()` valida e dispara `checkout-mp-pay`. O POST está em `resources/views/livewire/tenant/checkout.blade.php`. Não mova esse script para `app.js` como limpeza.
- Pedido manual chama `OrderService::createFromCart`. Não grave `Order` no componente.
- Conta usa `AuthenticatesTenantCustomer`. Leia e grave o `Customer` que o guard devolveu nesta request. `$this->customer` do snapshot sobrevive a logout e vem sem scope.
- `TenantHelper::isOpen()` é texto de header. Não trave carrinho nem checkout com ele.
- Telefone do checkout é opcional. Telefone do perfil é obrigatório. Não unifique os dois sem decisão de produto.
- Não há rota de logout do cliente. Revogação é `CustomerAuthService::logout()`. Não invente a rota para "completar" o fluxo.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Livewire/Tenant/Checkout.php` — estado público, `payOnline`, `placeOrderManual`.
> - `src/resources/views/livewire/tenant/checkout.blade.php` — o POST e os eventos do Brick.
> - `src/app/Livewire/Tenant/Concerns/AuthenticatesTenantCustomer.php` — a conta que não usa o snapshot.
> - `src/app/Livewire/Tenant/ProductShow.php` — `add()` delega ao `CartService`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `tenant-livewire-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
