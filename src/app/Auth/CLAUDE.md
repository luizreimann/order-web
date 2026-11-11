# Guards

Dois guards. Nenhum usa o model que o Livewire reidratou.

## `tenant_admin`

`TenantAdminGuard` estende `SessionGuard`. A chave de sessão inclui o id da loja (`login_tenant_admin_{tenantId}_…`), então o mesmo navegador fica logado em duas lojas. `logout()` só apaga a chave desta loja.

Sem tenant no `TenantContext`, `user()` devolve null. Com tenant, o usuário precisa ser `User`, `role` exatamente `tenant_admin` (lido cru, `tryFrom`, sem estourar em papel legado) e `tenant_id` igual ao contexto. `platform_admin` e `tenant_staff` não entram. Cache da instância é descartado se o tenant ou o id na sessão mudou (Octane e teste reaproveitam o guard).

`login()` sem tenant, ou de quem não pertence a ele, lança `InvalidArgumentException` e não grava sessão. `setUser()` (`actingAs`) marca o usuário como explícito: `user()` ainda exige o papel e a loja, e em falha devolve null sem limpar o `actingAs`.

## `customer`

`CustomerSessionGuard` não autentica por senha: `validate()` devolve falso. Lê o cookie do request real e o tenant do `TenantContext`, nunca o model do snapshot. `CustomerAuthService::validateSessionToken` exige token, esta loja e `is_active`. Sem tenant no contexto, ou cookie vazio, devolve null.

Não há cache entre requests. A memoização vale só para o mesmo request, o mesmo tenant e o mesmo token, porque a instância sobrevive em teste e no Octane. `forgetUser()` depois de login ou logout.

Quem emite, revoga e define o cookie (`customer_session_{tenantId}`, 90 dias) é `CustomerAuthService`, em `src/app/Services/CLAUDE.md`.

## Como Usar

- Guard novo registra em `Auth::extend` no `AppServiceProvider`. Se estender `SessionGuard`, passe cookie jar e dispatcher: sem isso o logout responde 500.
- Não autentique pelo model que o Livewire reidratou. Os dois guards leem sessão ou cookie mais `TenantContext`.
- `tenant_admin`: `user()` exige papel exatamente `tenant_admin` e `tenant_id` do contexto. `login()` sem tenant, ou de quem não pertence à loja, lança `InvalidArgumentException` e não grava sessão.
- `logout()` do admin apaga só a chave desta loja e regenera o CSRF. Não chame `session()->invalidate()`: o carrinho e o admin de outra loja no mesmo navegador continuam.
- `customer`: `validate()` permanece falso. Não implemente senha aqui. Emissão, rotação e revogação ficam em `CustomerAuthService`.
- `login()` do cliente não consulta `is_active`. O guard consulta. Não "corrija" o login para recusar antes sem uma decisão: hoje o token gira e a request seguinte é recusada.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Auth/TenantAdminGuard.php` — chave por loja, `user()` e `logout()`.
> - `src/app/Auth/CustomerSessionGuard.php` — cookie do request real, `validate()` falso.
> - `src/app/Providers/AppServiceProvider.php:boot` — `Auth::extend` com cookie jar e dispatcher.
> - `src/app/Services/CustomerAuthService.php` — quem grava o cookie.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `auth-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
