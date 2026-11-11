# Providers

`AppServiceProvider` é o único.

`TenantContext` é `scoped`: uma instância por request ou job, sem vazar no Octane. `PaymentGatewayManager` é singleton (fábricas, sem dado de request).

Guards no `Auth::extend`: `tenant_session` → `TenantAdminGuard` (cookie jar e dispatcher, senão o logout quebra); `customer_session` → `CustomerSessionGuard`. Contrato dos dois: `src/app/Auth/CLAUDE.md`.

`Livewire::addPersistentMiddleware`: `ResolveTenantFromPath`, `EnsureTenantAdminAuthenticated`, `TenantCustomerAuth`. `ResolveTenantCustomer` não está na lista.

Rate limit `checkout-status`: 40/min por sessão, 300/min por IP.

## Como Usar

- Dado de request (`TenantContext`) é `scoped`. Fábrica sem dado de request (`PaymentGatewayManager`) é singleton. Não inverta: singleton com tenant vaza entre requests no Octane.
- Guard novo: `Auth::extend` neste boot. O que estende `SessionGuard` recebe cookie jar e dispatcher.
- Middleware de tenant ou de auth que o Livewire precisa repetir entra em `addPersistentMiddleware`. Sem isso o `POST /livewire/update` pula a rota.
- O Mercado Pago já nasce no construtor de `PaymentGatewayManager`. Gateway novo entra por `extend()` num provider, como o docblock do manager pede. `FakeGateway` só no teste, pelo mesmo `extend()`.
- Limite novo de rota: `RateLimiter` nomeado em `registerRateLimiters()`, e `throttle:nome` na rota. O número não fica no `web.php`.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Providers/AppServiceProvider.php:register` — `scoped` e singleton.
> - `src/app/Providers/AppServiceProvider.php:boot` — guards, middleware persistente, rate limit.
> - `src/tests/Feature/Tenancy/LivewirePersistentMiddlewareTest.php` — o que quebra se o middleware sair da lista.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `providers-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
