# Registro de Desenvolvimento — Sprint 06

**Sprint:** 06-refactor
**Status:** 🟡 **EM ANDAMENTO** — 23 de 25 critérios de conclusão satisfeitos (92%), 2 parciais, 0 abertos
**Última atualização:** 2026-10-11

> Log de execução escrito **durante** o trabalho, conforme a convenção das sprints 01–05 (`03-dev.md`) e o requisito de `02-sprint.md` §9: para cada entregável, registrar **o teste que prova a correção** e o resultado antes/depois.
>
> **Não é um relatório de encerramento.** Nenhum critério aberto. Restam 2 parciais.

---

## 1. Status geral

| | Início da sprint | Agora |
|---|---|---|
| Critérios de conclusão (§6 de `02-sprint.md`) | 0 de 25 | **23 de 25** (+2 parciais) |
| Testes PHP | 89 | **1050** (+1 skipped: precisa de Node) |
| Testes E2E (Playwright) | 0 | **122** (76 snapshots visuais) |
| Banco dos testes | SQLite in-memory | **PostgreSQL 16 real** |
| Foreign keys | 2 | **22** |
| Análise estática | inexistente | **PHPStan nível 5, sem erros** |
| Formatação | Pint instalado, nunca rodado | **Pint PASS, 244 arquivos** |
| CI | inexistente | **GitHub Actions, verde (run `38175409724`)** |
| Opt-outs cross-tenant | — | **1** (eram 3 no webhook) |
| Bugs P0 corrigidos | — | **2** |
| Bypasses de autorização corrigidos | — | **2** |

### Critérios satisfeitos (23)

#1 versionado e no remoto · #4 nenhuma ação de admin sem `authorize()` (teste de auditoria estrutural) · #5 `tenant_staff` removido e enum `Role` com escopo · #6 `users.tenant_id` com FK e nullable · #7 global scope nos três contextos · #8 nenhum middleware de auth deixa passar não autenticado · #9 FKs em todo o schema e pivôs com `tenant_id` · #10 `track_inventory` opt-in com teste de concorrência · #11 checkout ponta a ponta com gateway fake · #14 colunas monetárias em `bigint` · #15 status como enum com CHECK · #12 `TYPE_ALL` respeita `max_items` · #13 landing em `/` sem tenant hardcoded · #16 carrinho calculado em um lugar só · #17 webhook idempotente com assinatura validada antes de consultar pedido · #18 nenhuma credencial em claro no estado Livewire · #19 componentes Blade em uso e `style=` inline em 3 · #20 Tailwind removido e CDNs consolidados no Vite (só MP e gtag externos) · #21 ≥ 40 testes de feature e ≥ 8 de isolamento · #22 E2E do checkout no navegador (122 testes, verde 3 vezes seguidas; **só local**: `src/e2e/` ficou fora do git e do CI por decisão do dono do projeto) · #23 todo item herdado com status explícito (`06-inherited-items.md`) · #24 `README.md` reescrito · #25 decisões de produto registradas.

Ressalvas honestas: #9 tem 3 FKs ainda sem composição por tenant (`showcases.category_id`, `orders.customer_id`, `order_items.product_id`); #17 tem uma exceção documentada (rota `/webhooks/{gateway}` sem tenant, que só valida assinatura depois de descobrir o dono do pagamento); #11 é prova no nível HTTP/serviço, não no navegador.

### Parciais (2)

- 🟡 **#2** CI com 3 gates (Pint, PHPStan, PHPUnit), vistos verdes no runner. O Playwright não roda no CI. Falta branch protection.
- 🟡 **#3** Leituras via query builder estão escopadas. Escrita por instância e props Livewire hidratadas NÃO passam pelo scope (limite documentado, §4).

### Abertos (0)

Nenhum.

---|---|---|
| Critérios de conclusão (§6 de `02-sprint.md`) | 0 de 25 | **4 de 25** (+3 parciais) |
| Testes | 89 | **183** (107 unit + 76 feature) |
| Banco dos testes | SQLite in-memory | **PostgreSQL 16 real** |
| Análise estática | inexistente | **PHPStan nível 5, sem erros** |
| Formatação | Pint instalado, nunca rodado | **Pint PASS, 128 arquivos** |
| CI | inexistente | **GitHub Actions com 3 gates** |
| Bugs P0 corrigidos | — | **2** |
| Bypasses de autorização corrigidos | — | **2** |
| Controle de versão | 1 arquivo (`README.md`) | **projeto completo** |

### Critérios satisfeitos (4)

- ✅ **#1** Projeto 100% versionado e no remoto
- ✅ **#7** Global scope operando nos três contextos (tenant, plataforma, impersonation)
- ✅ **#21** ≥ 40 testes de feature (76) e ≥ 8 de isolamento cross-tenant
- ✅ **#25** As 5 decisões de produto registradas (`04-decisions.md`)

### Parciais (3)

- 🟡 **#2** CI existe com 3 dos 4 gates (Pint + PHPStan + PHPUnit). Falta Playwright (item E4.5) e tornar obrigatório via branch protection.
- 🟡 **#3** Leituras via query builder nos 8 models de negócio estão escopadas. **Escrita por instância e hidratação de propriedades Livewire NÃO passam pelo scope** — limite documentado, ver §4.
- 🟡 **#8** Ações destrutivas de admin e de conta de cliente agora rejeitam sessão inválida. O middleware `TenantCustomerAuth` em si continua não bloqueando (item E1.5).

### Abertos (18)

Nenhum item de F2 (integridade de dados), F3 (domínio), F4 (design system), F5 (segurança de UI) ou F6 (documentação) foi iniciado.

---

## 2. Bloco 1 — Fundação ✅ COMPLETO

### E0.1 — Versionamento ✅

**Problema (QA-004):** o único arquivo sob controle de versão era `README.md`. 221 arquivos untracked. O projeto existia apenas no disco local.

Verificação de segurança antes do commit: `src/.env` corretamente ignorado; `.env.example` sem valores reais; sem `vendor/`, `node_modules/`, `.DS_Store` ou chaves. `.gitignore` da raiz criado e depois reforçado com `.env` / `.env.*`.

**Prova:** `git ls-files` → 222 arquivos; `main` sincronizado com `origin/main`.

**Pendências conhecidas:** `src/public/vendor/livewire` (2,1 MB de assets gerados, regeneráveis com `livewire:publish --assets`) e `package-lock.json` da raiz (stub de 88 bytes sem `package.json`) foram commitados para preservar o estado funcional. Ambos removíveis.

### E0.4 — BUG-001: `customer_id` nunca persistido ✅

`OrderService::createFromCart()` passava `customer_id` para `Order::create()`, mas o campo não estava no `$fillable`. **Todo pedido nascia órfão do cliente.**

**ANTES:** `O pedido foi persistido com customer_id nulo. Failed asserting that null is not null.`
**Correção:** uma linha (`'customer_id'` no `$fillable`).
**DEPOIS:** 90 testes verdes.

Teste usa `$order->fresh()` + `assertDatabaseHas()` — duas verificações independentes contra o banco.

Auditoria da mesma classe de bug em 10 models: nenhum outro caso real. Registrada uma assimetria em `Product` (`compare_at_cents` no `$fillable`, `price_cents` não) — inócua hoje, candidata a ser a próxima BUG-001.

### E0.2 — Testes em PostgreSQL ✅

**Problema (QA-002):** a suíte rodava em SQLite in-memory; a aplicação roda PostgreSQL 16 com `jsonb`, `lockForUpdate()` e `insertOrIgnore`.

**Implementado:** serviço `db_test` dedicado (postgres:16-alpine, tmpfs, `fsync=off`, porta 5434), healthchecks `pg_isready` em `db`/`db_test`/`redis`, `depends_on: condition: service_healthy` — o que também corrige a fragilidade da auditoria em que o `depends_on` não esperava o banco aceitar conexões.

`phpunit.xml` foi escolhido em vez de `.env.testing` com justificativa concreta: `git check-ignore` provou que `.gitignore:13` (`.env.*`) ignoraria `src/.env.testing`, que então funcionaria localmente e **sumiria no CI**, caindo silenciosamente de volta para SQLite — o bug que estávamos eliminando, agora invisível.

**Resultado:** 94 → **103 testes**; 0,76s → 1,88s. Banco de desenvolvimento intacto (`wl` vs `wl_test`).

#### O achado que justifica o item inteiro

Um teste novo captura o SQL real emitido por `TenantCounter::nextValue()`. Em SQLite:

```
insert or ignore into "tenant_counters" ...
select "value" from "tenant_counters" where ... limit 1
update "tenant_counters" set "value" = ? where ...
Failed asserting that '...' matches PCRE pattern "/for update/i".
```

**Nenhum `FOR UPDATE`.** O grammar do SQLite **descartava silenciosamente** o `lockForUpdate()`, e `insertOrIgnore` virava `insert or ignore` em vez de `ON CONFLICT DO NOTHING`.

