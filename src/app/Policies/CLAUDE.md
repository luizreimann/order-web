# Policies

Recurso de loja passa por `TenantResourcePolicy::allows()`. As policies não conhecem papel: pedem `Role::can(Permission)`. O ator precisa ser `User` com papel de escopo tenant, `users.tenant_id` igual ao tenant do recurso, e a permissão. `platform_admin` não passa (impersonation é a sprint 07; o único lugar a mudar é `allows()`).

`resolvedRole()` null (papel legado) nega. Papel novo: só `Role::permissions()`.

| Permissão | Onde |
|---|---|
| `catalog.manage` | produto, categoria, vitrine (`viewAny`/`view`/`create`/`update`/`delete`) |
| `customers.manage` | cliente |
| `orders.manage` | pedido (`view`/`update`; não há create/delete) |
| `dashboard.view` | `TenantPolicy::view` |
| `store.settings.manage` | configurações da loja |
| `store.slug.update` | trocar o slug |
| `payment.methods.manage` | métodos |
| `payment.integrations.manage` | credenciais |
| `store.status.toggle` | abrir/pausar a loja |
| `payments.view` | lista de pagamentos |

`PaymentPolicy` só tem `viewAny` e `view`.

## Como Usar

- Policy de recurso de loja estende `TenantResourcePolicy` e chama `allows()` com uma `Permission`. Não compare `role` no método.
- `platform_admin` continua sem passar. Impersonation, quando existir, muda só `allows()`.
- Componente admin usa `AuthorizesOnHydrate`. Ação de escrita busca com `forTenant` e autoriza aquele model. Id de outra loja é 404.
- Permissão nova é case de `Permission` e entra em `Role::permissions()` do `tenant_admin`. A policy só conhece a permissão.
- `view` de pagamento não libera editar método nem credencial. São permissões diferentes.

## Exemplo Real

> Antes de implementar, leia:
> - `src/app/Policies/TenantResourcePolicy.php:allows` — a regra única.
> - `src/app/Policies/ProductPolicy.php` — `catalog.manage` em view/create/update/delete.
> - `src/app/Policies/OrderPolicy.php` — view e update, sem create/delete.
> - `src/app/Policies/TenantPolicy.php` — dashboard, slug, métodos, credenciais, pausa.
> - `src/tests/Feature/Authorization/LivewireAdminAuthorizationAuditTest.php` — componente sem a trait quebra a suíte.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `policies-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
