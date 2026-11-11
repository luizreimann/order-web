# Controllers

Checkout online (`TenantPaymentController`), login do admin e a pausa da loja. O webhook (`PaymentWebhookController`) está em `src/app/Services/CLAUDE.md`: autentica antes de consultar, 200 definitivo, 401 assinatura, 404 gateway, 503 transitório. A landing (`PlatformLandingController`, `GET /`) está em `resources/views/CLAUDE.md`: sem tenant e sem dado de loja.

## Login do admin

`GET|POST /{tenant}/admin/login` fica fora de `tenant.auth` e dentro de `tenant`, então o contexto da loja existe. O guard está em `src/app/Auth/CLAUDE.md`.

`login` exige e-mail e senha. Busca `users` com `tenant_id` desta loja, `role = tenant_admin` e o e-mail. Senha por `Hash::check`. E-mail desconhecido e senha errada devolvem o mesmo erro no campo `email`, sem revelar qual. Sucesso: `Auth::guard('tenant_admin')->login($user)` (regenera o id da sessão, sem remember) e vai ao dashboard.

`logout` só desloga esta loja e regenera o token CSRF. Não chama `session()->invalidate()`: carrinho e o admin de outra loja no mesmo navegador continuam. Volta à tela de login desta loja.

## Pausa

`POST /{tenant}/admin/toggle-store-status` exige `toggleStoreStatus`. Inverte só `settings.business_hours.paused` e responde `{success, paused}`. Não regrava o restante do horário. A tela de horários, se salvar em seguida com o valor antigo, sobrescreve essa pausa.

## Checkout online

`TenantPaymentController` não conhece Mercado Pago. Resolve `PaymentGatewayInterface` pelo método e delega consistência a `PaymentService`.

## `POST /{tenant}/process_payment`

Campo do método: `payment_method` ou, nome histórico do Brick, `mp_method`. Manual ou desconhecido: 422. Tem de estar em `settings.payment.methods` com `enabled`, e o gateway `isConfigured`. Senão 422.

Do JSON só entram: método, `customer` (name, phone, email), `fulfillment` (`type`, `region_id`, `address` com line1, line2, city, state, postal_code), `formData` e `device_session_id`. Carrinho, preço e total saem de `CartService` (sessão + banco). Taxa e mínimo são recalculados. Outro campo de fulfillment é descartado.

Nome e e-mail obrigatórios. E-mail inválido em `PaymentMethod::EMAIL_RULES` não cobra. Telefone segue cru para `OrderService`, que normaliza. `DeviceSessionId::sanitize`: 8–512 de `[A-Za-z0-9._-]`; inválido vira null e o pagamento segue.

Nonce da sessão (`checkout.nonce.{tenantId}`) entra na `checkoutKey`. `begin()` + `charge()`.

| Resultado | HTTP |
|---|---|
| estoque, carrinho vazio, mínimo, endereço | 422 com `error` (`insufficient_stock` não cobrou e não gravou) |
| tentativa existente ainda `created` sem id no gateway | 409 `payment_in_progress` |
| recusa ou falha | 422, `message` de `PaymentFailure::userMessage()`. Falha do nosso lado: `error` `payment_unavailable`, sem corpo do gateway |
| aceito e gravado | 200, `ok`, ids, status, `point_of_interaction`, `PixWindow::timing` |
| aceito e gravação falhou (`unconsolidated`) | 202 `reconciling: true`. O cliente pagou |
| replay de tentativa já com id, status ainda `created` | 202 |
| replay já consolidado | 200 |

Cobrança aceita (`finishCheckout`): limpa o carrinho, esquece o nonce, grava `checkout.order.{id}` e `checkout.retry_cart.{id}` com os itens de antes.

## `GET /{tenant}/checkout/status`

Throttle `checkout-status`: 40/min por sessão e 300/min por IP. `Cache-Control: no-store, private`.

O id vem da sessão, revalidado no tenant do path. Sem pedido, pedido de outra loja ou id inexistente: o mesmo 404. Não é oráculo.

Corpo: `status` = `paid`, `cancelled`, `pending` ou `review`. `review` é pago e pedido já cancelado (incidente de estorno): a tela não oferece "tentar de novo".

`pending` chama `PaymentReconciler::reconcileOrder` (throttle de 3 s, erro de rede não derruba o endpoint) e responde o estado novo. Pix pendente pode ganhar `pix` com só `qr_code`, `qr_code_base64` e `ticket_url`, mais `expires_at` e `server_now`. Cancelado por janela vencida (`pix_expires_at` no passado e payment `cancelled` ou `rejected`): `reason: expired`.

## Como Usar

- Tela nova é componente Livewire, não controller. Controller fica para o que não é componente: login do admin, pausa, `process_payment`, status, webhook, landing.
- `TenantPaymentController` não conhece Mercado Pago. Resolve a interface pelo método e delega a consistência ao `PaymentService`. Carrinho, preço e total saem de `CartService`, não do JSON.
- `logout` não chama `session()->invalidate()`. Carrinho e o admin de outra loja sobrevivem.
- A pausa inverte só `business_hours.paused`. Não regrave o bloco de horário neste controller: a tela de horários, se salvar em seguida com o valor antigo, já sobrescreve a pausa. Não "conserte" isso regravando o bloco inteiro aqui.
- E-mail desconhecido e senha errada devolvem o mesmo erro no campo `email`.
- Webhook: a regra está em `Services`. Este controller autentica e traduz `WebhookResult` em HTTP. Não consulte a API do gateway daqui.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Http/Controllers/TenantPaymentController.php` — `processPayment` e `status`, a tabela HTTP do checkout.
> - `src/app/Http/Controllers/TenantAdminAuthController.php` — login sem revelar qual campo falhou, logout desta loja.
> - `src/app/Http/Controllers/ToggleStoreStatusController.php` — só `paused`.
> - `src/app/Http/Controllers/PaymentWebhookController.php:handle` — secret antes de qualquer consulta.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `controllers-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