Ou seja: toda a proteção de concorrência do `TenantCounter` — inclusive a que acabara de ser escrita para corrigir o BUG-002 — **nunca havia sido testada**. Era um no-op na suíte.

O teste `test_for_update_really_blocks_a_concurrent_connection` agora abre duas conexões PG independentes, trava a linha em uma e exige que a outra estoure `SQLSTATE 55P03` (`lock_not_available`) — automatizando a reprodução manual do BUG-002. Todos os 8 testes de `PostgresFeaturesTest` têm guard que **falha explicitamente** se a suíte voltar para SQLite.

### E0.3 — Pint, PHPStan e CI ✅

**Problema (QA-003):** Pint instalado e nunca invocado; nenhuma análise estática; nenhum CI; nenhuma barreira contra regressão.

| Item | Resultado |
|---|---|
| `pint.json` | preset `laravel`, aplicado em 72 arquivos (de 114 fora do padrão) |
| `phpstan.neon` | nível 5, analisando `app/`, `database/` e `tests/` |
| PHPStan: erros | **70 → 8** (−89%), apenas com tipagem/docblock |
| Baseline | 8 entradas, todas triadas individualmente |
| `composer.json` | scripts `lint`, `format`, `analyse` |
| `.github/workflows/ci.yml` | 3 gates, service PostgreSQL 16 |

**Causa raiz dos 70 erros:** 51 deles vinham de uma única omissão — as 7 factories escritas à mão não tinham a anotação genérica `@extends Factory<Model>`, então `Tenant::factory()->create()` era inferido como `Model` e todo acesso a propriedade virava `property.notFound`. Corrigir isso é o que dá ao PHPStan poder de detectar a classe de erro do BUG-001 daqui em diante.

**Aderência à política de dependências:** o `composer require larastan/larastan` resolveu `phpstan/phpstan` para uma versão publicada **há 2 dias**, violando a regra de ≥7 dias. Foi feito downgrade e pin exato em `2.2.14` (15 dias) para que um `composer update` futuro não puxe silenciosamente uma release recém-publicada.

**CI — decisão de arquitetura verificada empiricamente:** o `phpunit.xml` aponta `DB_HOST=db_test` (nome do serviço Docker). Testei se uma variável de ambiente do SO sobrescreve o `<env>` do PHPUnit rodando a suíte com `DB_HOST=host-invalido-proposital` — a conexão falhou, confirmando a precedência **env do SO > phpunit.xml > .env**. Isso permitiu usar service container nativo no CI (mais rápido) sobrescrevendo apenas `DB_HOST`, sem duplicar configuração.

Também foi corrigido o cache do Composer para usar `composer config cache-files-dir` em vez de caminho fixo — um caminho errado deixaria o cache silenciosamente inútil.

---

## 3. Bloco 2 — Isolamento de tenant 🟡 PARCIAL

### E1.3 — `TenantContext` request-bound ✅

Corrige ARC-002 (`app()->instance(Tenant::class, ...)`, singleton inseguro em Octane e ausente em jobs).

`App\Support\TenantContext` registrado como **`scoped`** (não singleton), com **três estados nomeados**: tenant / plataforma / indefinido. API inclui `runFor()` e `runForPlatform()` com restauração em `finally` — base para impersonation e para jobs.

O teste `context_is_registered_as_scoped_not_singleton` prova que após `forgetScopedInstances()` a instância é outra e vem sem tenant.

### E1.1 — Trait `BelongsToTenant` com global scope ✅

Corrige SEC-003 (isolamento opt-in em ~41 chamadas manuais).

- `App\Models\Scopes\TenantScope` — filtra por `tenant_id`, com **coluna qualificada** (`products.tenant_id`), necessário porque os pivôs `category_product` e `showcase_product` fazem join e ainda não têm `tenant_id` (item E2.2)
- `App\Models\Concerns\BelongsToTenant` — global scope + auto-fill de `tenant_id` no `creating` + `withoutTenantScope()`
- Aplicada em 8 models: `Category`, `Customer`, `CustomerAddress`, `Order`, `OrderItem`, `Payment`, `Product`, `Showcase`
- `scopeForTenant` e as ~42 chamadas `->forTenant()` **mantidas** (item E1.2, separado para manter diffs auditáveis)

**Auditoria dos opt-outs — exatamente 3, todos no caminho de webhook (rota fora do prefixo de tenant):**
```
app/Http/Controllers/MercadoPagoWebhookController.php:33   Order::withoutTenantScope()
app/Gateways/MercadoPagoGateway.php:161                    Order::withoutTenantScope()
app/Gateways/MercadoPagoGateway.php:206                    Payment::withoutTenantScope()
```
Sem eles, o webhook pararia de encontrar pedidos e **os pagamentos deixariam de ser confirmados** — regressão de receita. Cobertos por 4 testes, incluindo o cenário de contexto de outro tenant presente.

#### Decisões de design, todas com justificativa

**Sem contexto: não filtra e não lança exceção.** Lançar quebraria webhook, seeders, jobs, artisan e o futuro painel de plataforma. Os três estados são funcionalmente iguais para plataforma/indefinido, mas **semanticamente distintos** — o que permite à Sprint 07 endurecer o estado indefinido alterando **somente** `TenantScope::apply()`, sem tocar nenhum model. Era exatamente a refatoração transversal que a decisão D2 mandava evitar.

**`User` NÃO recebe o scope.** `platform_admin` terá `tenant_id = null` (D2), e um scope de igualdade é semanticamente incompatível com coluna legitimamente nula. Pior que o filtro seria o auto-fill: as rotas de login ficam **dentro** de `/{tenant:slug}`, então qualquer `User::create()` ali passaria a receber `tenant_id` do contexto silenciosamente — atribuindo tenant a um `platform_admin`. Os 2 pontos de query já filtram explicitamente.

**`TenantCounter` NÃO recebe o scope** — e aqui o scope seria **ativamente nocivo**, não apenas redundante. Com ele, incrementar o contador de um tenant B a partir de um contexto A geraria `where tenant_id = B and tenant_id = A` → 0 linhas → `value` nulo → contador sempre 1 → a segunda compra de B estouraria `orders_tenant_id_number_unique`. O scope transformaria o contador em fonte de unique violation — reintroduzindo o BUG-002 por outro caminho. Cenário real e comum (seeder, job, webhook, impersonation).

### E1.4 + E1.5 — Guards e middleware bloqueante ✅ (2026-09-29, não commitado)

Testes 183 → **213**; PHPStan e Pint limpos; baseline intocado.

- `Role` enum (`platform_admin`, `tenant_admin`) com dimensão de escopo; `tenant_staff` removido do código (só testes de rejeição). `users.tenant_id` segue nullable.
- Guard `tenant_admin` (`TenantAdminGuard`): chave de sessão derivada do tenant, então **login simultâneo em dois tenants no mesmo navegador funciona** (o `SessionGuard` padrão sobrescreveria). Revalida tenant e role a cada `user()`; role legada devolve null em vez de estourar `ValueError`.
- Guard `customer`: lê o cookie do request real (não da cópia duplicada do middleware persistente), que era o que fazia `authenticated_customer` morrer. Sem cache entre requisições, então logout/`is_active=false` valem na hora. Fator continua só o telefone (D3), marcado pelo teste `test_d3_accepted_risk_phone_alone_authenticates`.
- `TenantCustomerAuth` agora **bloqueia** (redirect no GET, 403 no update); `ResolveTenantCustomer` é o opcional para o storefront. Crash de `$customer` não inicializado eliminado.
- `EnsureTenantAdminAuthenticated`: 404 sem tenant (antes 500); `app(Tenant::class)` removido do projeto.
- Testes existentes adaptados sem enfraquecer: login admin passou a ser POST real em vez de `withSession`.

Abertos: closure `toggle-store-status` sem `authorize()` (E1.6); cookies `secure=false` (E1.8); `LoginPopup.php:20-24` lê cookie cru; `Customer` herda `getAuthPassword`/`remember_token` inexistentes (inofensivo).

### E1.6 — Policies e `authorize()` ✅ (2026-09-29, não commitado)

Testes 213 → **289**; PHPStan e Pint limpos. Antes da implementação, 61 dos 76 testes novos falhavam.

- 7 policies sobre `TenantResourcePolicy`; permissões centralizadas em `Role::permissions()`/`can()` (enum `Permission`): criar um papel novo muda um lugar só. `platform_admin` tem permissões `[]` em recursos de tenant até a impersonation (Sprint 07, muda só `TenantResourcePolicy::allows()`).
- O guard default é `web`, onde nunca há usuário; sem ajuste `authorize()`/`@can` veriam sempre um convidado. `Auth::resolveUsersUsing` aponta o Gate para `tenant_admin`.
- `authorize()` em toda ação pública dos 22 componentes admin; trait `AuthorizesOnHydrate` reautoriza a cada `POST /livewire/update`, fechando o re-render com dados para quem perdeu a permissão. Abilities sensíveis separadas: `updateSlug`, `manageIntegrations` (credenciais MP só carregam no snapshot se permitido).
- Closure `toggle-store-status` virou `ToggleStoreStatusController` (mesma rota/JSON).
- `LivewireAdminAuthorizationAuditTest` falha se alguém adicionar ação pública sem autorização (mordida provada removendo `authorize` em `Products\Index::delete`).

