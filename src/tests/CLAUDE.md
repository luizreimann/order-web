# Tests

A suíte é PHPUnit contra PostgreSQL de verdade (`wl_test` em `db_test`). Não use SQLite: o código depende de jsonb, `FOR UPDATE`, `insertOrIgnore` e unique parcial. Não aponte `DB_HOST` para o `db` de desenvolvimento.

Estas classes são listas fechadas. Quebrar uma delas é o contrato mudando, não um teste frouxo:

| Teste | O que trava |
|---|---|
| `Tenancy/TenantIsolationAuditTest` | model sem a trait, `withoutTenantScope` e SQL cru em `app/` |
| `Tenancy/RouteMiddlewareAuditTest` | rota nova fora do grupo `tenant`, e a landing na allowlist |
| `Authorization/LivewireAdminAuthorizationAuditTest` | componente admin sem `AuthorizesOnHydrate` |
| `Unit/Support/AdminNavigationTest` | ordem, rótulo, rota e ícone do menu |
| `Database/MonetaryColumnsTest` | coluna de dinheiro que não é inteiro de centavos |
| `Database/StatusConstraintsTest` | CHECK de `orders.status` e `orders.payment_status` |

Concorrência (estoque, pagamento, teto de vitrine) abre outro processo PHP. O filho está em `tests/Feature/*/Support/*_child.php`.

`tests/Support/Gateways/FakeGateway.php` só existe em teste. Registrar com `PaymentGatewayManager::extend`, nunca no provider de produção.

E2E Playwright não fica nesta pasta. Ver `src/database/CLAUDE.md`.

## Como Usar

- Estenda `Tests\TestCase`. A base não traz `RefreshDatabase`: o teste que grava banco usa a trait na própria classe, como o vizinho.
- Banco é `wl_test` em `db_test` (`src/phpunit.xml`). Não troque para SQLite e não aponte para `wl`.
- Lista fechada: model, rota, componente admin, item de menu, coluna de dinheiro ou status novo tem de continuar passando no teste da tabela acima. A allowlist muda com o motivo no código. A asserção não afrouxa.
- Gateway falso: `tests/Support/Gateways/FakeGateway.php` via `PaymentGatewayManager::extend()`. Nunca no `AppServiceProvider`.
- Concorrência de estoque, pagamento ou teto de vitrine abre outro processo PHP. O filho fica em `tests/Feature/*/Support/*_child.php`. Duas chamadas no mesmo processo não provam o lock.
- O `phpunit.xml` força `QUEUE_CONNECTION=sync` e `SESSION_DRIVER=array`. Isso é da suíte, não da aplicação.
- Rodar: `docker compose exec app php artisan test`, com `--filter` para um caso. Um teste que precisa de Node fica `skipped` dentro do container.

## Exemplo Real

> Antes de implementar, leia:
> - `src/tests/TestCase.php` — a base, sem trait de banco.
> - `src/tests/Feature/Tenancy/TenantIsolationAuditTest.php` — a lista fechada de isolamento.
> - `src/tests/Feature/Tenancy/TenantScopeTest.php` — `RefreshDatabase` e o scope na prática.
> - `src/tests/Support/Gateways/FakeGateway.php` — o dobro de gateway que o teste registra.
> - `src/phpunit.xml` — host `db_test`, banco `wl_test`, fila `sync`.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `tests-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
