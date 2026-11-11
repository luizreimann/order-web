# Middleware da loja

| Alias | Classe | Efeito |
|---|---|---|
| `tenant` | `ResolveTenantFromPath` | slug da URL → `TenantContext::set`. `is_active` falso ou slug ausente: 404. Comparte `currentTenant` na view |
| `tenant.customer` | `ResolveTenantCustomer` | não bloqueia. Se o cookie de cliente veio e o guard não autentica, apaga o cookie na resposta |
| `tenant.customer.auth` | `TenantCustomerAuth` | exige o guard `customer`. Página: redirect à home com flash e apaga cookie inválido. Header `X-Livewire`: 403. Sem tenant no contexto: 404 |
| `tenant.auth` | `EnsureTenantAdminAuthenticated` | exige o guard `tenant_admin` desta loja. Página: redirect ao login do admin. Header `X-Livewire`: 403. Sem tenant: 404. Comparte `tenantAdmin` |

`TrustProxies` substitui o middleware global do Laravel. Não é alias de loja. `TRUSTED_PROXIES` vazio: não confia em `X-Forwarded-*`. `*` confia em todos. Lista: IPs separados por vírgula. A leitura é na requisição, não no boot, porque o `withMiddleware()` roda antes do `.env`.

O grupo `/{tenant}` da vitrine usa `tenant` e `tenant.customer`. Conta (`/conta`, `/conta/perfil`) acrescenta `tenant.customer.auth`.

`tenant`, `tenant.customer.auth` e `tenant.auth` estão em `Livewire::addPersistentMiddleware` (`AppServiceProvider`), então o update do componente repete a resolução da loja e a exigência de login. `tenant.customer` não está nessa lista.

O guard não usa o model que o Livewire reidratou. A conta ainda assim revalida dentro da ação (`AuthenticatesTenantCustomer`), porque o snapshot sobrevive ao logout.

## Como Usar

- Rota de loja entra no grupo `{tenant:slug}` com `tenant` e `tenant.customer`. Admin autenticado acrescenta `tenant.auth`. Conta acrescenta `tenant.customer.auth`.
- Middleware que precisa valer no `POST /livewire/update` entra em `Livewire::addPersistentMiddleware`. Hoje: `ResolveTenantFromPath`, `EnsureTenantAdminAuthenticated`, `TenantCustomerAuth`. `ResolveTenantCustomer` fica de fora: ele só limpa cookie rejeitado na resposta da página.
- Rota sem tenant (landing, webhook) entra na allowlist de `RouteMiddlewareAuditTest`, com o motivo. Não confie na posição no arquivo.
- Alias novo registra em `bootstrap/app.php`. O nome que a rota usa é o alias (`tenant`, `tenant.auth`), não a classe.
- `TRUSTED_PROXIES` vazio não confia em `X-Forwarded-*`. `*` confia em todos e não vai para produção. A leitura é na requisição, não no boot.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Http/Middleware/ResolveTenantFromPath.php` — slug, 404 de loja inativa, `TenantContext::set`.
> - `src/app/Http/Middleware/EnsureTenantAdminAuthenticated.php` — página redireciona, Livewire recebe 403.
> - `src/app/Http/Middleware/TrustProxies.php` — a leitura de `TRUSTED_PROXIES` na requisição.
> - `src/bootstrap/app.php` — aliases e a troca do middleware global.
> - `src/tests/Feature/Tenancy/RouteMiddlewareAuditTest.php` — a allowlist.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `middleware-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