Abertos: `WithFileUploads` (`_startUpload`) sem `authorize` próprio (protegido por hydrate + URL assinada); `Categories\Form::updateProductOrder` mexe em produtos no componente; vazamento do Redirector do Livewire entre requests só em teste (contornado por ordenação).

### E1.8 — Cookies `secure` por ambiente ✅ (2026-10-11, não commitado)

`secure` deriva de `session.secure` e, se indefinido, de `production || request()->isSecure()`; fábrica única `makeCookie()` (httpOnly, Lax). `config/session.php` e `.env.example` ajustados (`APP_DEBUG=false` no exemplo). Bug que quase passou: `deleteCookie` passava minutos onde o Symfony espera timestamp, o que viraria cookie de sessão e não apagaria; o teste `isCleared()` pegou. +12 testes.

### F2a — Integridade do schema (E2.1–E2.5) ✅ (2026-10-11, não commitado)

Testes 301 → **377**; PHPStan/Pint limpos. Verificado no PostgreSQL: **22 FKs (eram 2)**, `users.tenant_id` ainda nullable, colunas monetárias `bigint`, unique parcial de slug.

- **E2.1:** FKs em todo o schema. `CASCADE` para catálogo/config, `RESTRICT` para histórico financeiro e dados pessoais (`orders`, `order_items`, `payments`, `customers`; `payments` era CASCADE), `SET NULL` em `orders.customer_id` e `order_items.product_id` (snapshot preservado). `users.tenant_id` com FK e **nullable** (D2).
- **E2.2:** pivôs com `tenant_id` e **FK composta** `(tenant_id, X_id) → X(tenant_id, id)`: o banco rejeita vínculo cross-tenant, sem trigger. Relações gravam o tenant do model no pivô.
- **E2.3:** `*_cents` em `bigint` + `CHECK >= 0` (PG não tem unsigned).
- **E2.4:** slug `NOT NULL`, gerado de forma única por `GeneratesUniqueSlug` (`bolo`, `bolo-2`); `showcases.meta` em `jsonb`.
- **E2.5:** soft deletes em products/categories/customers/showcases (não em `orders`: o ciclo de vida é o status `cancelled`, e `RESTRICT` em `payments` já barra o delete físico). Uniques viraram índices parciais `WHERE deleted_at IS NULL`.
- Migrations `add_session_fields_to_customers` e `add_display_mode_to_showcases` fundidas nas de criação.
- Dois testes de `PostgresFeaturesTest` mudaram: um provava que vários slugs NULL coexistiam (o buraco que o E2.4 fecha); outro inseria contador com tenant inexistente, que a FK agora barra.

Abertos: `showcases.category_id`, `orders.customer_id`, `order_items.product_id` ainda aceitam referência cross-tenant (FK só por `id`); props Livewire (`public ?Product $productModel`) re-hidratam registros já excluídos; pivôs ficam com vínculos de itens excluídos (invisíveis); race de slug protegida só pelo índice único; sessão de cliente excluído deixa de resolver (falta teste).

### F2b — Enums de status e estoque (E2.6, E2.7) ✅ (2026-10-11, não commitado)

Testes 377 → **443**; PHPStan/Pint limpos; concorrência estável em 3 execuções verificadas por mim.

**E2.6.** `OrderStatus` e `PaymentStatus` com `label()`/`variant()` (D8: nunca `primary`/`danger`, testado), casts nos models e **CHECK no PostgreSQL** cujos valores são comparados com os cases do enum via `pg_get_constraintdef`. `payments.status` ficou string **de propósito**: guarda o status bruto do Mercado Pago, e um valor novo faria cast/CHECK estourar justamente ao registrar um pagamento real; `GatewayPaymentStatus` usa `tryFrom`. O PHPStan agora rejeita `$o->status === 'pending'`. Literais substituídos em models, serviços, gateway, controllers, seeder e views (`Rule::enum` nas validações). Bug de passagem: `tenant/account/index.blade.php:68` comparava com `'completed'`, status que não existe, então todo pedido aparecia em inglês e como warning. Mudanças visíveis: pedido "confirmed" de `primary` para `info`; pagamento "rejected" de `danger` para `secondary` (regra D8).

**E2.7 (D1).** `track_inventory` (default false) e `stock_quantity` por produto, com CHECKs de coerência. `OrderService::createFromCart` soma quantidades por produto, trava com um único `SELECT … FOR UPDATE ORDER BY id` (ordem estável contra deadlock) e valida/decrementa na mesma transação; falta de saldo lança `InsufficientStockException` e nada é gravado, nem o contador de pedidos (validação roda antes do `Order::create`). Checkout mostra aviso, `TenantPaymentController` responde 422 sem chamar o gateway. Cancelar devolve o estoque **uma única vez** (idempotente); reativar baixa de novo. Storefront mostra "Esgotado" e limita quantidade. Sem controle de estoque o comportamento é o anterior.

**Prova de concorrência** (`InventoryConcurrencyTest`, processos PHP separados com conexão própria e barreira `pg_advisory_lock`): último item com 2 clientes → 1 ok e 1 insufficient; estoque 3 com 6 clientes → 3 ok, estoque 0, números de pedido 1,2,3 sem buracos. Removendo o `lockForUpdate()`, 3 dos 4 testes falham (venda dupla): o teste morde.

Abertos: pelo `TenantPaymentController` o estoque é baixado antes do gateway; se o pagamento falhar o pedido fica `pending` com estoque baixado (admin cancela para devolver; não tratado); janela de milissegundos se o admin ligar o controle entre a leitura do carrinho e o lock; `Livewire::test()` falha nos componentes admin (usar o endpoint real).

### E1.2 — Limpeza dos `forTenant()` ⬜ NÃO FEITO

**Recomendação: adiar e fundir com E1.4/E1.5.** Justificativa em §5.

---

## 4. Vulnerabilidades encontradas e corrigidas nesta sessão

Nenhuma das três estava no plano da sprint. Todas surgiram de revisão cética sobre código recém-escrito.

### ACH-01 — O global scope estava inativo em toda interação do Livewire

**Comprovado por mim** antes de aceitar o relatório. O middleware efetivo de `POST /livewire/update`:

```
EncryptCookies, AddQueuedCookiesToResponse, StartSession,
ShareErrorsFromSession, ValidateCsrfToken, SubstituteBindings
```

Nem `ResolveTenantFromPath`, nem `EnsureTenantAdminAuthenticated`. O Livewire 3 não re-executa o middleware da rota original — apenas uma allowlist hardcoded (`vendor/livewire/.../PersistentMiddleware.php:14-23`), e o app nunca chamava `Livewire::addPersistentMiddleware()` (grep → 0).

**Consequência:** o `TenantContext` ficava vazio em **toda interação do usuário após o primeiro carregamento** (filtrar, paginar, salvar, deletar). O global scope que acabáramos de construir não protegia o caminho principal. Isso tornava o E1.1 uma feature de segurança que não funcionava, e bloqueava o E1.2.

### ACH-02 — Ações destrutivas de admin sem sessão válida

Mesma causa raiz. Prova:

```
ACH-02: o produto foi DELETADO por um POST /livewire/update sem sessão de admin
— EnsureTenantAdminAuthenticated não foi re-executado. Status: 200
```

`Admin\Products\Index::delete()` apagava o registro com **HTTP 200** e sessão esvaziada, usando apenas um snapshot válido (assinado com a `APP_KEY`, não com a sessão).

**Correção:** `Livewire::addPersistentMiddleware([...])` em `AppServiceProvider::boot()`.

Verifiquei no vendor que o Livewire re-aplica **apenas** os middlewares da rota original, filtrados pela allowlist e na ordem da rota — então componentes públicos do storefront não herdam `tenant.auth`. Confirmado por teste.

**Verificação decisiva que fiz pessoalmente:** desativei a correção com um `if (false)` e rodei os 7 testes. Resultado: **5 falharam, 2 passaram** — e os 2 que passaram são exatamente os que *deveriam* passar nos dois cenários (ação de admin com sessão válida, e carrinho público sem login). Os testes são precisos, não genéricos.

### ACH-03 — Bypass de revogação de sessão do cliente

Encontrado pelo code review do bloco 2. Duas coisas do vendor:

1. `Model::save()/update()/delete()` de **instância** usam `newModelQuery()` (`Model.php:1608`), que **não registra global scopes**
2. O Livewire hidrata `public Model $prop` via `newQueryForRestoration()` → `newQueryWithoutScopes()` (`Model.php:1669-1672`)

