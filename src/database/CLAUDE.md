# Database

Três bancos, três papéis:

| Nome | Onde | Quem escreve |
|---|---|---|
| `wl` | serviço `db` | a aplicação e `php artisan db:seed` |
| `wl_test` | serviço `db_test` | PHPUnit (`src/phpunit.xml`). `RefreshDatabase` recria as tabelas |
| `wl_e2e` | `db_test`, profile `e2e` | `E2eSeeder`, descartável |

`DatabaseSeeder` chama `TenantSeeder`, `TenantAdminSeeder`, `ShowcaseSeeder`, `CarolDocesDemoSeeder`. Não chama `E2eSeeder`.

`E2eSeeder` apaga e recria os tenants do Playwright. Recusa banco cujo nome não termina em `_e2e`. Slugs, UUIDs, números e datas são fixos. Mercado Pago fica desligado. Não está no `DatabaseSeeder` para não rodar em `wl`.

## Como Usar

- Mudança de esquema é migration nova. Não edite migration já aplicada.
- Dinheiro é integer de centavos. `MonetaryColumnsTest` trava isso. Status de pedido e de pagamento levam os valores do enum e o CHECK que `StatusConstraintsTest` lê.
- Unique de slug e de telefone, em tabela com soft delete, é parcial (`WHERE deleted_at IS NULL`).
- FK composta `(tenant_id, id)` nos pivôs `category_product` e `showcase_product`. Vínculo entre lojas tem de falhar no banco, não só na Policy.
- Seeder de referência e de demo entra no `DatabaseSeeder`. `E2eSeeder` não entra. Chamá-lo em `wl` apaga a demo.
- `migrate:fresh --seed` é o banco `wl`. A suíte recria `wl_test` sozinha.

## Exemplo Real

> Antes de implementar, leia:
> - `src/database/seeders/DatabaseSeeder.php` — a cadeia que roda em `wl`, sem o E2E.
> - `src/database/seeders/E2eSeeder.php` — a recusa de qualquer banco que não termina em `_e2e`.
> - `src/tests/Feature/Database/MonetaryColumnsTest.php` — o que uma coluna de valor precisa ser.
> - `src/tests/Feature/Database/StatusConstraintsTest.php` — o CHECK dos dois status do pedido.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `database-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