`Tenant\Account\Profile::save()` salvava um `Customer` vindo do snapshot, sem revalidar o cookie (`TenantCustomerAuth` não era persistente e, além disso, **nunca aborta**).

Provas coletadas — status **200** em todos:
```
BYPASS: perfil ALTERADO após o logout ter revogado o session_token
BYPASS: perfil ALTERADO SEM nenhum cookie de sessão
BYPASS: perfil ALTERADO mesmo com o customer DESATIVADO (is_active = false)
BYPASS: um cliente autenticado escreveu no perfil de OUTRO cliente apenas
        reenviando o snapshot dele
```

O quarto caso é o mais grave e não estava previsto: **qualquer cliente logado podia editar o perfil de qualquer outro cliente do mesmo tenant.** O checksum HMAC do snapshot prova integridade dos dados — não identidade do ator, e não expira, não acompanha logout nem desativação.

**Correção:** `TenantCustomerAuth` adicionado à lista persistente **e** trait `AuthenticatesTenantCustomer` que **re-resolve o customer no servidor a partir do cookie** e aborta com 403 em divergência. Escrever na instância re-resolvida (em vez de na do snapshot) é o que neutraliza o quarto caso.

Um detalhe que só apareceu na implementação: o middleware persistente roda sobre uma requisição **duplicada** (`request()->duplicate()`), então `$request->merge()` / `attributes->set()` morrem com a cópia. Qualquer correção baseada em ler `authenticated_customer` do request seria um **no-op silencioso** — o pior tipo de correção para este repositório.

### Correção de documentação que criaria a próxima falsa confiança

O docblock da trait afirmava que "nos caminhos HTTP de tenant o isolamento é garantido". **Falso.** Foi reescrito com uma seção de limites explícita, citando arquivo:linha do vendor para o próximo dev poder verificar em vez de acreditar:

> O `TenantScope` é um filtro de **leitura via query builder**, e nada mais. Não é barreira de autorização. Não protege escrita por instância. Não protege propriedades Livewire hidratadas. Trate-o como *defense in depth* contra consulta esquecida, nunca como prova de isolamento.

Isso importa porque o BUG-002 sobreviveu a cinco sprints e a uma auditoria completa **justamente por ter sido elogiado como correto** (ver retratação do OK-03 em `01-review.md`). Documentação que promete mais do que entrega é o mecanismo que produz esse tipo de falha.

### Teste de auditoria de rotas

`RouteMiddlewareAuditTest` enumera `app('router')->getRoutes()` e falha se alguma rota fora de uma allowlist explícita não resolver tenant. Tranca 5 propriedades, incluindo a mais importante: **nenhuma rota allowlistada pode servir um componente `App\Livewire\*`** — impede "allowlistar para o teste passar" justamente no caso perigoso.

Verificado que o teste morde: removendo `'up'` da allowlist, dois testes falham com mensagem explicando o que fazer.

---

## 5. Recomendação: adiar o E1.2

O E1.2 removeria as ~42 chamadas `->forTenant()` por serem redundantes com o global scope. **Recomendo adiar e fundir com E1.4/E1.5.**

**A favor de fazer agora** (argumento do revisor): manter as chamadas é uma "mentira confortável" — dá a impressão de que o componente se protege sozinho, quando já depende do middleware para quase tudo. Remover torna o contrato explícito e greppável. E o `RouteMiddlewareAuditTest` agora cobre o risco de um componente ser servido fora do prefixo de tenant.

**Contra** (o que me convence):
1. **Ganho puramente cosmético, risco não nulo.** Nenhuma funcionalidade nova; a única mudança observável possível é um vazamento.
2. **Esta vizinhança ainda está incompleta.** As três vulnerabilidades da §4 estavam todas aqui, e as duas últimas foram descobertas *depois* de o E1.1 ser declarado pronto. O E1.4 (guards) e o E1.5 (middleware que bloqueia) vão reescrever esse código.
3. **6 dos pontos devem permanecer de qualquer forma** (`Showcase::getProducts`, `OrderService`, `CustomerAuthService`) — são alcançáveis sem contexto, onde o scope por decisão de design não filtra. Então o E1.2 nunca seria uma limpeza total.
4. **Defesa em profundidade tem valor real aqui**, dado que acabamos de descobrir que o scope não cobre escrita nem hidratação.

Fazer o E1.2 junto com E1.4/E1.5 permite remover as chamadas **uma vez**, sobre código já estabilizado, com guards reais no lugar.

---

## 6. Achados abertos, priorizados

Documentados, não corrigidos. Priorização consolidada do code review.

| # | Achado | Sev. | Destino |
|---|---|---|---|
| 1 | `OrderService::resolveCustomer()` (`:50-88`) sobrescreve `name`/`email`/`phone` de um `Customer` existente encontrado **por telefone**. Quem souber o telefone de um cliente reescreve os dados dele pelo checkout público, sem cookie algum | **P1** | Escopo próprio — muda o contrato do checkout de convidado |
| 2 | `EnsureTenantAdminAuthenticated:23` usa `app(Tenant::class)`, que instancia `Tenant` vazio em vez de falhar → `UrlGenerationException` 500. Fecha por acidente, não por desenho | P2 | E1.4 |
| 3 | Middleware persistente roda sobre requisição **duplicada**: todo middleware que comunica resultado via `$request->merge()`/`attributes->set()` é no-op em `/livewire/update`. Afeta `TenantCustomerAuth:32-33` e `EnsureTenantAdminAuthenticated:50` | P2 | E1.5 |
| 4 | `TenantCounter::nextValue()` lança `TypeError` opaco com `tenant_id` nulo | P3 | Guard de 3 linhas |
| 5 | Assinatura do webhook validada **só quando o pedido é encontrado** (`MercadoPagoWebhookController:28-47`). Não permite forjar `paid` (status vem da API do MP), mas permite provocar chamadas à API sem autenticação | P3 | E3.5 |
| 6 | `livewire/upload-file` e `livewire/preview-file` sem middleware persistente. Mitigado por URL assinada; resíduo é a URL continuar válida após logout até expirar | P3 | Backlog |
| 7 | Padrão legado Livewire 2 (`getXxxProperty()`) em `Checkout.php` e `Cart.php` — deveria ser `#[Computed]` | P4 | Sprint de UI |
| 8 | `tenant_staff` ainda aceito nos dois `whereIn` (D2 manda remover) | — | E1.6 |

---

## 7. Método: o placar até aqui

`02-sprint.md` §1.3 estabeleceu que **nenhum item é concluído sem um teste que falha antes e passa depois**, com o risco R5 ("repetir a aprovação condicional das sprints 01–05") marcado como probabilidade alta.

O placar de coisas encontradas **porque** o método foi seguido:

| Achado | Como apareceu |
|---|---|
| BUG-001 (`customer_id`) | Estava no plano; o teste confirmou e virou regressão |
| **BUG-002** (`UPDATE` sem `WHERE`) | Seed com dados reais + revisor questionando uma premissa. Sobreviveu a 5 sprints e a uma auditoria que o **elogiou** |
| **`lockForUpdate()` era no-op na suíte** | Migração para PostgreSQL. Descoberto ao capturar o SQL real |
| **ACH-01/02** (scope inativo + delete sem sessão) | Agente questionou como o Livewire trata middleware |
| **ACH-03** (bypass de sessão do cliente) | Code review lendo o vendor do Eloquent e do Livewire |

Cinco problemas reais, **quatro deles fora do plano**. Nenhum teria sido encontrado por leitura de código — todos exigiram executar, capturar SQL, ou ler o vendor.

A lição mais desconfortável segue sendo a do OK-03: a auditoria original classificou como correto, por análise estática, um código que estava quebrado. Os outros nove itens "OK" de `01-review.md` §2 merecem a mesma desconfiança até existir teste cobrindo cada um.

---

## 8. O que falta

| Item | Status |
|---|---|
| Push dos commits acumulados e conferir o CI | ⬜ **próximo** |
| Conferência no navegador do que os testes não alcançam (§10h) | ⬜ com o dono do produto |
| 11 bugs revelados pelo E2E ainda abertos (§10i) | ⬜ triar para a Sprint 07 |
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |
| 18 itens ABERTOS e 3 NÃO VERIFICADOS de `06-inherited-items.md` | ⬜ triar para a Sprint 07 |
| Branch protection obrigando o CI | ⬜ |

Todos os 25 critérios têm status: 23 satisfeitos e 2 parciais.

---|---|
| E4.5 Playwright (E2E do checkout + snapshots visuais) | ⬜ **próximo** |
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |
| Conferência no navegador do que os testes não alcançam (§10h) | ⬜ com o dono do produto |
| 18 itens ABERTOS e 3 NÃO VERIFICADOS de `06-inherited-items.md` | ⬜ triar para a Sprint 07 |

1 dos 25 critérios segue aberto (#22) e 2 parciais.

---|---|
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |
| E4.2/E4.3 Migrar Categorias, Clientes, Pedidos, Vitrines, Tenant/*, storefront e layout admin para os componentes; `<x-confirm-action>` e `<x-admin-nav>` | ⬜ **próximo** |
| E4.5 Playwright (E2E do checkout + snapshots) | ⬜ |
| 18 itens ABERTOS e 3 NÃO VERIFICADOS de `06-inherited-items.md` | ⬜ triar para a Sprint 07 |

1 dos 25 critérios segue aberto (#22) e 3 parciais.

---|---|
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |
| E4.1–E4.3 Design system: escala, `<x-form-field>`, `<x-card>` etc., layout admin | ⬜ **próximo** |
| E4.5 Playwright (E2E do checkout + snapshots) | ⬜ |
| F6 `TYPE_ALL`/`max_items`, itens herdados, `README.md` | ⬜ |

5 dos 25 critérios seguem abertos.

---|---|
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |
| F4 Design system (componentes Blade, escala, layout admin, assets, landing, Playwright) | ⬜ |
| F5 Segurança de UI (escape de CSS no tema, GA id, uploads, innerHTML, nginx) | ⬜ |
| F6 `TYPE_ALL`/`max_items`, itens herdados, `README.md`, `.env.example` | ⬜ |

7 dos 25 critérios seguem abertos, todos em F4–F6.

---|---|
| E1.4 Guards Laravel (admin + customer, dimensão plataforma) | ⬜ **próximo** |
| E1.5 Middleware que bloqueia | ⬜ |
| E1.6 Policies + `authorize()` + remover `tenant_staff` | ⬜ |
| E1.8 Cookies `secure` por ambiente | ⬜ |
| E1.2 Limpeza dos `forTenant()` | ⬜ adiado (§5) |

### Blocos 3 a 10 — não iniciados
F2 (FKs, tipos monetários, enums, soft deletes, `track_inventory`), F3 (`CartService`, telefone, idempotência do webhook, transação, gateway fake, credenciais write-only), F4 (design system), F5 (escape de CSS, uploads, nginx), F6 (documentação, `README`, itens herdados).

18 dos 25 critérios seguem abertos.

---

## 9. Arquivos alterados

**Commitado localmente em 5 commits temáticos (ainda NÃO enviado ao remoto: `main` está 5 à frente de `origin/main`; o CI nunca rodou no GitHub):**

```
b2866a6  docs(sprint): auditoria, plano, decisões e log da Sprint 06
02b481d  feat(seed): dados de demonstração da Carol Doces
67059ac  fix(tenancy): isolamento por tenant como padrão, e 4 falhas encontradas no caminho
fa763a1  test(db): migrar a suíte de SQLite para PostgreSQL
96e4b37  chore(quality): Pint, PHPStan nível 5 e CI
```

Limitação: `67059ac` agrupa BUG-001, BUG-002, E1.1/E1.3 e ACH-01/02/03 porque `Order.php` e `AppServiceProvider.php` carregam mudanças de vários itens e não havia staging interativo.

Pós-commit: 4 erros do intelephense (traits analisados isoladamente: `addGlobalScope`, `creating`, `query`, `fail`) eram falsos positivos; resolvidos com `@mixin` + `@phpstan-require-extends` em `BelongsToTenant` e `InteractsWithLivewireEndpoint`. PHPStan segue limpo.

Antes do commit havia 102 arquivos no `git status` (72 deles apenas formatação do Pint).

| Área | Arquivos |
|---|---|
| Contexto e scope de tenant | `app/Support/TenantContext.php`, `app/Models/Scopes/TenantScope.php`, `app/Models/Concerns/{BelongsToTenant,TenantScoped}.php` |
| Models | 8 com a trait + `Order.php` (`customer_id`), `TenantCounter.php` (BUG-002), `Product.php`/`Showcase.php` (tipagem) |
| Middleware e providers | `ResolveTenantFromPath.php`, `AppServiceProvider.php` |
| Autorização de cliente | `app/Livewire/Tenant/Concerns/AuthenticatesTenantCustomer.php`, `Account/{Profile,Index}.php` |
| Cross-tenant deliberado | `MercadoPagoWebhookController.php`, `MercadoPagoGateway.php` |
| Infra e qualidade | `docker-compose.yml`, `src/phpunit.xml`, `pint.json`, `phpstan.neon`, `phpstan-baseline.neon`, `composer.json`, `.github/workflows/ci.yml` |
| Seeders | `CarolDocesDemoSeeder.php` (novo), `DatabaseSeeder.php` |
| Testes (94 novos) | `TenantContextTest`, `TenantScopeTest`, `WebhookCrossTenantTest`, `LivewirePersistentMiddlewareTest`, `TenantCustomerSessionRevocationTest`, `RouteMiddlewareAuditTest`, `PostgresFeaturesTest`, `TenantCounterTest`, + `OrderServiceTest` |
| Documentação | `.sprint/06-refactor/01..05` |

---

## 10. Itens abertos que precisam de decisão

1. **`created_at` determinístico nos pedidos do seed** — um agente foi além do escopo pedido. Torna o seed reproduzível byte a byte; trivial de reverter.
2. **`src/public/vendor/livewire`** (2,1 MB) e **`package-lock.json` da raiz** commitados para preservar estado. Removíveis.
3. **Chave natural dos produtos no seeder** é `Str::slug($name)`. Se o nome mudar numa edição futura, cria duplicata e deixa o antigo órfão. `sku` seria mais estável, mas não é unique no banco.
4. **Achado P1 da §6** (sobrescrita de dados de cliente pelo checkout público) precisa de decisão de produto: é comportamento aceito do checkout de convidado ou deve ser restringido?

---

## 10b. F3a — `CartService` e telefone (E3.1, E3.2) ✅ (2026-10-11, não commitado)

Testes 443 → 512. Antes da implementação, 18 dos 29 testes novos falhavam.

- **`CartService`** é a única fonte de verdade do carrinho (sessão `cart.{tenant_id}`, linhas, totais, add/increment/decrement/remove/clear, limite de estoque). `Cart`, `Checkout`, `ProductShow`, o badge do header, `OrderService` e `TenantPaymentController` o consomem; um `grep` confirma que não sobrou acesso direto a `cart.*` fora dele. `getXxxProperty()` legados migraram para `#[Computed]`. O total continua recalculado no servidor em `createFromCart`.
- **Mudanças de comportamento:** produto inativo deixou de entrar em carrinho, total e pedido (antes `is_active` nunca era filtrado); item sem `qty` é descartado; o badge conta linhas válidas.
- **Telefone (`App\Support\Phone`):** forma canônica só com dígitos. O `55` só é descartado quando o total tem 12 ou 13 dígitos e o DDD restante é válido, então o DDD 55 nacional nunca perde o prefixo e não há colisão entre números diferentes. `Customer::setPhoneAttribute` normaliza em qualquer caminho de escrita; `firstOrCreateByPhone` roda o INSERT num savepoint e, se perder a corrida pela unique, devolve o vencedor em vez de 500. `(11) 99999-9999`, `11999999999` e `+55 11 99999-9999` resolvem para o mesmo cliente no checkout, no login e no admin.
- **Decisão E3.2b (muda o contrato do checkout de convidado, reversível):** `OrderService::resolveCustomer` só **preenche campos vazios** de um cliente existente; nunca sobrescreve nome/e-mail. Antes, quem soubesse o telefone de um cliente reescrevia os dados dele pelo checkout público. O pedido continua guardando o snapshot digitado. Consequência: um cliente logado que edite o nome no checkout não atualiza mais o cadastro (usa o Perfil).

Abertos: busca por telefone em `Admin/Customers/Index.php:68` e `Admin/Orders/Index.php:51` usa `ilike %termo%` e não acha `(11) 9999` em telefones guardados só com dígitos; checkout/login ainda associam pedido a cliente com `is_active = false`; `TenantPaymentController` valida pouco o payload.

## 10c. E3.6 — Credenciais write-only (ARC-006) ✅ (2026-10-11, não commitado)

Testes 512 → 529. Antes, 11 de 17 falhavam e o token aparecia **em claro no `wire:snapshot` do HTML inicial**.

`mp_access_token` e `mp_webhook_secret` deixaram de ser propriedades públicas. A máscara (`••••` + 4 últimos, ou só `••••` se o segredo tem menos de 8 caracteres) é computada no servidor e não entra no snapshot. O segredo digitado chega como **parâmetro da ação** (`saveIntegrations($token, $secret)`), nunca como estado. Campo vazio preserva a credencial cifrada; `clearAccessToken()`/`clearWebhookSecret()` removem explicitamente, ambas com `manageIntegrations`. `testMercadoPagoConnection` usa a credencial salva quando o campo está vazio e converte qualquer `Throwable` em `failed`, sem log nem flash da exceção. Prova: os testes batem no endpoint real e verificam texto e base64 do segredo no HTML, no snapshot, nos `effects.html` e no `laravel.log`.

Aberto: `MercadoPagoGateway::makeRequest` só captura `ConnectionException`.

## 10d. F4a — Stack de assets e landing (E4.4, D7) ✅ (2026-10-11, não commitado)

- **Tailwind removido** (`tailwindcss`, `@tailwindcss/vite`; grep confirma zero uso). Bootstrap 5.3 é o framework, oficialmente.
- **CDNs no Vite:** Font Awesome 6.7.2 (solid + brands, versão mantida para não mudar visual), Splide 4.1.4, SortableJS 1.15.7, todas com versão exata e mais de 7 dias. Duas entradas: `app.js` (storefront, só Splide) e `admin.js` (admin e login admin, só Sortable). Externos que restam, por razão técnica: SDK do Mercado Pago, gtag e Google Fonts. Verificado por grep.
- **`style=` 49 → 6.** Sobram 2 no checkout (o JS do SDK do MP alterna `display`) e 4 no preview do tema (valor dinâmico por tenant); colapsar os 4 em 1 via custom properties leva a 3.
- **Landing em `/`** por `PlatformLandingController` (não Livewire, para respeitar o teste de rota auditada); sem preço, plano, contato nem link para tenant. `caroldoces` não aparece mais em `app/`, `routes/` nem `resources/`.
- Teste de arquitetura (`AssetStackTest`) falha se voltar script externo, CDN, Tailwind, slug hardcoded ou `style=` acima do limite.

**Não verificado visualmente:** carrossel, ícones e drag-and-drop de pagamentos, categorias e vitrines. O Sortable nas vitrines mudou a estratégia de carga e é o ponto de maior risco.

Abertos: `categories/form.blade.php` inicia o Sortable só no `DOMContentLoaded` (não em `wire:navigate`); o login admin carrega o `admin.js` inteiro; `login-popup.blade.php` tem `d-block !important` que anula o `x-show`; `npm ci` reporta 11 vulnerabilidades (2 críticas), não investigadas.

## 10e. F5 — Segurança de UI (E5.1–E5.5) ✅ (2026-10-11, não commitado)

Testes 601 → 696. Cada item com teste que falhou antes.

- **E5.1 CSS injetável:** `TenantTheme` com allow-list única (5 fontes, cores `#rgb`/`#rrggbb` com fallback). Antes, `font_family` malicioso no banco emitia `Inter; } body { display:none } /*`; 13 de 15 casos maliciosos falhavam. A URL do Google Fonts também vem da allow-list. Descoberta: a validação de `font_family` em `Customization.php:56` era só `string|max:255`; o review anterior dizia que restringia a opções conhecidas, e não restringia.
- **E5.2 Google Analytics:** `/\AG-[A-Z0-9]{4,20}\z/` na escrita e na leitura; valor inválido não emite nada; `Js::from` no JS. Só GA4 (`UA-` morreu em 2023).
- **E5.3 Uploads** (`ImageUpload`, usado nos 5 campos): jpg/png/webp/gif (favicon ico/png), **SVG proibido**, mime e `getimagesize` precisam concordar, dimensões máximas, extensão derivada do conteúdo, nome UUID, arquivo antigo só apagado se estiver sob `tenants/{mesmoTenant}/`. Antes, um GIF enviado como `.jpg` era gravado como `.jpg` e o `.ico` nunca funcionou (a regra `image` o excluía). Limite documentado: arquivo poliglota é aceito, mas gravado como `.gif` com nome UUID, e o nginx só executa `\.php$`.
- **E5.4 Checkout:** `replaceChildren()`; link do ticket só com `https:` e QR só se for base64 válido. Antes, um `javascript:` vindo do gateway virava XSS ao clicar.
- **E5.5 nginx:** `client_max_body_size 50M`, igual ao `php.ini`. Antes um upload de 5 MB dava 413; agora 200, e 55 MB continua 413.

Bug de passagem: re-render com upload rejeitado ou `.ico` dava 500 (`FileNotPreviewableException`); corrigido com `isPreviewable()` nos 5 getters.

**Não provado sem navegador:** o DOM real do checkout. O teste de Node (funções puras de validação) fica *skipped* no container e roda no host.

## 10f. F6 — `max_items`, itens herdados, documentação (E6.2, E6.3) ✅ (2026-10-13, não commitado)

- **D5:** `TYPE_ALL` usa `min(max_items, 100)`; nulo ou < 1 cai no teto, nunca em `limit(0)`. Antes, 10 produtos para `max_items=5`.
- **VAL-004 (race do limite de 10 vitrines):** `Form::save` fazia `count()` e `create()` sem lock. `Showcase::createWithinLimit()` abre transação, trava a linha do tenant com `FOR NO KEY UPDATE` (e não `FOR UPDATE`, que bloquearia a checagem de FK de pedidos e produtos do mesmo tenant), conta, calcula `sort_order` e insere. Teste de concorrência em processos PHP separados; sem o lock, 3 testes falham.
- **`06-inherited-items.md`:** 52 itens das sprints 01–05 reconciliados com o código: 19 resolvidos, 3 aceitos, 9 adiados, **18 abertos**, 3 não verificados (cobertura > 80%, assinatura do webhook contra o sandbox real do MP, drag-and-drop de vitrines). O `01-review.md` §7 tinha status errados: VAL-001/002 constavam abertos e já estavam resolvidos; QA-011 constava parcial e está resolvido. Os adiados marcados "(proposto)" ainda não têm registro em `04-decisions.md`.
- **README reescrito** (raiz) com direção do produto, arquitetura, riscos aceitos (D3 e a regra do `migrate:fresh`); `src/README.md` virou ponteiro. O passo a passo não foi provado em máquina zerada, só conferido contra o código.
- `MERCADOPAGO_INTEGRATOR_ID` já era `env()`; a premissa do review estava errada. `.env.example` agora aponta para o Postgres do compose; validado rodando a suíte como o CI.
- Healthcheck do `app` e `nginx` com `depends_on: service_healthy`; verificado em execução (`wl_app` healthy).

**Abertos de maior impacto (de `06-inherited-items.md`):** o checkout **não aplica** pedido mínimo, taxa de entrega nem entrega grátis (`OrderService.php:59` grava `delivery_fee_cents = 0`; `:56` fixa `pickup`) apesar de o doc da sprint 04 marcar como implementado; `createFromCart` aceita carrinho vazio e grava pedido com 0 itens (os chamadores protegem, o serviço não); não há reconciliação periódica de pagamentos pendentes; não há paginação em lugar nenhum de `app/`.

## 10g. F4b — Design system, piloto Produtos (E4.1, E4.2 parcial) ✅ (2026-10-13, não commitado)

Testes +92. 10 componentes Blade anônimos, compartilhados admin e storefront: `x-form-field`, `x-form-select`, `x-form-check`, `x-card`, `x-page-header`, `x-section-title`, `x-btn` (com `wire:loading`), `x-empty-state`, `x-data-table`, `x-status-badge`. Decisões da escala em `03-design-system.md` §3.4.

**`<x-form-field>` corrige o defeito funcional** (84 `@error` contra 4 `is-invalid`): o `is-invalid`, o `aria-invalid` e o `invalid-feedback` vêm de `$errors` automaticamente. Em Produtos, `@error` foi de 12 para 0 e os 12 campos ficaram marcados. Prova: 13 testes pelo endpoint real; com as views antigas, **10 dos 13 falham**.

Em Produtos, `card-body`, `card shadow-sm`, `form-label`, `form-control`, `form-select` e `table-responsive` à mão caíram a 0. Equivalência verificada capturando o HTML de 8 telas antes e depois; `wire:*`, `@can`, textos e classes dos botões idênticos. Diferenças: wrappers (`vstack gap-3`), `id`/`for` em todos os campos, spinner no Salvar, e o `colspan` do estado vazio de 4 para 5 (a tabela tem 5 colunas, o texto antes ficava descentralizado).

Abertos: slot Blade `</x-slot:nome>` colado ao texto seguinte quebra o `@endslot` (a tag precisa de linha própria); `tenant/account/profile.blade.php:40` tem `id="name"` à mão; sem `prefix`/`suffix` (`input-group`) no `x-form-field`. Recomendação do agente: a API aguenta Categorias, Clientes e Pedidos como está.

## 10h. F4c — Migração dos demais módulos e layout admin (E4.2, E4.3) ✅ (2026-10-13, não commitado)

Quatro frentes em paralelo, views disjuntas, banco de testes próprio por agente. Testes 807 → **934**.

| Frente | Resultado |
|---|---|
| Categorias, Clientes, Pedidos | 6 views; 30 testes de `is-invalid` (23 falham contra as views antigas) |
| Vitrines, Configurações, Dashboard | 14 views; 35 testes (30 falham contra as antigas); preserva Sortable, upload e os inputs Alpine dos segredos do MP |
| Storefront | 11 views; 15 testes; JS do checkout e carrossel intactos (diff vazio, ids e seletores comprovados) |
| Layout admin | `tenant-admin.blade.php` de 705 para **110 linhas**; `AdminNavigation` define o menu uma única vez; JS inline virou módulos Vite; `<x-confirm-action>` |

**Números no projeto inteiro (medidos por mim, antes → agora):** `card-body` à mão 50 → 5; `card shadow-sm` à mão 45 → 1; `form-label` 74 → 9; `form-control` 71 → 3; `@error` 84 → 4; `style=` 49 → 3; 393 usos de componentes. Os 4 `@error` que sobram são avisos de regra de negócio (quantidade, estoque, carrinho), não de campo.

**`<x-form-field>` revelou erros que estavam escondidos:** `BusinessHours` não tinha nenhum `@error`, então as mensagens de horário inválido nunca apareciam; e `@error('selectedProducts')` só casava a chave exata, escondendo `selectedProducts.0`. O componente casa `name.*`.

**`style=` 6 → 3:** os 3 internos do preview do tema viraram classes (`tenant-preview-*`) que leem as custom properties do container, já sanitizadas por `TenantTheme`. Limite do `AssetStackTest` baixado de 6 para 3.

### Bug real encontrado pela migração: E2.2 quebrou a edição de categoria em PostgreSQL

O GET de edição de **qualquer** categoria dava **500**: `SQLSTATE[42702] column reference "tenant_id" is ambiguous`. A causa é minha, do F2a: o pivô `category_product` ganhou `tenant_id`, e `scopeForTenant` fazia `where('tenant_id', …)` sem qualificar, ficando ambíguo no JOIN quando chamado pela relação `belongsToMany`. Só aparece em PostgreSQL e só pela relação, e nenhum teste cobria o GET de edição, então a reordenação com SortableJS ficou inalcançável. Corrigi qualificando a coluna nos 8 `scopeForTenant`. `ForTenantScopeAmbiguityTest` (4 testes): os 4 falhavam antes com o `SQLSTATE` exato e passam agora.

### Comportamentos que mudam e o dono do produto precisa conhecer

- **Tooltips e toasts do admin passam a funcionar de fato.** `bootstrap.bundle` importado como módulo não cria `window.bootstrap`; o layout antigo testava `typeof bootstrap`, que era sempre `undefined`. Os tooltips nunca inicializavam e os toasts caíam no fallback sem animação.
- **Estados vazios do storefront** viram `<x-empty-state>` (texto centralizado e cinza) em vez da caixa azul `alert-info`. É a única mudança visual deliberada nas telas públicas; reverter são 5 linhas.
- Títulos de seção ficam menores e sem `border-bottom` (D8/9.1); `mb-4` virou `gap-3` (24px → 16px entre blocos).
- O botão de salvar ganha spinner e `disabled` durante a ação.

### Achados abertos (não corrigidos)

- **`partials/tenant-theme.blade.php:165` e `:191`:** o tema sobrescreve `.badge.bg-secondary` e `.badge.text-bg-secondary` com `--tenant-secondary`. A regra D8 protege só o `primary`, então "Cancelado", "Fechado", "Esgotado" e "Inativo" saem na cor secundária do tenant; se ele escolher vermelho ou verde, o status perde o significado. Anterior à migração.
- `Checkout`: `addError('payment_method', …)` nunca é exibido (não há `@error('payment_method')`).
- `PaymentSettings::saveIntegrations`: o `validate()` de `mp_public_key` lança antes de `validatedSecret()`, então os erros dos segredos não aparecem se a public key for inválida.
- `login-popup.blade.php:3`: `d-block !important` anula o `x-show`; o popup aparece na hora.
- `categories/form.blade.php`: o Sortable só inicia no `DOMContentLoaded`.
- Faltam ao `x-form-field`: `prefix`/`suffix` (input-group do slug), slot `feedback` fora da coluna (quantidade no produto), e ao `x-card` um slot `media` (cards com imagem); `x-btn` aceita `success`/`warning` mas só documenta 4 variantes.
- `ImageUploadHardeningTest` falha de forma intermitente quando agentes rodam a suíte em paralelo, porque `Storage::fake` usa um diretório compartilhado. Não ocorre em execução isolada.

### O que NÃO foi verificado sem navegador (o dono do produto deve olhar)

Nada disto foi visto numa tela real; só HTML, jsdom e testes de DOM.
- **Layout admin:** sidebar e offcanvas em claro e escuro (o `admin.css` trocou regras diretas por variáveis do Bootstrap), item ativo e grupo aberto, acordeão, hambúrguer do mobile, tema sem flash no recarregar, tooltips e toast do botão de abrir/fechar a loja.
- **Drag-and-drop** de pagamentos, categorias e vitrines (a estratégia de carga do Sortable mudou).
- **Admin de Configurações:** `input-group` do slug, campos de cor do tema e a linha de horário.
- **Storefront:** carrossel, ícones, estados vazios, e o checkout com o Brick do Mercado Pago.

## 10i. E4.5 — Playwright: E2E e snapshots ✅ (2026-10-19, não commitado)

`@playwright/test` 1.64.0 (exata, publicada há 11 dias; a 1.64.1 tinha 5 e foi descartada pela regra de 7 dias). Um comando: `cd src && npm run e2e`.

**Isolamento:** serviço `e2e-app` (profile `e2e` do compose, porta 8081, `php -S`) e banco próprio `wl_e2e`, com `E2eSeeder` que recusa qualquer banco que não termine em `_e2e` e não está no `DatabaseSeeder`. Cada teste restaura o banco por `CREATE DATABASE … TEMPLATE` (~100 ms). Os browsers rodam na imagem oficial `mcr.microsoft.com/playwright:v1.64.0-jammy`, para os snapshots serem os mesmos no Mac e no CI. Verifiquei por conta própria: o banco de dev `wl` e o `wl_test` ficaram intactos, e o teardown não deixa container nem banco `e2e`.

**Verificação minha:** `npm run e2e` → **122 passaram em 2 min**; o agente reportou 3 execuções seguidas sem diff de snapshot.

**Cobertura E2E:** checkout completo (dinheiro e PIX, com pedido, `customer_id`, snapshot de itens, total e estoque conferidos no banco), estoque, validação, login admin, CRUD, isolamento entre dois tenants, SortableJS, tema sem flash, toast e tooltip, offcanvas mobile, carrossel, popup, conta do cliente, segredos do MP ausentes do HTML e do snapshot, e Brick com SDK stubado. **Nenhuma chamada externa** (testado). **76 snapshots visuais**.

**O teste visual morde:** removi `shadow-sm` do `<x-card>`. Com a tolerância inicial (0,1%) a mutação passou despercebida; calibrei para `threshold 0.02` e `maxDiffPixelRatio 0.0002`, e então **54 de 67** testes visuais falharam.

**Nenhuma linha de código de aplicação foi alterada** pelo agente do E2E (nem `data-testid`).

### 13 bugs que o navegador revelou (nenhum aparecia nos 934 testes de PHP)

Cada um virou `test.fail(true, 'BUG: …')`, que passa a falhar quando o bug é corrigido.

**Corrigidos nesta sessão (3, confirmados por mim antes e depois):**
1. **"Sair" do admin dava HTTP 500** (regressão minha do E1.4). `AppServiceProvider` criava `TenantAdminGuard` sem cookie jar, e `SessionGuard::logout()` sempre o usa. O guard agora recebe tudo o que `AuthManager::createSessionDriver` entrega ao guard padrão (cookie jar, dispatcher, request, `rehashOnLogin`, `timeboxDuration`); os eventos `Login`/`Logout` agora disparam. O `TenantAdminGuardTest` já existia e **não conferia o status do POST**. Verificado por curl real: 500 → 302.
2. **Preço ≥ R$ 1.000 era corrompido ao editar produto** (corrupção de dados). O form exibia "1.250,00" e o mutator lia o "." como decimal: salvar um produto de R$ 1.250,00 sem tocar no preço gravava **R$ 1,25**; e `abc` virava R$ 0,00 sem erro. Novo `Money` (pt-BR consciente) e regra `ValidMoney`; o formulário exibe direto dos centavos, e o ciclo exibir→salvar é idempotente. **"1.250" sem vírgula é inválido** de propósito (ambíguo entre mil duzentos e cinquenta e 1,250). Mesmo defeito em `DeliverySettings`, que usava `(int)($x*100)`: 1,15 virava 114 centavos. Verificado: `1.250,00→125000`, `1.250→inválido`, `abc→inválido`.
3. **Menu mobile do admin não navegava.** `data-bs-dismiss` nos `<a>` faz o Bootstrap chamar `preventDefault()`. Removido dos links; o offcanvas fecha porque a página recarrega.

**Abertos (11), a triar na Sprint 07:**
- **Loja "pausada" ainda aceita pedido** (`admin-js.spec.ts:209`): ninguém consulta `TenantHelper::isOpen()` no checkout.
- **"Fechar loja" não fecha com Horários desligado**: `ToggleStoreStatusController:22` grava `paused`, mas `TenantHelper:15` só lê `paused` quando `business_hours.enabled`. O botão muda de cor e mostra toast sem efeito.
- **Confirmações que somem:** pedido enviado, produto, categoria e cliente salvos gravam `session()->flash` e redirecionam para telas que não o exibem (só Vitrines exibe).
- **Pagamentos:** o drag-and-drop morre ao trocar de aba e voltar (`initSortable` só em `DOMContentLoaded`/`livewire:navigated`); e com o Mercado Pago integrado, arrastar na tabela do MP reordena os métodos manuais (`PaymentSettings.php:226`, índices relativos aplicados ao array completo).
- **Popup de login** aparece no primeiro paint (`d-block !important` anula o `x-show`; o atraso de 3 s nunca funciona).
- **Validação em inglês** ("The name field is required."): locale `en`.
- **Labels sem `for`** na quantidade do produto e no login do admin.

### Resultado dos itens "não verificados sem navegador" (§10h)

SortableJS em Vitrines e Categorias OK (persiste no banco, na recarga e na home); em Pagamentos só persiste depois de "Salvar Métodos" e tem os dois bugs acima. Tema sem flash OK (com o `admin.js` bloqueado a página recarrega já escura). Tooltip e toast OK, e o tooltip atualiza o texto após o toggle. Offcanvas abria e fechava mas não navegava (corrigido). Carrossel OK. **Brick real e sandbox do Mercado Pago continuam não verificados**: exigem credenciais de teste.

### Flakiness encontrada no caminho

A segunda execução do E2E falhou em 3 snapshots `produtos-indice`: a listagem ordena por `created_at desc` e o `E2eSeeder` não fixava `created_at`, então 10 produtos empatavam no mesmo segundo e a ordem mudava ao cruzar a virada de um segundo. Corrigido no seeder (data fixa e única por produto), sem regravar nenhum PNG. É flakiness de seed, não de aplicação.

### Decisão do dono do projeto: `src/e2e/` fora do git e do CI

Os specs, os 69 snapshots (5,2 MB) e o suporte ficam só na máquina de quem roda; o job `e2e` foi removido do `ci.yml`. **Consequência assumida:** a regressão visual e o E2E do checkout deixam de ter proteção automática, e um clone novo não os tem. Versionados continuam `playwright.config.ts`, `bin/e2e.sh`, o `E2eSeeder`, o profile `e2e` do compose e as dependências do `package.json`.

### O que NÃO pôde ser provado

- O relógio não está congelado (`context.clock` mata o SortableJS; `Carbon::setTestNow` apaga os uploads do Livewire). A determinação vem de datas absolutas no seed. O ano do rodapé é mascarado.

## 11. Bloco 7 — Pagamentos idempotentes, consistentes e desacoplados (E3.3, E3.4, E3.5)

Escrito com teste primeiro: os testes de `tests/Feature/Payments` foram rodados contra o código antigo (32 de 37 falhavam; os 5 que passavam eram os que já valiam) e só depois a implementação foi escrita.

### E3.3 — Webhook autenticado antes de qualquer consulta, e idempotente

**Dilema:** a assinatura usa o `webhook_secret` do TENANT, que o código antigo só descobria olhando o pedido (e só validava `if ($order)`).
**Estratégia:** a `notification_url` enviada em `createPayment` agora é `/webhooks/{gateway}/{tenantId}` (o id, não o slug: o slug é editável e a URL já foi entregue ao MP). O controller (`PaymentWebhookController`) resolve o gateway (404 se desconhecido), lê o tenant da URL (dado da URL, não do payload; inexistente = 401), valida a assinatura com o secret dele (`validateWebhook`, pura: sem banco, sem rede) e só então processa. Assinatura inválida nunca toca pedidos, pagamentos nem a API do gateway, exista o pedido ou não (provado com `DB::listen` + `Http::assertNothingSent`).
`/webhooks/{gateway}` (sem tenant) ficou como compatibilidade: o tenant vem do pagamento já registrado (uma leitura de `payments.tenant_id`; único opt-out `CROSS-TENANT DELIBERADO` restante) e a assinatura é validada com o secret dele; sem tenant para autenticar, falha fechado (401), salvo local/testing.
**Secret ausente:** produção falha fechado; bypass só em `local`/`testing` (`WebhookSecurity`; antes também `development`).
**Idempotência:** tabela `payment_webhook_events` (UNIQUE `(gateway, gateway_payment_id, status)` + índice `(gateway, request_id)`), `lockForUpdate` em pedido e depois pagamento, comparação de estado sob lock e UNIQUE como rede de segurança. Reenvio com o mesmo `x-request-id` é cortado antes da consulta à API. O status vem sempre da consulta com o token do tenant; `metadata.tenant_id/order_id` continua validada. HTTP: 200 definitivo (processado, reenvio, ignorado), 401 assinatura, 404 gateway, 503 só para API do gateway fora (para reenvio).
**Extras no mesmo código:** recusa/cancelamento assíncrono (PIX expirado) de pedido ainda pendente cancela e devolve o estoque via `OrderService::updateOrder`; aprovação que chega para pedido já cancelado (timeout ambíguo) NÃO reabre o pedido, marca `payment_status = paid` e loga incidente `critical` para estorno.
**Prova de concorrência** (`PaymentConcurrencyTest`, processos PHP separados): 6 webhooks aprovados simultâneos = 1 transição, todos 200; 6 rejeitados = 1 transição e estoque devolvido uma vez; 5 simultâneos sobre tentativa sem id = 1 Payment. Removendo locks E unique, 3 dos 4 testes falham.

### E3.4 — Pedido + pagamento consistentes

- `PaymentService::begin()`: UMA transação curta com baixa de estoque + pedido + tentativa `Payment` (`created`, `amount_cents` = total do pedido, `idempotency_key` persistida). Nenhuma transação aberta durante a chamada HTTP (asserido via `DB::transactionLevel()` dentro do `Http::fake`).
- Duplo submit: a chave é derivada de (tenant, nonce de checkout da sessão, linhas do carrinho); `UNIQUE (gateway, idempotency_key)` decide a corrida. Segundo envio recebe 409 (em andamento) ou o replay do resultado (inclui o QR do PIX). Após falha, a retentativa usa `<chave>:2` (o MP devolveria a falha antiga para a mesma chave) e novo pedido.
- Falha (recusa HTTP 4xx, `rejected` com HTTP 201, timeout, exceção): Payment `failed`/`rejected`, pedido `cancelled`, estoque devolvido uma vez (`OrderService::updateOrder`), 422 amigável.
- Aprovado + falha ao gravar: incidente `payment.incident.charge_not_consolidated` (`critical`, com tenant/pedido/pagamento/id no gateway/chave), pedido e estoque ficam, resposta 202 `reconciling`, o webhook adota a tentativa pelo `metadata.order_id`.
- `createPayment` virou só I/O (não persiste). `MercadoPagoGatewayTest::test_create_payment_returns_success_on_api_success` mudou de intenção para refletir isso.

### E3.5 — Desacoplamento (D4)

`PaymentGatewayInterface` ganhou `key`, `isConfigured`, `checkoutPayload`, `validateWebhook`, `parseWebhook`, `handleWebhook(..., ?Tenant)`; `PaymentGatewayManager` (`forProvider`, `forMethod(PaymentMethod|string, Tenant)`, `extend`, `has`) é singleton e resolve por `settings.payment.methods[].provider` do tenant com fallback para `PaymentMethod::provider()`, só entre provedores registrados. `TenantPaymentController`, `PaymentService`, `PaymentWebhookController` e `PaymentWebhookProcessor` só conhecem a interface. `Checkout.php` não tem mais ids do MP (teste lê o fonte). `FakeGateway` (`tests/Support/Gateways`) roda checkout -> pagamento -> webhook com o container proibido de instanciar `MercadoPagoGateway` e `Http::preventStrayRequests()`.

### Abertos / achados

- Slug `webhooks` colide com `POST /webhooks/{gateway}` (rota de gateway vem antes do grupo de tenant): não há lista de slugs reservados.
- Transição `(gateway, pagamento, status)` é aplicada uma vez: um gateway que volte a um status anterior (ex.: `in_process` -> `pending` -> `in_process`) não reaplica a 2ª vez. O MP não faz isso hoje.
- `validateWebhookSignature` não confere tolerância de `ts` (replay de uma notificação assinada antiga é inofensivo: o status vem da API).
- O número do pedido de uma tentativa cancelada é consumido (numeração com buracos nos cancelados por falha de pagamento).
- Webhooks configurados no painel do MP com a URL antiga `/webhooks/mercadopago` continuam funcionando pela rota sem tenant; o ideal é migrar para a URL com o id do tenant.
