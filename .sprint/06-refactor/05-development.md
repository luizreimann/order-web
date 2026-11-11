# Registro de Desenvolvimento — Sprint 06

**Sprint:** 06-refactor
**Status:** 🟢 **23 de 23 critérios obrigatórios satisfeitos** (#2 e #22 dispensados como obrigatórios pelo dono em 2026-11-10; #3 fechado em §20)
**Última atualização:** 2026-11-10

> Log de execução escrito **durante** o trabalho, conforme a convenção das sprints 01–05 (`03-dev.md`) e o requisito de `02-sprint.md` §9: para cada entregável, registrar **o teste que prova a correção** e o resultado antes/depois.
>
> **Não é um relatório de encerramento.** O §18 (auditoria, final deste arquivo) reconcilia cada critério contra o código, com a evidência medida em 2026-11-08. Onde este log divergiu da realidade, o §18 prevalece.

---

## 1. Status geral

Números **medidos em 2026-11-08** (não herdados de entradas antigas):

| | Início da sprint | Agora |
|---|---|---|
| Critérios de conclusão (§6 de `02-sprint.md`) | 0 de 25 | **23 de 23 obrigatórios** (#2 e #22 dispensados, ver §20) |
| Testes PHP | 89 | **1631** (+5 skipped: 4 precisam de Node, 1 lê o `docker-compose.yml`, só visível no host/CI) |
| Testes E2E (Playwright) | 0 | **137** regulares (68 funcionais + 69 visuais); +184 de varredura só com `E2E_SCAN=1`. **Só local** |
| Banco dos testes | SQLite in-memory | **PostgreSQL 16 real** |
| Foreign keys | 2 | **25** |
| Análise estática | inexistente | **PHPStan nível 5, sem erros** |
| Formatação | Pint instalado, nunca rodado | **Pint PASS, 297 arquivos** |
| CI | inexistente | **GitHub Actions, verde (run `42512498981`)**; sem branch protection |
| Opt-outs cross-tenant em `app/` | — | **1** (`PaymentWebhookProcessor::tenantOfRecordedPayment`) |
| `style=` inline nas views | 49 | **1** (preview do tema do tenant) |
| Bugs P0 corrigidos | — | **2** |
| Bypasses de autorização corrigidos | — | **2** |

### Dispensados como obrigatórios (2), por decisão do dono em 2026-11-10

- ⚪ **#2** CI obrigatório com Playwright. O CI existe e é verde com Pint, PHPStan e PHPUnit em PostgreSQL. Não há Playwright no CI (`src/e2e/` é local, fora do git) nem branch protection. **Não está cumprido**: foi retirado do que a entrega exige.
- ⚪ **#22** E2E do checkout verde. Existe e roda na máquina do dono (137 testes regulares + 56 de entrega), mas não é gate.

### Fechado nesta rodada

- ✅ **#3** isolamento de tenant: leitura, escrita por instância e re-hidratação do Livewire (§20).

### Sem pendência aberta nos critérios obrigatórios

Os itens abaixo do critério (bugs do E2E, entrega, paginação etc.) estão em `06-inherited-items.md` e no §10i, não no placar.

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

### E1.4 + E1.5 — Guards e middleware bloqueante ✅ (2026-09-29, commitado)

Testes 183 → **213**; PHPStan e Pint limpos; baseline intocado.

- `Role` enum (`platform_admin`, `tenant_admin`) com dimensão de escopo; `tenant_staff` removido do código (só testes de rejeição). `users.tenant_id` segue nullable.
- Guard `tenant_admin` (`TenantAdminGuard`): chave de sessão derivada do tenant, então **login simultâneo em dois tenants no mesmo navegador funciona** (o `SessionGuard` padrão sobrescreveria). Revalida tenant e role a cada `user()`; role legada devolve null em vez de estourar `ValueError`.
- Guard `customer`: lê o cookie do request real (não da cópia duplicada do middleware persistente), que era o que fazia `authenticated_customer` morrer. Sem cache entre requisições, então logout/`is_active=false` valem na hora. Fator continua só o telefone (D3), marcado pelo teste `test_d3_accepted_risk_phone_alone_authenticates`.
- `TenantCustomerAuth` agora **bloqueia** (redirect no GET, 403 no update); `ResolveTenantCustomer` é o opcional para o storefront. Crash de `$customer` não inicializado eliminado.
- `EnsureTenantAdminAuthenticated`: 404 sem tenant (antes 500); `app(Tenant::class)` removido do projeto.
- Testes existentes adaptados sem enfraquecer: login admin passou a ser POST real em vez de `withSession`.

Abertos: closure `toggle-store-status` sem `authorize()` (E1.6); cookies `secure=false` (E1.8); `LoginPopup.php:20-24` lê cookie cru; `Customer` herda `getAuthPassword`/`remember_token` inexistentes (inofensivo).

### E1.6 — Policies e `authorize()` ✅ (2026-09-29, commitado)

Testes 213 → **289**; PHPStan e Pint limpos. Antes da implementação, 61 dos 76 testes novos falhavam.

- 7 policies sobre `TenantResourcePolicy`; permissões centralizadas em `Role::permissions()`/`can()` (enum `Permission`): criar um papel novo muda um lugar só. `platform_admin` tem permissões `[]` em recursos de tenant até a impersonation (Sprint 07, muda só `TenantResourcePolicy::allows()`).
- O guard default é `web`, onde nunca há usuário; sem ajuste `authorize()`/`@can` veriam sempre um convidado. `Auth::resolveUsersUsing` aponta o Gate para `tenant_admin`.
- `authorize()` em toda ação pública dos 22 componentes admin; trait `AuthorizesOnHydrate` reautoriza a cada `POST /livewire/update`, fechando o re-render com dados para quem perdeu a permissão. Abilities sensíveis separadas: `updateSlug`, `manageIntegrations` (credenciais MP só carregam no snapshot se permitido).
- Closure `toggle-store-status` virou `ToggleStoreStatusController` (mesma rota/JSON).
- `LivewireAdminAuthorizationAuditTest` falha se alguém adicionar ação pública sem autorização (mordida provada removendo `authorize` em `Products\Index::delete`).

Abertos: `WithFileUploads` (`_startUpload`) sem `authorize` próprio (protegido por hydrate + URL assinada); `Categories\Form::updateProductOrder` mexe em produtos no componente; vazamento do Redirector do Livewire entre requests só em teste (contornado por ordenação).

### E1.8 — Cookies `secure` por ambiente ✅ (2026-10-11, commitado)

`secure` deriva de `session.secure` e, se indefinido, de `production || request()->isSecure()`; fábrica única `makeCookie()` (httpOnly, Lax). `config/session.php` e `.env.example` ajustados (`APP_DEBUG=false` no exemplo). Bug que quase passou: `deleteCookie` passava minutos onde o Symfony espera timestamp, o que viraria cookie de sessão e não apagaria; o teste `isCleared()` pegou. +12 testes.

### F2a — Integridade do schema (E2.1–E2.5) ✅ (2026-10-11, commitado)

Testes 301 → **377**; PHPStan/Pint limpos. Verificado no PostgreSQL: **22 FKs (eram 2)**, `users.tenant_id` ainda nullable, colunas monetárias `bigint`, unique parcial de slug.

- **E2.1:** FKs em todo o schema. `CASCADE` para catálogo/config, `RESTRICT` para histórico financeiro e dados pessoais (`orders`, `order_items`, `payments`, `customers`; `payments` era CASCADE), `SET NULL` em `orders.customer_id` e `order_items.product_id` (snapshot preservado). `users.tenant_id` com FK e **nullable** (D2).
- **E2.2:** pivôs com `tenant_id` e **FK composta** `(tenant_id, X_id) → X(tenant_id, id)`: o banco rejeita vínculo cross-tenant, sem trigger. Relações gravam o tenant do model no pivô.
- **E2.3:** `*_cents` em `bigint` + `CHECK >= 0` (PG não tem unsigned).
- **E2.4:** slug `NOT NULL`, gerado de forma única por `GeneratesUniqueSlug` (`bolo`, `bolo-2`); `showcases.meta` em `jsonb`.
- **E2.5:** soft deletes em products/categories/customers/showcases (não em `orders`: o ciclo de vida é o status `cancelled`, e `RESTRICT` em `payments` já barra o delete físico). Uniques viraram índices parciais `WHERE deleted_at IS NULL`.
- Migrations `add_session_fields_to_customers` e `add_display_mode_to_showcases` fundidas nas de criação.
- Dois testes de `PostgresFeaturesTest` mudaram: um provava que vários slugs NULL coexistiam (o buraco que o E2.4 fecha); outro inseria contador com tenant inexistente, que a FK agora barra.

Abertos: `showcases.category_id`, `orders.customer_id`, `order_items.product_id` ainda aceitam referência cross-tenant (FK só por `id`); props Livewire (`public ?Product $productModel`) re-hidratam registros já excluídos; pivôs ficam com vínculos de itens excluídos (invisíveis); race de slug protegida só pelo índice único; sessão de cliente excluído deixa de resolver (falta teste).

### F2b — Enums de status e estoque (E2.6, E2.7) ✅ (2026-10-11, commitado)

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

## 10b. F3a — `CartService` e telefone (E3.1, E3.2) ✅ (2026-10-11, commitado)

Testes 443 → 512. Antes da implementação, 18 dos 29 testes novos falhavam.

- **`CartService`** é a única fonte de verdade do carrinho (sessão `cart.{tenant_id}`, linhas, totais, add/increment/decrement/remove/clear, limite de estoque). `Cart`, `Checkout`, `ProductShow`, o badge do header, `OrderService` e `TenantPaymentController` o consomem; um `grep` confirma que não sobrou acesso direto a `cart.*` fora dele. `getXxxProperty()` legados migraram para `#[Computed]`. O total continua recalculado no servidor em `createFromCart`.
- **Mudanças de comportamento:** produto inativo deixou de entrar em carrinho, total e pedido (antes `is_active` nunca era filtrado); item sem `qty` é descartado; o badge conta linhas válidas.
- **Telefone (`App\Support\Phone`):** forma canônica só com dígitos. O `55` só é descartado quando o total tem 12 ou 13 dígitos e o DDD restante é válido, então o DDD 55 nacional nunca perde o prefixo e não há colisão entre números diferentes. `Customer::setPhoneAttribute` normaliza em qualquer caminho de escrita; `firstOrCreateByPhone` roda o INSERT num savepoint e, se perder a corrida pela unique, devolve o vencedor em vez de 500. `(11) 99999-9999`, `11999999999` e `+55 11 99999-9999` resolvem para o mesmo cliente no checkout, no login e no admin.
- **Decisão E3.2b (muda o contrato do checkout de convidado, reversível):** `OrderService::resolveCustomer` só **preenche campos vazios** de um cliente existente; nunca sobrescreve nome/e-mail. Antes, quem soubesse o telefone de um cliente reescrevia os dados dele pelo checkout público. O pedido continua guardando o snapshot digitado. Consequência: um cliente logado que edite o nome no checkout não atualiza mais o cadastro (usa o Perfil).

Abertos: busca por telefone em `Admin/Customers/Index.php:68` e `Admin/Orders/Index.php:51` usa `ilike %termo%` e não acha `(11) 9999` em telefones guardados só com dígitos; checkout/login ainda associam pedido a cliente com `is_active = false`; `TenantPaymentController` valida pouco o payload.

## 10c. E3.6 — Credenciais write-only (ARC-006) ✅ (2026-10-11, commitado)

Testes 512 → 529. Antes, 11 de 17 falhavam e o token aparecia **em claro no `wire:snapshot` do HTML inicial**.

`mp_access_token` e `mp_webhook_secret` deixaram de ser propriedades públicas. A máscara (`••••` + 4 últimos, ou só `••••` se o segredo tem menos de 8 caracteres) é computada no servidor e não entra no snapshot. O segredo digitado chega como **parâmetro da ação** (`saveIntegrations($token, $secret)`), nunca como estado. Campo vazio preserva a credencial cifrada; `clearAccessToken()`/`clearWebhookSecret()` removem explicitamente, ambas com `manageIntegrations`. `testMercadoPagoConnection` usa a credencial salva quando o campo está vazio e converte qualquer `Throwable` em `failed`, sem log nem flash da exceção. Prova: os testes batem no endpoint real e verificam texto e base64 do segredo no HTML, no snapshot, nos `effects.html` e no `laravel.log`.

Aberto: `MercadoPagoGateway::makeRequest` só captura `ConnectionException`.

## 10d. F4a — Stack de assets e landing (E4.4, D7) ✅ (2026-10-11, commitado)

- **Tailwind removido** (`tailwindcss`, `@tailwindcss/vite`; grep confirma zero uso). Bootstrap 5.3 é o framework, oficialmente.
- **CDNs no Vite:** Font Awesome 6.7.2 (solid + brands, versão mantida para não mudar visual), Splide 4.1.4, SortableJS 1.15.7, todas com versão exata e mais de 7 dias. Duas entradas: `app.js` (storefront, só Splide) e `admin.js` (admin e login admin, só Sortable). Externos que restam, por razão técnica: SDK do Mercado Pago, gtag e Google Fonts. Verificado por grep.
- **`style=` 49 → 6.** Sobram 2 no checkout (o JS do SDK do MP alterna `display`) e 4 no preview do tema (valor dinâmico por tenant); colapsar os 4 em 1 via custom properties leva a 3.
- **Landing em `/`** por `PlatformLandingController` (não Livewire, para respeitar o teste de rota auditada); sem preço, plano, contato nem link para tenant. `caroldoces` não aparece mais em `app/`, `routes/` nem `resources/`.
- Teste de arquitetura (`AssetStackTest`) falha se voltar script externo, CDN, Tailwind, slug hardcoded ou `style=` acima do limite.

**Não verificado visualmente:** carrossel, ícones e drag-and-drop de pagamentos, categorias e vitrines. O Sortable nas vitrines mudou a estratégia de carga e é o ponto de maior risco.

Abertos: `categories/form.blade.php` inicia o Sortable só no `DOMContentLoaded` (não em `wire:navigate`); o login admin carrega o `admin.js` inteiro; `login-popup.blade.php` tem `d-block !important` que anula o `x-show`; `npm ci` reporta 11 vulnerabilidades (2 críticas), não investigadas.

## 10e. F5 — Segurança de UI (E5.1–E5.5) ✅ (2026-10-11, commitado)

Testes 601 → 696. Cada item com teste que falhou antes.

- **E5.1 CSS injetável:** `TenantTheme` com allow-list única (5 fontes, cores `#rgb`/`#rrggbb` com fallback). Antes, `font_family` malicioso no banco emitia `Inter; } body { display:none } /*`; 13 de 15 casos maliciosos falhavam. A URL do Google Fonts também vem da allow-list. Descoberta: a validação de `font_family` em `Customization.php:56` era só `string|max:255`; o review anterior dizia que restringia a opções conhecidas, e não restringia.
- **E5.2 Google Analytics:** `/\AG-[A-Z0-9]{4,20}\z/` na escrita e na leitura; valor inválido não emite nada; `Js::from` no JS. Só GA4 (`UA-` morreu em 2023).
- **E5.3 Uploads** (`ImageUpload`, usado nos 5 campos): jpg/png/webp/gif (favicon ico/png), **SVG proibido**, mime e `getimagesize` precisam concordar, dimensões máximas, extensão derivada do conteúdo, nome UUID, arquivo antigo só apagado se estiver sob `tenants/{mesmoTenant}/`. Antes, um GIF enviado como `.jpg` era gravado como `.jpg` e o `.ico` nunca funcionou (a regra `image` o excluía). Limite documentado: arquivo poliglota é aceito, mas gravado como `.gif` com nome UUID, e o nginx só executa `\.php$`.
- **E5.4 Checkout:** `replaceChildren()`; link do ticket só com `https:` e QR só se for base64 válido. Antes, um `javascript:` vindo do gateway virava XSS ao clicar.
- **E5.5 nginx:** `client_max_body_size 50M`, igual ao `php.ini`. Antes um upload de 5 MB dava 413; agora 200, e 55 MB continua 413.

Bug de passagem: re-render com upload rejeitado ou `.ico` dava 500 (`FileNotPreviewableException`); corrigido com `isPreviewable()` nos 5 getters.

**Não provado sem navegador:** o DOM real do checkout. O teste de Node (funções puras de validação) fica *skipped* no container e roda no host.

## 10f. F6 — `max_items`, itens herdados, documentação (E6.2, E6.3) ✅ (2026-10-13, commitado)

- **D5:** `TYPE_ALL` usa `min(max_items, 100)`; nulo ou < 1 cai no teto, nunca em `limit(0)`. Antes, 10 produtos para `max_items=5`.
- **VAL-004 (race do limite de 10 vitrines):** `Form::save` fazia `count()` e `create()` sem lock. `Showcase::createWithinLimit()` abre transação, trava a linha do tenant com `FOR NO KEY UPDATE` (e não `FOR UPDATE`, que bloquearia a checagem de FK de pedidos e produtos do mesmo tenant), conta, calcula `sort_order` e insere. Teste de concorrência em processos PHP separados; sem o lock, 3 testes falham.
- **`06-inherited-items.md`:** 52 itens das sprints 01–05 reconciliados com o código: 19 resolvidos, 3 aceitos, 9 adiados, **18 abertos**, 3 não verificados (cobertura > 80%, assinatura do webhook contra o sandbox real do MP, drag-and-drop de vitrines). O `01-review.md` §7 tinha status errados: VAL-001/002 constavam abertos e já estavam resolvidos; QA-011 constava parcial e está resolvido. Os adiados marcados "(proposto)" ainda não têm registro em `04-decisions.md`.
- **README reescrito** (raiz) com direção do produto, arquitetura, riscos aceitos (D3 e a regra do `migrate:fresh`); `src/README.md` virou ponteiro. O passo a passo não foi provado em máquina zerada, só conferido contra o código.
- `MERCADOPAGO_INTEGRATOR_ID` já era `env()`; a premissa do review estava errada. `.env.example` agora aponta para o Postgres do compose; validado rodando a suíte como o CI.
- Healthcheck do `app` e `nginx` com `depends_on: service_healthy`; verificado em execução (`wl_app` healthy).

**Abertos de maior impacto (de `06-inherited-items.md`):** o checkout **não aplica** pedido mínimo, taxa de entrega nem entrega grátis (`OrderService.php:59` grava `delivery_fee_cents = 0`; `:56` fixa `pickup`) apesar de o doc da sprint 04 marcar como implementado; `createFromCart` aceita carrinho vazio e grava pedido com 0 itens (os chamadores protegem, o serviço não); não há reconciliação periódica de pagamentos pendentes; não há paginação em lugar nenhum de `app/`.

## 10g. F4b — Design system, piloto Produtos (E4.1, E4.2 parcial) ✅ (2026-10-13, commitado)

Testes +92. 10 componentes Blade anônimos, compartilhados admin e storefront: `x-form-field`, `x-form-select`, `x-form-check`, `x-card`, `x-page-header`, `x-section-title`, `x-btn` (com `wire:loading`), `x-empty-state`, `x-data-table`, `x-status-badge`. Decisões da escala em `03-design-system.md` §3.4.

**`<x-form-field>` corrige o defeito funcional** (84 `@error` contra 4 `is-invalid`): o `is-invalid`, o `aria-invalid` e o `invalid-feedback` vêm de `$errors` automaticamente. Em Produtos, `@error` foi de 12 para 0 e os 12 campos ficaram marcados. Prova: 13 testes pelo endpoint real; com as views antigas, **10 dos 13 falham**.

Em Produtos, `card-body`, `card shadow-sm`, `form-label`, `form-control`, `form-select` e `table-responsive` à mão caíram a 0. Equivalência verificada capturando o HTML de 8 telas antes e depois; `wire:*`, `@can`, textos e classes dos botões idênticos. Diferenças: wrappers (`vstack gap-3`), `id`/`for` em todos os campos, spinner no Salvar, e o `colspan` do estado vazio de 4 para 5 (a tabela tem 5 colunas, o texto antes ficava descentralizado).

Abertos: slot Blade `</x-slot:nome>` colado ao texto seguinte quebra o `@endslot` (a tag precisa de linha própria); `tenant/account/profile.blade.php:40` tem `id="name"` à mão; sem `prefix`/`suffix` (`input-group`) no `x-form-field`. Recomendação do agente: a API aguenta Categorias, Clientes e Pedidos como está.

## 10h. F4c — Migração dos demais módulos e layout admin (E4.2, E4.3) ✅ (2026-10-13, commitado)

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

## 10i. E4.5 — Playwright: E2E e snapshots ✅ (2026-10-19, commitado)

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

## 10j. Rodada de testes manuais do dono do produto (2026-10-22)

Primeira conferência no navegador. Avaliação do dono: a interface está "praticamente perfeita para um MVP". Cinco observações:

1. **Setas e outros elementos com a cor padrão do Bootstrap em alguns cenários** (por exemplo os chevrons do menu do admin, que aparecem azuis): ainda há variáveis do tema a sobrescrever. **Revisto pelo dono: precisa ser feito.** ✅ feito (§14).
2. **Partes do tema escuro não funcionam** (no print: o card "Preview do Checkout" em Pagamentos fica branco com texto claro, quase ilegível). **Revisto pelo dono: precisa ser feito.** ✅ feito (§14). Pista: o preview usa `bg-white` fixo, que não acompanha o `data-bs-theme`.
3. **Títulos de aba ainda mostravam "Laravel"** ✅ feito.
4. **Marca da plataforma "quitanda.app" e rodapé "Feito com ♡ e quitanda.app"** ✅ feito.
5. **PIX do Mercado Pago:** pedia e-mail de novo e exigia dois cliques ✅ feito (ver abaixo).

### Títulos e marca (itens 3 e 4)

**Causa do "Laravel":** o layout do storefront emitia **dois** `<title>`: o próprio (`config('app.name')`, que vinha do `.env` como "Laravel") e o do partial de SEO. O navegador usa o primeiro, então a aba mostrava "Laravel" mesmo com o título SEO correto logo abaixo. Agora é um só, no partial: `{página} - {loja}` quando a página informa um título (`#[Title]`/`->title()`), senão o título SEO da loja. Verificado no servidor real: `Checkout - Carol Doces`, `Carrinho - Carol Doces`, `Torta Holandesa (fatia) - Carol Doces`, `Bolos - Carol Doces`; a home mantém `Carol Doces`.

`config('app.name')` passa a ser `quitanda.app` (default no `config/app.php`, em `.env.example`, no `.env` local e no `phpunit.xml`, para o teste não depender do `.env`), e `config('app.platform_url')` = `https://quitanda.app`. A landing deixou de dizer "Order Web". O rodapé ganha `Feito com ♡ e <a href="https://quitanda.app" rel="noopener noreferrer">quitanda.app</a>` depois do copyright. `BrandingAndTitlesTest` (10 testes; 9 falhavam antes).

### PIX direto e seleção única (item 5)

Detalhes e provas na seção 12. Em resumo: o cliente escolhe o método uma vez (radios da esquerda), há um botão só ("Pagar com PIX"), o servidor cria o PIX pela API com o e-mail do formulário (nunca pedido duas vezes, sem e-mail placeholder), o QR e o copia e cola aparecem direto, e a página confirma sozinha por polling protegido por sessão e tenant. Cartão usa o Card Payment Brick restrito ao tipo escolhido. **Mudança de comportamento:** nos métodos Mercado Pago o e-mail do formulário passa a ser obrigatório e validado antes de qualquer cobrança (antes, quem pedia o e-mail era o Brick). No fluxo manual ele já era obrigatório (`required|email` em `placeOrderManual`), então nada muda ali.

**Não provado sem credenciais e navegador reais:** o SDK e o Card Payment Brick reais (que esconde o campo de e-mail, aceita `types.excluded` e devolve `getFormData()` no formato esperado), a aparência real do QR, o polling contra o webhook real, o formato real do `point_of_interaction` do PIX, e se o MP aceita PIX identificando o pagador só pelo e-mail. Testei o JS com o SDK stubado.

**Efeito no E2E local (fora do git):** os 20 snapshots divergem só pelo rodapé novo (+25 px). Não foram regravados.

## 11. Bloco 7 — Pagamentos idempotentes, consistentes e desacoplados (E3.3, E3.4, E3.5)

Escrito com teste primeiro: os testes de `tests/Feature/Payments` foram rodados contra o código antigo (32 de 37 falhavam; os 5 que passavam eram os que já valiam) e só depois a implementação foi escrita.

### E3.3 — Webhook autenticado antes de qualquer consulta, e idempotente

**Dilema:** a assinatura usa o `webhook_secret` do TENANT, que o código antigo só descobria olhando o pedido (e só validava `if ($order)`).
> **Atualizado pelo §15 (API de Orders):** a `notification_url` não existe mais no corpo da cobrança (Orders só usa a URL cadastrada no painel do MP); a rota `/webhooks/{gateway}/{tenantId}` e o resto desta seção continuam valendo, com o tópico `order` e o `data.id` da query na assinatura.

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

## 12. Checkout do Mercado Pago reformulado: uma escolha, PIX direto, cartão sem reescolha

> **Atualizado pelo §15:** o corpo da cobrança agora é uma order (`/v1/orders`); o fluxo do front (PIX direto, Brick só para cartão) é o mesmo, com a tela "Gerando o seu Pix…" quando o QR ainda não veio.

Relato do dono do produto: (1) o PIX pedia o e-mail de novo no Brick e (2) eram dois cliques (radio à esquerda + lista de meios do Brick à direita).

**Novo fluxo.** Os radios da esquerda são a ÚNICA escolha; um só botão (`Pagar com PIX` / `Pagar com cartão de crédito|débito`) chama `Checkout::payOnline()`, que valida nome, WhatsApp e e-mail (obrigatório só nos métodos online; `PaymentMethod::EMAIL_RULES`) e só então entrega método e cliente ao script. O script chama o MESMO `POST /{tenant}/process_payment` de antes (`PaymentService::begin()/charge()`: pedido, estoque, `Payment`, `idempotency_key`, compensação e webhook inalterados).

- **PIX:** sem Brick. O servidor cria `payment_method_id: pix` com `payer.email` do formulário; a resposta traz QR e copia e cola, e a página os mostra na hora (`safeQrBase64`, `replaceChildren`, `textContent`). Depois, polling em `GET /{tenant}/checkout/status` (4 s com backoff até 12 s, 10 min no máximo, para em `paid`/`review`/`cancelled`, confere na volta do app do banco). Estados: `paid`, `cancelled` ("Tentar novamente" devolve o carrinho), `review` (pago sobre pedido já cancelado: nunca "tente de novo"), `timeout` ("Continuar verificando"). **[Atualizado pelo §16: o resultado do Pix agora é um modal; o polling do Pix é ~3 s com backoff até 5 s e vai só até a janela de pagamento (5 min) + 90 s, não 4–12 s por 10 min; o texto "expira em …" mostra a janela, nunca 30 minutos; os 10 min valem só para cartão.]**
- **Cartão:** Card Payment Brick (`cardPayment`) só com o tipo escolhido (`types.excluded`), `payer.email` pré-preenchido (o SDK esconde o campo) e `hidePaymentButton`: o botão é o da página, via `getFormData()`. O Brick só é montado com e-mail válido; trocar o radio destrói e recria. O servidor repassa uma LISTA BRANCA (token, emissor, bandeira, documento) e o e-mail do formulário (`MercadoPagoGateway::chargeFormData`).
- **Status:** o id do pedido recém-criado fica na SESSÃO (`checkout.order.{tenant}`) e é revalidado contra o tenant do path; resposta só `{status}`; 404 idêntico para qualquer negação; `throttle:checkout-status` (40/min por sessão + 300/min por IP).
- **Recarregar a página:** com o carrinho vazio e um pedido da sessão em aberto ou decidido há menos de 30 min, o checkout reabre a tela de espera a partir do `Payment` gravado. **[Atualizado pelo §16: um Pix com janela gravada reabre até o fim da janela + 10 min, e o contador usa o `pix_expires_at` gravado; sem janela gravada (Pix de antes do §16) vale o prazo antigo (`PT30M` + 5 min).]**

**Mudanças de teste legítimas:** `StorefrontMarkupContractTest` (ids do Brick antigo `mpPaymentCard`/`mpPixInfo` saíram; entram `mpResult`, `mpPayButton`, etc.); `AssetStackTest` (checkout de 2 `style=` para 0: o PIX usa `d-none` e `width`/`height`; limite total 3 para 1).

**Não provado sem credenciais/navegador reais:** o SDK/Brick de cartão reais, o QR reais na tela, o polling contra o webhook real do MP. Provado: PHP com `Http::fake`/`FakeGateway`, e (local, fora do git) E2E com SDK stubado.

**Abertos:** `process_payment` não tem `throttle`. ~~O PIX usa a expiração padrão do MP (24 h…)~~ **[Resolvido pelo §16: o Pix tem janela de 5 min imposta pelo servidor, que cancela a order no MP; o `expiration_time` de 30 min segue como teto do lado do MP.]**

## 13. Pagamento sem webhook: `notification_url` válida, reconciliação, log e mensagem

> **Atualizado pelo §15:** o bug do 4020 era da API de Payments. Com Orders o corpo nunca leva `notification_url`; `WebhookUrl` agora só calcula a URL mostrada em Admin > Pagamentos. O log `webhook desativado` deixou de existir; reconciliação, `payment.charge.failed` e mensagens seguem como descritas (os códigos de erro agora são os de `errors[]` da API de Orders).

Bug real (teste manual com credenciais de teste): com `APP_URL=http://localhost` o gateway enviava `notification_url=http://localhost/webhooks/mercadopago/<id>`; o MP respondeu HTTP 400, código 4020 ("notificaction_url attribute must be url valid"), o `Payment` ficou `failed`, o pedido `cancelled` e nada foi logado.

- **`App\Support\WebhookUrl`**: só devolve a URL de webhook se `APP_URL` for `https`, sem credenciais/query/fragmento, porta padrão, com host DNS de ao menos dois rótulos (TLD alfabético fora de `.local/.localhost/.test/.internal/.example/.invalid/.lan/...`, nem `example.com|net|org`) ou IP literal público (v4 fora de 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16, 100.64/10, documentação, multicast, reservadas; v6 só `2000::/3` fora de `2001:db8::/32`). A base é `config('app.url')`, nunca o cabeçalho `Host`. Sem URL válida o gateway omite `notification_url` (e remove a que o chamador tenha embutido) e loga uma vez por processo/tenant. Fonte: a documentação do MP manda não usar `localhost`/`127.0.0.1` (com ou sem porta) e o aviso de 14/11/2024 bloqueia `http` em URLs de notificação. A porta não padrão foi rejeitada por conservadorismo (a documentação não a promete).
- **`PaymentReconciler`** (+ `PaymentWebhookProcessor::reconcile`): consulta ativa que reaproveita `processForTenant`/`applyTransition` do webhook (locks pedido → pagamento, UNIQUE de eventos, estoque devolvido em recusa, `paid_on_cancelled_order`). Throttle de 3 s por pagamento com `Cache::add`; qualquer erro vira log (`payment.reconcile.failed|gateway_unavailable`) e o endpoint responde o estado atual. `GET /{tenant}/checkout/status` o chama quando o pedido está pendente. `php artisan payments:reconcile` varre pagamentos em aberto, por tenant. **[Atualizado pelo §16: a varredura `--pix-only` de Pix vencidos agora está agendada a cada minuto; a varredura geral continua sob demanda.]**
- **`MercadoPagoGateway::getPayment`** devolve `null` quando a resposta 200 não traz `status` (antes gravava uma transição para o status vazio).
- **`PaymentFailure`** + log `payment.charge.failed`: ver README (Pagamentos). Falha de serviço (5xx, 401/403, timeout/exceção, códigos MP 3, 4, 5, 3002, 3009, 4020) responde "O pagamento online está indisponível no momento..." e `error: payment_unavailable`; o resto mantém a mensagem antiga.
- **Não provado sem credenciais reais:** que o MP aceita a URL do túnel; PIX em sandbox ponta a ponta; o texto exato dos códigos reais além do 4020.


## 14. Tema: azul do Bootstrap vazando e conflitos claro/escuro (itens 1 e 2 do §10j)

**Método:** varredura programática (local) de 43 telas × tenant rosa e verde × claro e escuro = 172 combinações, 35.332 elementos, mais 4.456 elementos focados por Tab e 972 hovers. Antes: **2.972 ocorrências de azul padrão** (36 famílias) e 2.234 pares abaixo de 4,5:1 (1.274 abaixo de 3:1). Depois: **0 azul** em claro e escuro, com as duas cores, e **0 pares abaixo de 3:1 no admin**.

**Causas raiz (cada uma é uma família, não um caso):**
- `--bs-primary` sozinho não basta: o Bootstrap usa `rgba(var(--bs-primary-rgb), a)` em `.text-primary`, `.bg-primary` e nos anéis de foco, e o tema nunca definia o `-rgb`. Agora define `--tenant-primary-rgb`, `--bs-primary-rgb` e os `-rgb` de link, a partir de `TenantTheme::rgb()` (só inteiros, sempre de cor já validada).
- Azul literal no CSS compilado em foco, `:indeterminate`, `.btn-primary:disabled`, `.nav-link:focus-visible`, `.accordion`, `.pagination`, `.btn-close`, `.form-range` e `.list-group`: cada um passou a ler a variável do tenant.
- **Seta do accordion** (o caso que o dono viu): o SVG tinha a cor fixa dentro do `data:`. Virou máscara com `currentColor`, então segue o texto do botão nos dois temas.
- Fundos fixos (`bg-white`, `bg-light`, `text-dark`) viraram `bg-body`, `bg-body-tertiary`, `text-body`, com o mesmo visual no claro. A causa do card branco no escuro era `x-card muted` usando `bg-light`.
- O Bootstrap redefine todas as `--bs-*` em qualquer `[data-bs-theme]`, então um contêiner claro dentro do admin escuro perdia o tenant; o bloco de variáveis agora vale também para `[data-bs-theme="light"]`.
- O preview de Personalização é a única exceção deliberada: simula o header da loja, que é sempre claro, então fixa `data-bs-theme="light"`.
- **Status neutro (fecha o achado aberto do §10h):** removido o override que pintava `.badge.bg-secondary` com a cor secundária do tenant. "Cancelado", "Fechado", "Esgotado" e "Inativo" agora são cinza em qualquer loja.

**Testes permanentes (mordem, provado por 7 mutações):** `ThemeClassesTest` (falha com classe de cor fixa fora de uma allowlist justificada, e com hex azul do Bootstrap no CSS próprio), `TenantThemeRenderTest` (inclui um teste que lê o CSS compilado do Bootstrap e exige um override do tema para cada azul literal; achou dois `--bs-list-group-active-*` que o agente tinha esquecido) e `TenantThemeRgbTest`.

**Não verificado:** só Chromium (Safari e Firefox não foram testados: máscara, `color-mix`, `radial-gradient` no switch); mobile só por snapshot; hover e `:active` por amostra; a loja não tem tema escuro (o escuro nela foi forçado só para achar classes fixas).

**Abertos:**
- Ícone do botão do WhatsApp: branco sobre `#25D366` = 1,98:1 (26 achados, todos o mesmo botão, que tem `aria-label`).
- `admin.css:~39`: `--bs-accordion-btn-focus-box-shadow: none` tira o indicador de foco do menu admin por teclado. Sugestão: `:focus-visible`.
- Texto branco fixo sobre `btn-primary`: com cor de marca clara o contraste cai (rosa 3,53:1, verde 3,30:1). Sugestão: escolher o texto por luminância.
- `x-status-badge` aceita `light` e `dark`, que são cores fixas (nenhum enum emite hoje).
- `partials/tenant-theme.blade.php` emite comentários CSS em cada página, enviados a todo cliente; trocar por `{{-- --}}` economiza bytes.
- Os 31 snapshots do E2E local diferiam e foram regravados depois de revisão visual (a pasta é local).

## 15. Migração para a API de Orders (`/v1/orders`) (2026-10-23, commitado)

**Por quê:** com o `access_token` de teste do tenant (conta de teste, app "Checkout Transparente + API Orders") `POST /v1/payments` falha SEMPRE com 401 `Unauthorized use of live credentials`; `POST /v1/orders` dá 201. O guia oficial "Como migrar da Payments API para a Orders API" confirma: a Orders API troca esse erro por `invalid_credentials` e é a que aceita essas credenciais. O resto do sistema (pedido, estoque, idempotência, webhook idempotente, reconciliação, polling) não mudou de contrato.

### O que a sandbox REAL mostrou (conta de teste, ~18 orders de R$ 1,00; nenhum segredo em saída)

| Cenário | Resultado observado |
|---|---|
| `POST /v1/orders` com `metadata` | 400 `unsupported_properties` (`'$.metadata' not allowed`). O vínculo com o pedido é o `external_reference` (máx. 64, `[A-Za-z0-9_-]`: o UUID do pedido serve; 65 caracteres, espaço e `@` dão 400 `property_value`). |
| Valor | `total_amount`/`amount` precisam ser STRING (número dá 400 `property_type`); `total_amount` diferente da soma dá 400 `invalid_total_amount`. |
| `X-Idempotency-Key` | obrigatória (400 `empty_required_header`), máx. 128 (400 `invalid_idempotency_key_length`). Mesma chave + mesmo corpo devolve a MESMA order (201); mesma chave + corpo diferente dá 409 `idempotency_key_already_used`. Chave nova com o mesmo `external_reference` cria OUTRA order (não há unicidade por referência). |
| Cartão aprovado (token pela API, MC `5480 8328 0103 3311`, titular `APRO`) | 201 `processed/accredited`, transação `processed/accredited` na hora. `payment_method` volta com `id, type, token, installments, installment_amount, transaction_security`. |
| Cartão recusado (`OTHE`) | **HTTP 402** com `errors[{code: failed, details: ["PAY...: rejected_by_issuer"]}]` E a order em `data` (`failed/failed`, transação `failed/rejected_by_issuer`). É uma order criada e recusada: o gateway a trata como recusa registrada (grava o id, cancela o pedido, devolve o estoque). |
| Cartão `CONT` | 201 `processing/in_process`, sem avançar em 25 s. |
| Débito | `debelo` com Elo de teste: 400 `payment_method_not_allowed_for_collector` (a conta de teste não aceita); `id: elo` com `type: debit_card`: 400 "value must be 'debelo'". Cartão de débito de outras bandeiras não existe em Orders (o guia diz: só Elo e débito virtual Caixa). **Débito não foi provado.** |
| `payer.first_name/last_name` (inclusive acentos e sobrenome composto) e `description` | aceitos. |
| `X-meli-session-id` com valor fictício | aceito (201). |
| **PIX** (`pix`/`bank_transfer`, com e sem `processing_mode`, com e sem `expiration_time`, com `first_name: APRO`, com CPF) | 201 `processing/in_process`, transação `processing/in_process`, `payment_method` só com `id` e `type`. **Nenhum QR em 65 s de polling** (a cada 5 s) nem ao final de dezenas de minutos; a order não muda sozinha. Passado o prazo (`PT30M` ≈ 34 min depois; `PT10M` ≈ 10 min), a order vira `failed/failed` e a transação `failed/processing_error`. A documentação de "Realizar compra teste com Pix" promete `action_required/waiting_transfer` + QR com `payer.first_name: APRO`; aqui não ocorreu. |
| Por que o QR não vem (hipótese, NÃO provada) | `GET /v1/payment_methods` desta conta de teste lista 11 meios (crédito, `debelo`, `bolbradesco`, `account_money`...) e **não lista `pix`**; a documentação de Pix exige chave Pix cadastrada na conta. A ação do dono do produto é cadastrar uma chave Pix na conta de teste (ou testar com outra conta de teste), e reexecutar o cenário. |
| `expiration_time` | a sandbox aceitou `PT10M`, `PT29M` e `P31D`; a documentação diz 30 min a 30 dias. O sistema valida contra a documentação (fora da faixa ou inválido cai em `PT30M`, com aviso uma vez no log). |
| Cancelar | só em `created`/`action_required`: order `processing` (PIX) dá 409 `cannot_cancel_order`; `processed` também; cancelar de novo dá 409 `order_already_canceled`. O cancelamento com sucesso foi provado numa order em modo `manual` (`created`): 200, order `canceled/canceled`, transação `canceled/canceled_transaction` (esse `status_detail` não está na documentação). O estado `action_required` (onde o PIX com QR seria cancelável) NÃO pôde ser alcançado. |
| Consulta | `GET /v1/orders/{id}` 200. Id inexistente ou numérico (da API antiga) dá 400 `invalid_path_param`. A resposta não traz `payer`. |
| Orders `created` em modo manual | `status: created` sem `status_detail`, transação sem status. |

### Decisões

- **Gateway** (`MercadoPagoGateway`): `type: online`, `processing_mode: automatic`, valores string com 2 casas, `external_reference` = id do pedido, `description`, `payer` (e-mail, `first_name`/`last_name` derivados do nome do checkout sem inventar: uma palavra só vira só `first_name`; identificação do Brick para cartão), `X-Idempotency-Key` = a chave persistida, `X-meli-session-id` e `X-Integrator-Id` condicionais. **Sem `notification_url` e sem `metadata`** (a primeira não existe em Orders, a segunda dá 400). O `type` do cartão (`credit_card`/`debit_card`) vem do MÉTODO escolhido, nunca do navegador; o `id` (bandeira) vem do Brick, validado por regex.
- **Identificadores:** `payments.gateway_payment_id` = id da ORDER (`ORD...`); `payments.meta.transaction_id` = `PAY...`; `meta.gateway_details` = status/detalhe brutos de order e transação (+ `note` quando fora da tabela). Ids numéricos antigos: o webhook os ignora (200), `getPayment` os recusa sem HTTP e o `PaymentReconciler` os pula (`ValidatesPaymentIds`), sem erro nem log de falha. Sem migração de dados.
- **Webhook:** tópico `order` (`action: order.processed|canceled|...`, `data.id` = id da order); outros tópicos e ids antigos = 200 ignorado. Assinatura: o manifesto `id:<data.id da QUERY>;request-id:<x-request-id>;ts:<ts>;` com HMAC-SHA256 (o guia de migração diz "o mecanismo é idêntico entre as duas APIs"; o SDK PHP oficial minusculiza o id e usa o `data.id` da query). Como a documentação de Orders não mostra o manifesto e não foi possível receber um webhook real, aceitam-se o `data.id` em minúsculas (forma documentada) E o literal: as duas exigem o segredo. Query (`data_id` no PHP) e corpo divergentes = 401. O estado vem sempre de `GET /v1/orders/{id}`; todas as garantias anteriores (assinatura antes de tudo, tenant pela URL, UNIQUE de eventos, `lockForUpdate` pedido→pagamento, `paid_on_cancelled_order`) seguem nos mesmos testes.
- **Pix assíncrono:** depois de criar a order sem QR, o servidor espera no máximo `MERCADOPAGO_PIX_QR_WAIT` (8 s; passos de 1-2 s; no máximo ~5 consultas; só PIX; falha de consulta não derruba a cobrança). Sem QR: `GET /{tenant}/checkout/status` devolve `pix: {qr_code, qr_code_base64, ticket_url}` (só o dono da sessão, só com o pedido pendente, `no-store`, mesmo throttle) assim que a reconciliação o obtém; o QR já guardado não é apagado por uma consulta que não o traga. O front mostra "Gerando o seu Pix…" e desenha o QR com as mesmas salvaguardas (`safeQrBase64`, `safeHttpsUrl`, `textContent`).
- **Expiração (MP):** `MERCADOPAGO_PIX_EXPIRATION` (`PT30M`), uma só fonte (`App\Support\PixExpiration`) para o `expiration_time` da order: é o teto de segurança do lado do MP (a documentação não aceita menos de 30 min). **[Corrigido pelo §16: o polling, a mensagem "expira em …" e a restauração da tela de espera NÃO usam mais esse prazo; usam a janela de 5 min do cliente (`MERCADOPAGO_PIX_WINDOW`, `App\Support\PixWindow`), imposta pelo servidor. O estoque do Pix pendente não fica mais preso por 30 min: o servidor cancela a order no MP e o pedido quando a janela vence (webhook, polling, `payments:reconcile` agendado).]**
- **Device ID:** `security.js` só na página de checkout; o valor é validado (`App\Support\DeviceSessionId`). **Desvio da instrução:** o regex aceita também `.` (`[A-Za-z0-9._-]`, 8 a 512): os valores reais do MP têm a forma `armor.<hex>.<hex>` (~250 caracteres, observado em discussão pública do SDK do MP); sem o ponto, todo Device ID legítimo seria descartado. CR/LF, espaços e 10 KB continuam descartados.
- **`PaymentFailure`:** lê `errors[].code|message|details`; `CONFIGURATION_CODES` (`invalid_credentials`, `payment_method_not_allowed_for_collector`, `unsupported_properties`, `required_properties`, `invalid_header_value`, cota/idempotência...) e HTTP 401/403/429/5xx = "indisponível"; `property_value`/`property_type` e códigos desconhecidos = mensagem de dado do cliente. O mascaramento de tokens longos agora exige um dígito (senão o código `payment_method_not_allowed_for_collector`, 40 caracteres, virava `[token]` no log).
- **Cancelamento/reembolso:** ~~o contrato do gateway não tem nenhum dos dois~~ **[Atualizado pelo §16: o cancelamento de order existe como interface OPCIONAL (`CancelsPayments`, `MercadoPagoGateway::cancelPayment`) e é usado só para expirar o Pix vencido; reembolso continua fora do contrato.]** O comportamento da API (409 `cannot_cancel_order`/`order_already_canceled`, sucesso só provado em order manual `created`) está provado acima.

### Tabela de status (order/transação do MP → sistema) — `OrderStatusMapper`

| MP (`status`/`status_detail`) | `payments.status` | efeito no pedido |
|---|---|---|
| `processed/accredited`, `processed/partially_refunded` | `approved` | pago (reembolso parcial não rebaixa) |
| `created`, `action_required/waiting_payment\|waiting_transfer\|waiting_retry\|pending_challenge` | `pending` | não pago |
| `processing/*` (`in_process`, `pending_review_manual`, `in_review`), `in_review/in_review` | `in_process` | não pago |
| `action_required/waiting_capture` | `authorized` | não pago |
| `failed/*` | `rejected` | cancela e devolve estoque (se ainda aberto) |
| `canceled/*`, `expired/*` | `cancelled` | cancela e devolve estoque (se ainda aberto) |
| `refunded/refunded` | `refunded` | reembolsado |
| `charged_back/in_process` | `in_mediation` | segue pago |
| `charged_back/settled\|reimbursed` | `charged_back` | reembolsado |
| qualquer par fora da tabela, ou `processed` com detalhe desconhecido | `in_process` + `note: unknown` | nunca paga nem cancela; continua consultado; log `payment.gateway.status_not_in_table` |
| transação aprovada com order recusada/cancelada (contradição) | `in_process` + `note: conflict` | idem |

Aprovação exige order E transação aprovadas. Não tratado: 3DS (`pending_challenge`) e captura manual (`waiting_capture`) são pendentes genéricos porque o checkout não os usa; `charged_back/reimbursed` está documentado de forma contraditória (a doc de transação diz "valor ao vendedor", a de order "devolvido ao pagador") e foi tratado como o `charged_back` anterior (reembolsado), a decidir pelo dono.

### Testes

Novos: `OrderStatusMapperTest` (tabela + ambiguidades), `DeviceSessionIdTest`, `WebhookUrlTest`, `OrdersWebhookTest`, `PaymentSettingsWebhookGuideTest`, `TrustProxiesTest` (sem `TRUSTED_PROXIES` o `X-Forwarded-Proto` forjado é ignorado; `*` honra; IP específico/lista só o proxy listado), `MercadoPagoOrders` (fixtures no formato observado). Reescritos para o formato de Orders mantendo a cobertura: `MercadoPagoGatewayTest`, `MercadoPagoCheckoutFlowTest`, `NotificationUrlTest`, `ChargeFailureTest`, `PaymentConsistencyTest`, `PaymentReconciliationTest`, `CheckoutStatusEndpointTest`, `MercadoPagoDirectFlowTest`, `WebhookIdempotencyTest`, `WebhookCrossTenantTest`, `ReconcilePaymentsCommandTest`, `PaymentConcurrencyTest` (e `payments_child.php`: webhook, status e reconcile em processos PHP separados, agora com orders), `CheckoutOnlinePaymentLivewireTest`, `CheckoutDomSafetyTest`, `AssetStackTest` (allowlist: `sdk.mercadopago.com` e `www.mercadopago.com`, esta só no checkout, para o `security.js` do Device ID).

### O que NÃO foi provado

- **QR do Pix**, Pix pago e a transição `action_required/waiting_transfer` (a conta de teste não gera o QR; ver hipótese acima). O desenho de produção segue a documentação e a fixture de `waiting_transfer` + QR vem dela, não da sandbox.
- **Webhook real do MP** (nenhum chegou; o tenant não tem `webhook_secret` e o `APP_URL` é local): formato do corpo e manifesto da assinatura vêm da documentação e do SDK oficial, não de uma entrega real.
- **Débito** (Elo/`debelo`) e débito virtual Caixa; `X-Integrator-Id` e `integration_data.integrator_id` com um id real; o Card Payment Brick e o `security.js` num navegador de verdade (o Playwright local não foi executado nesta rodada; só o filtro de requisições externas foi ajustado).
- **Cancelamento de Pix `action_required`** (409 `cannot_cancel_order` foi provado para `processing`/`processed`/já cancelada; sucesso, só para order manual `created`).

## 16. Pix em modal e janela de 5 minutos (2026-10-23, commitado)

Pedido do dono: (1) a tela de espera do Pix vira um modal por cima do checkout; (2) o Pix vale no máximo 5 minutos, com contador visível, aplicado de forma segura no servidor. Testes: 1400 + 2 skipped → **1483 + 3 skipped** (os 3 skipped são os de Node, que o container não tem; rodam no host: 16/16). Pint, PHPStan e build limpos. E2E local (`src/e2e`, fora do git): 8 testes novos do modal com `page.clock`, os de checkout adaptados, 41 passaram nesta rodada com `--grep checkout`; nenhum snapshot visual mudou, então nenhum foi regravado. Nenhuma chamada de escrita ao Mercado Pago foi feita nesta tarefa (nenhuma, nem leitura), tudo com `Http::fake`/`FakeGateway`; o tenant `caroldoces` e suas credenciais de produção não foram tocados.

### Decisões

- **Janela:** `MERCADOPAGO_PIX_WINDOW` (s), padrão e **teto 300**, piso 60 (600 vira 300; 10 vira 60; texto, 0, negativo ou `PT5M` caem em 300). Independe do `expiration_time` enviado ao MP (`PT30M`, mínimo da documentação), que fica como teto de segurança do lado dele. `App\Support\PixWindow`.
- **Fonte da verdade = servidor.** Ao consolidar a cobrança de um Pix ainda por pagar (`PaymentService::consolidate`), grava `payments.meta.pix_expires_at` (UTC, arredondado PARA CIMA ao segundo: o cliente nunca fica com menos que a janela). Sem migration. O front recebe `expires_at` + `server_now` em `process_payment`, em `checkout/status` (enquanto pendente) e na tela restaurada (`awaitingPayment`); calcula o restante pelo prazo do servidor corrigido pela diferença de relógio (re-sincronizada a cada resposta). O contador não volta ao recarregar. Pix sem `pix_expires_at` (anteriores ao §16, ou cobrança não consolidada) **nunca** é tocado pela regra.
- **Expiração imposta pelo servidor** (`PixExpiryEnforcer`, chamado pelo `PaymentReconciler`, ou seja, pelo `checkout/status` e por `payments:reconcile`): janela + **10 s** de tolerância (`>`: em t = janela − 1 s, janela e janela + 10 s não cancela; em janela + 11 s cancela). Passos: (a) `GET /v1/orders/{id}` e aplicação do estado pelo caminho idempotente do webhook (`PaymentWebhookProcessor::reconcileWithRemote`); (b) já pago ou qualquer estado final: termina, **nunca cancela um pago**; (c) `created`/`action_required` (mapeados para `pending`): `POST /v1/orders/{id}/cancel` e **reconsulta**; só o estado que o MP mostrar é aplicado (cancelado → pedido cancelado e estoque devolvido uma vez); (d) cancel que falha por rede/timeout/5xx/401/erro desconhecido: nada local muda, log `payment.pix_expiry.cancel_failed` (tenant, pedido, pagamento, status HTTP e código, sem segredo), o próximo ciclo repete (o throttle de 3 s por pagamento vale); (e) 409 `cannot_cancel_order` (pago no último instante) ou `order_already_canceled`: a reconsulta decide, o estado do MP é respeitado. Order ainda `processing/in_process` (o Pix sem QR da sandbox): o MP só cancela `created`/`action_required`, então não se tenta, loga `payment.pix_expiry.not_cancelable_state` e fica pendente. O cancel usa a chave de idempotência estável `pix-expiry:<payment id>`; sem corpo (a doc não tem corpo; o `makeRequest` mandaria `[]`, então POST sem dados agora não envia corpo).
- **Gateway:** interface OPCIONAL `CancelsPayments::cancelPayment(): PaymentCancellation` (`canceled` | `already_canceled` | `not_cancelable(status atual)` | `unavailable(http, código)`), como `ValidatesPaymentIds`. 409 e 400 com `cannot_cancel_order` são "não cancelável" (a doc lista 400, a sandbox deu 409). `FakeGateway` implementa com resultado configurável. Um gateway sem a capacidade fica sem a janela (só o prazo do provedor).
- **Comando e agendador:** `payments:reconcile` agora varre primeiro os Pix vencidos (qualquer idade, sem esperar os 10 min) e `--pix-only` só faz isso. **Registrei o agendamento** `payments:reconcile --pix-only` a cada minuto, `withoutOverlapping(10)` em `routes/console.php`. Justificativa: é seguro porque consulta o MP antes de qualquer cancelamento, respeita o pago, só toca Pix com `pix_expires_at` (os de antes e os de cartão ficam de fora) e só gasta chamadas quando há Pix vencido em aberto; sem ele, quem fecha a aba deixaria o estoque preso até o MP expirar a order (30 min). A varredura geral continua não agendada. **Pré-requisito de produção:** o agendador do Laravel precisa rodar (cron `schedule:run` a cada minuto); este repositório não tem esse processo (nem `schedule:work` no compose). Sem ele a expiração só acontece quando o cliente consulta o status ou alguém roda o comando.
- **Modal (UI):** `<div class="modal fade" id="pixModal">` estático no Blade, `modal-dialog-centered modal-dialog-scrollable`, `role="dialog" aria-modal="true" aria-labelledby`, `data-bs-backdrop="static" data-bs-keyboard="false"`, cabeçalho com o botão "Fechar" (`btn-close`, só esconde), corpo e rodapé preenchidos por JS com `textContent`/nós DOM (nada de `innerHTML`, mesmas salvaguardas `safeQrBase64`/`safeHttpsUrl`), região `aria-live="polite"` oculta que anuncia só os marcos (4:00, 3:00, 2:00, 1:00, 30 s, 10 s); o contador visível é `role="timer" aria-live="off"`. Sem `style=`, sem cor fixa (fundo preto semitransparente = `.modal-backdrop` do Bootstrap; o tema não o altera). O checkout continua visível por baixo e fica `inert`. Fechar mostra o aviso compacto "Pix aguardando pagamento · MM:SS · Reabrir QR Code" (contador e polling seguem); fechar depois de um estado final mostra o desfecho no aviso (confirmado, expirado + "Tentar novamente"...). O foco volta ao botão "Reabrir QR Code" (o Bootstrap só devolve foco em modais abertos por data-api). Estados: gerando (spinner), aguardando, verificando, pago, expirado, cancelado/recusado, `review`, "ainda verificando" (limite do polling), indisponível. Cartão e o resto seguem no painel `#mpResult`; se a classe `Modal` faltar, o Pix cai nesse painel.
- **Acesso ao Modal:** `bootstrap.bundle` como módulo não cria `window.bootstrap` (mesma armadilha do admin, §10h). `resources/js/app.js` passou a importar o default do bundle e expõe só `window.StorefrontUI = { Modal }`; o script inline do checkout lê dali. Sem dependência nova.
- **Zero do contador:** o JS NÃO declara "expirado" sozinho. Em 00:00 mostra "Verificando o pagamento…" e consulta o status na hora (sem esperar o intervalo); só `cancelled` com `reason: expired` do servidor vira "O tempo para pagar o Pix acabou. O pedido foi cancelado. Tente novamente."; `paid` mostra confirmado. Servidor `pending` depois do zero (tolerância de 10 s, ou cancel que falhou e será refeito): continua "verificando" e consultando até a janela + 90 s, depois "Ainda verificando" com "Continuar verificando". Polling do Pix: ~3 s com backoff leve até 5 s (cartão: 4 s até 12 s, 10 min).
- **`checkout/status`:** pendente + Pix com janela devolve `status`, `pix` (se houver QR), `expires_at`, `server_now`; cancelado devolve `reason: expired` só para Pix cancelado/recusado depois de a janela fechar (cancelado pelo lojista ou recusado antes: sem motivo). Nada mais sai. 404 idêntico para outra sessão/tenant, `no-store` e o throttle continuam.

### Bug real encontrado pelo teste de concorrência (corrigido)

O teste de duas ou mais verificações simultâneas falhava em 2 de 3 rodadas: uma consulta LENTA ao MP (feita antes de o cancelamento acontecer) aplicava o "aguardando" DEPOIS que outro processo já tinha cancelado, e `PaymentWebhookProcessor::applyTransition` regredia `payments.status` de `cancelled` para `pending` (o pedido ficava cancelado e o estoque certo, mas o pagamento ficava `pending` para sempre: o evento `cancelled` já existia e a transição seguinte virava no-op). Vale também para webhook e polling atrasados em qualquer fluxo. Correção: um pagamento já decidido nunca volta a "em aberto" (`applyTransition` ignora a consulta atrasada). Testes: `PaymentConcurrencyTest` (5 processos PHP; 4 rodadas isoladas e 2 da suíte completa, todas verdes; antes da correção falhava em 2 de 3) e `PixExpiryTest::test_a_stale_waiting_lookup_...` (determinístico); sem a correção o segundo falha.

### Provado por teste

- **Janela/config** (`PixWindowTest`, `PixExpiryTest`): padrão 300, teto, piso, inválido, string do `.env`; independente do `PT30M`; `pix_expires_at` gravado e devolvido; arredondamento para cima; cartão não recebe janela.
- **Expiração** (`PixExpiryTest`, `Http::fake` no formato real de Orders): vencido + `action_required` → POST `/cancel` (sem corpo, com `X-Idempotency-Key`), pedido cancelado, estoque devolvido UMA vez, `status` = `cancelled` + `expired`; o pedido local ainda está pendente na hora do cancel no MP; vencido + MP pago → pago, sem `/cancel`; 409 `cannot_cancel_order` + reconsulta paga → pago; 409 `order_already_canceled` → cancelado uma vez; timeout, 500, 503, 401 → pedido pendente, estoque intocado, log, próxima rodada conclui; falha na consulta → nada cancelado; `in_process` → não cancela; cancel aceito mas ainda não refletido → pendente até refletir; webhook tardio = no-op; limites (−1 s, janela + 10 s exato, +11 s); gateway-agnóstico com o `FakeGateway` (sem tocar o MP).
- **Concorrência** (`PaymentConcurrencyTest`, 5 processos PHP): cancela e devolve estoque uma vez; Pix já pago nunca é cancelado.
- **Comando** (`ReconcilePaymentsCommandTest`): cancela Pix vencido sem navegador (criado há 6 min), segue a varredura geral sem duplicar, respeita pago, retoma após falha, ignora Pix dentro da janela/tolerância, cartão, Pix sem janela e ids numéricos antigos; o agendamento está registrado (a cada minuto, `--pix-only`, sem sobreposição).
- **Gateway** (`MercadoPagoGatewayTest`): 200, 409 (as duas causas), 400, 5xx/401/404/outro 409/timeout, ids antigos e sem credencial sem chamada HTTP, truncamento da chave a 128, sem corpo.
- **Front sem navegador** (`CheckoutPixModalMarkupTest`, `CheckoutDomSafetyTest`): modal no DOM com `id`, `role`, `aria-*`, `data-bs-backdrop="static"`, `data-bs-keyboard="false"`, botão fechar, `wire:ignore`, sem `style=`, sem cor fixa, sem "30 minutos"; o zero do contador não declara expiração; o prazo vem do servidor; contador em Node (MM:SS, correção por `server_now` com relógio do cliente adiantado/atrasado, clamp em 00:00, entradas inválidas) — **rodou no host (16/16)**, no container fica skipped.
- **E2E local com Chromium** (`page.clock`, SDK e API simulados, relógio pausado): abre sobre o checkout; fundo `rgb(0,0,0)` com opacidade entre 0,3 e 0,8; ESC e clique fora não fecham; foco preso (Tab); contador 05:00 → 04:00 → 03:30; Fechar → aviso com o mesmo contador e foco em "Reabrir QR Code"; reabrir; relógio do servidor 1 h à frente não altera o contador; no zero: "Verificando…", consulta imediata (entre o polling regular de ~56 s e ~61 s), servidor `pending` segue verificando, depois `expired`; pago no último segundo mostra confirmado; limite do polling (150 s) e "Continuar verificando". Capturas olhadas (desktop, celular 375 px e escuro; não versionadas).

### O que NÃO foi provado

- **Contra o MP real**, nada novo. Em especial: que `POST /v1/orders/{id}/cancel` funciona numa order Pix `action_required`/`waiting_transfer` em produção (a sandbox só provou o sucesso em order manual `created`; o 409 de `processing`/`processed`/já cancelada foi provado); que o QR de um Pix cancelado deixa de ser pagável no app do banco; que o MP devolve `canceled` na reconsulta logo após o cancel (sem atraso); o corpo real do 409 (usamos o código de `errors[]`).
- Webhook real do MP (nenhum chegou); com o webhook, o `order.canceled` que o cancelamento dispara é um no-op idempotente (provado só com `Http::fake`).
- Safari/Firefox, leitores de tela reais, teclado virtual do celular, `inert` em navegadores antigos, foco devolvido em todos os caminhos, aparência com o tema de um tenant real (as capturas usam o tenant do seed).
- O agendador rodando em produção (nenhum processo `schedule:run` existe neste repositório).
- A latência real: o `GET` + `POST` + `GET` do enforcer, com timeout de 30 s cada, pode segurar uma requisição de status por mais de 30 s se o MP estiver lento (o throttle impede rajada, mas não a requisição lenta).

### Como o dono valida em produção sem risco

1. Gere o Pix de R$ 6,50 no checkout, **não pague**, e olhe o contador (05:00) e o QR no modal; feche e reabra.
2. Espere passar 5 min + ~15 s com a aba aberta (ou fechada, se o `schedule:run` estiver ativo): o modal deve mostrar "O tempo para pagar o Pix acabou" e o pedido deve estar cancelado com o estoque devolvido.
3. Confirme no MP: `GET /v1/orders/{id}` (ou o painel) da order deve estar `canceled`. Se o MP responder `cannot_cancel_order`, a mensagem do log é `payment.pix_expiry.still_open`/`cancel_failed` e o pedido fica pendente (nunca cancelado à força).
4. (Opcional, 1 centavo de risco) Para ver o caminho "pago no último segundo", pague outro Pix a ~4:50: deve virar "Pagamento confirmado!", nunca "expirado".

### Achados novos (não corrigidos)

- `src/app/Services/PaymentService.php` (`reportUnconsolidated`/`adoptAttempt` em `PaymentWebhookProcessor.php:325`): cobrança aceita pelo MP mas não consolidada (resposta 202 `reconciling`) fica sem `pix_expires_at`, então a janela de 5 min não vale para ela (o pedido segue o prazo de 30 min do MP). Raro, mas é um buraco da regra; sanar exigiria gravar o prazo na adoção.
- `src/app/Gateways/MercadoPagoGateway.php:591` (`makeRequest`, captura em `:622`): continua só capturando `ConnectionException`; `cancelPayment` envolve a chamada em `Throwable`, mas `createPayment`/`getPayment` não.
- O enforcer faz até 3 chamadas ao MP por ciclo (consulta, cancela, reconsulta); com muitos Pix vencidos de uma vez o `--limit=200` do comando pode levar mais de um minuto (o `withoutOverlapping(10)` evita a sobreposição).

### Decisões que precisam do dono

1. **Agendador em produção:** confirmar que o `schedule:run` (cron a cada minuto) será ativado; é ele que libera o estoque de quem fecha a aba.
2. **Tolerância de 10 s** e **janela de 5 min** como padrão (o teto é 300 por requisito; reduzir é só configurar).
3. **Pix sem QR após a janela** (order `processing`, caso da sandbox): hoje o pedido fica pendente até o MP expirá-la (30 min), porque o MP não cancela `processing`. Decidir se, nesse caso, vale cancelar só localmente (devolve o estoque, mas arrisca um pagamento tardio sobre pedido cancelado, que cairia no incidente `paid_on_cancelled_order`).
4. Pix de antes do §16 (sem janela gravada) continuam com o prazo de 30 min; não há migração de dados.

## 17. Vencimento do Pix: pedido cancelado e estoque devolvido, sempre

Requisito do dono: "ao vencimento do prazo para pagamento, o pedido é cancelado e consequentemente o estoque devolvido". A §16 cobria o Pix que tem `pix_expires_at` e que o MP deixa cancelar. Faltavam quatro caminhos em que o pedido ficava preso, mais o processo que executa a varredura.

| Caminho | Antes | Agora |
|---|---|---|
| Sem processo de agendamento | a varredura por minuto nunca rodava (só o cliente consultando o status impunha o prazo) | serviço `scheduler` no compose (`php artisan schedule:work`, `restart: unless-stopped`); provado: executou `payments:reconcile --pix-only` sozinho em 2 s |
| Pix sem `pix_expires_at` (cobrança não consolidada ou anterior à janela) | nunca vencia | vence `created_at + janela + tolerância` (`PixWindow::isDueFor`); só Pix por gateway, nunca cartão |
| Order `processing` sem QR (o MP não cancela esse estado) | pendente até o MP expirar | se **nunca** houve QR guardado (nada pagável foi mostrado), cancela localmente e devolve o estoque; com QR, só o estado do MP decide |
| Order inexistente para as credenciais atuais (404 `order_not_found`, ex.: trocou teste por produção) | pendente para sempre, com aviso a cada minuto | `ReportsMissingPayments::paymentIsMissing`: só 404 + `order_not_found` é definitivo; 5xx, timeout, 401 e 404 sem esse código nunca cancelam |

Mutações (cada uma derruba o teste esperado): sem o ramo "nunca teve QR", cancelar mesmo com QR exibido, sem o fallback por `created_at`, e cartão vencendo pela janela do Pix.

Efeito medido no banco de dev: com o agendador de pé, os 5 pedidos Pix presos (#21, #22, #25 da sandbox; #26 e #27 de produção) foram cancelados sem intervenção, e não restou pagamento em aberto.

**Limites:** se o MP aprovar depois de um cancelamento local (sem QR, ou `order_not_found` sob outra conta), o webhook/reconciliação registra `paid_on_cancelled_order` e é preciso reembolsar à mão. Cobrança aceita pelo MP mas sem `gateway_payment_id` (resposta perdida) continua fora da varredura, que exige o id para consultar. Em produção o agendador precisa existir: este serviço, ou `* * * * * php artisan schedule:run`.

## 18. Auditoria da sprint contra o código (2026-11-08)

Revisão pedida pelo dono: conferir cada critério de `02-sprint.md` §6 contra o **código e o banco de hoje**, e não contra o que este log dizia. Resultado: **22 de 25 satisfeitos, 3 parciais, 0 abertos.** Houve **um rebaixamento** (#22) e **quatro correções de documentação**; nenhum critério voltou a "aberto".

### Medido nesta auditoria

| # | Critério | Veredito | Evidência (reproduzível) |
|---|---|---|---|
| 1 | Projeto versionado e no remoto | ✅ | `git log origin/main` = `7368ba8`; CI verde nesse commit (run `42512498981`) |
| 2 | CI obrigatório e verde (+Playwright) | ⚪ dispensado (2026-11-10) | CI verde com Pint, PHPStan 5 e PHPUnit em PostgreSQL. **Sem Playwright** (decisão do dono) e **sem branch protection** (`gh api .../protection` → 404) |
| 3 | Nenhuma query de model ignora o tenant sem opt-out | ✅ (fechado em §20) | leitura filtrada, escrita por instância barrada (`CrossTenantWriteException`), re-hidratação do Livewire escopada; `TenantWriteIsolationTest`, `ForgedSnapshotAcrossTenantsTest`, `TenantIsolationAuditTest`; mutações derrubam os testes |
| 4 | Nenhuma action de admin sem `authorize()` | ✅ | `LivewireAdminAuthorizationAuditTest` **morde**: adicionei `sneakyDelete()` sem `authorize` e ele falhou nomeando o método |
| 5 | `tenant_staff` removido, `Role` com escopo | ✅ | só restam comentários e testes que provam que ele **não** é aceito |
| 6 | `users.tenant_id` com FK e nullable | ✅ | `information_schema`: `is_nullable=YES`; 1 FK sobre a coluna |
| 7 | Global scope nos 3 contextos | ✅ | `TenantScopeTest`, `TenantContext` (150 testes de isolamento/auth passam) |
| 8 | Nenhum middleware de auth deixa passar | ✅ | `RouteMiddlewareAuditTest` **morde**: rota `/{tenant}/rota-sem-middleware` falhou a auditoria |
| 9 | FKs em tudo; pivôs com `tenant_id` | ✅ | 25 FKs; 0 tabelas com `tenant_id` sem FK; `category_product` e `showcase_product` com `tenant_id`. **Ressalva mantida:** `showcases.category_id`, `orders.customer_id`, `order_items.product_id` sem FK composta |
| 10 | `track_inventory` opt-in com concorrência | ✅ | `InventoryConcurrencyTest` (processos PHP separados) |
| 11 | Checkout ponta a ponta com gateway fake | ✅ | `FakeGateway`; HTTP/serviço, não navegador |
| 12 | `TYPE_ALL` respeita `max_items` | ✅ | testes de limite e teto 100 |
| 13 | `/` landing sem tenant hardcoded | ✅ | `LandingTest` |
| 14 | Colunas monetárias em `bigint` | ✅ | 9 colunas `*_cents`, todas `bigint`, 9 CHECKs `>= 0` |
| 15 | Status como enum com CHECK | ✅ | `orders_status_valid`, `orders_payment_status_valid`. `payments.status` fica string **de propósito** (guarda o bruto do gateway) |
| 16 | Carrinho em um lugar só | ✅ | nenhum acesso a `cart` na sessão fora do `CartService` |
| 17 | Webhook idempotente, assinatura antes de consultar | ✅ | `WebhookIdempotencyTest`, `OrdersWebhookTest`, `PaymentConcurrencyTest` (concorrência real). Exceção documentada: rota `/webhooks/{gateway}` sem tenant |
| 18 | Nenhuma credencial em claro no Livewire | ✅ | `PaymentSettingsWebhookGuideTest`, `payment-secrets` (E2E local) |
| 19 | Componentes Blade; ≤ 5 `style=` | ✅ | **1** `style=` (era 49); 20 componentes, 445 usos |
| 20 | Tailwind removido; CDNs no Vite | ✅ com ressalva | Tailwind fora. Externos que restam: SDK do MP, `security.js` do MP, gtag e **Google Fonts** (4 fontes de uma allow-list, carregadas dinamicamente por `TenantTheme::googleFontUrl`). O critério vale para script/CSS, **não** para fontes |
| 21 | ≥ 40 testes de feature; ≥ 8 de isolamento | ✅ | 711 métodos em `tests/Feature`; 28 com nome de isolamento |
| 22 | E2E do checkout verde | ⚪ dispensado (2026-11-10) | Passava como ✅ no placar. Existe e roda (137 testes regulares), mas **só na máquina do dono**; o critério o exigia como gate e o CI não o roda. Honestamente é parcial |
| 23 | Itens herdados com status explícito | ✅ | `06-inherited-items.md`, **atualizado nesta auditoria** (4c, 14, 22, 27 mudaram) |
| 24 | README reflete a realidade | ✅ | cobre Orders, webhook, Pix, `TRUSTED_PROXIES`, `scheduler`, Device ID; **faltava** citar o nome `quitanda.app`, corrigido |
| 25 | Decisões de produto registradas | ✅ | `04-decisions.md` |

### Defeitos da própria documentação, achados e corrigidos

1. **Bloco de status duplicado e falso** no topo deste arquivo (linhas "4 de 25", "18 abertos", "nenhum item de F2 foi iniciado"): sobra de uma edição por substituição de texto. Contradizia o placar e afirmava o contrário do que existe. Removido.
2. **Números velhos:** FKs 22 → 25, Pint 262 → 283, testes 1318 → 1492, E2E "122" → 137. O "122" mistura-se com os 184 testes de varredura do tema que o `playwright.config.ts` passou a incluir por engano.
3. **`06-inherited-items.md` afirmava o contrário do código** no item 4c ("não existe `app/Console`, comando nem `Schedule::`"). Existem. Corrigido junto com 14, 22 e 27.
4. **"não commitado"** nas seções já enviadas ao remoto.

### Defeitos de código/teste achados pela auditoria

- **`playwright.config.ts`:** `npm run e2e` rodava 321 testes, 184 deles de varredura pontual do tema. Corrigido: `scan/` só entra com `E2E_SCAN=1`.
- **Teste que passava sem provar nada (meu):** o primeiro `ForgedSnapshotAcrossTenantsTest` adulterava `data.order[0]` (o campo errado) e passava mesmo sem `authorize()`. Detectado por mutação, reescrito: agora cada teste prova **uma** defesa, e a policy morde (sem a comparação de tenant, falha, e a suíte de autorização inteira pega a mesma mutação: 9 falhas).
- **Item 11 do inherited confirmado por execução, não por leitura:** `OrderService::createFromCart` com carrinho vazio **cria um pedido de total 0 e 0 itens**. Segue aberto (os chamadores protegem; o serviço não).

### O que esta auditoria NÃO provou

- A concorrência e o E2E rodam aqui; **não** foram vistos em outro ambiente além do runner do CI (concorrência) e da máquina do dono (E2E).
- Safari/Firefox, leitor de tela, Pix real em produção (parcialmente: o dono confirmou o QR; o **cancelamento** da order Pix no MP segue sem prova real).
- `#9` e `#3` seguem com as ressalvas acima; a auditoria não as fecha.

## 19. Itens 11 e 41: carrinho vazio, retirada e entrega com taxa, bairro e pedido mínimo (2026-11-08)

Pedido do dono: os dois itens herdados abertos mais importantes. **Item 11:** `createFromCart` criava pedido com total 0 e 0 itens (confirmado por execução na auditoria do §18). **Item 41:** o admin oferecia pedido mínimo, taxa de entrega, frete grátis e tempo estimado, mas o checkout só gravava `fulfillment_type = pickup` e taxa 0.

**Escopo definido pelo dono:** liga/desliga de **retirada** e de **entrega**, **taxa adicional na retirada**, **taxa de entrega por bairro cadastrável (nome + preço)**, pedido mínimo para todo pedido. (Interpretação registrada: "IO" = liga/desliga.)

| Regra | Onde |
|---|---|
| Carrinho sem nada para comprar (vazio, produto inexistente/inativo/de outro tenant, quantidade 0) lança `EmptyCartException` antes de qualquer escrita | `OrderService` |
| Padrão sem configuração = como sempre foi: só retirada, sem taxa, sem mínimo | `FulfillmentService` |
| Mínimo: sobre o **subtotal**, antes de qualquer taxa, vale para retirada e entrega | `FulfillmentService::quote` |
| Retirada: taxa adicional própria. Entrega: taxa do bairro escolhido; sem bairro ativo, a taxa padrão | idem |
| Frete grátis: subtotal >= limite zera a taxa de **entrega** (limite 0 = desligado), nunca a de retirada | idem |
| Entrega exige endereço (rua/número, cidade, UF; complemento e CEP opcionais) e bairro quando há bairros ativos | `validateAddress`, `quote` |
| Tudo desligado cai em retirada (não trava a loja) | `availableTypes` |

**Segurança de dinheiro:** o cliente só envia tipo, bairro e endereço. Taxa, mínimo e total são **sempre recalculados no servidor** ao gravar o pedido (`OrderService`), e o `PaymentService` cobra `order.total_cents`, então o valor enviado ao gateway inclui a taxa. Teste: campos `fee_cents`/`total_cents`/`total` enviados pelo cliente são ignorados. Recusas (mínimo, tipo, bairro, endereço, carrinho vazio) acontecem antes de gravar cliente, estoque ou número do pedido.

**Idempotência do pagamento:** `checkoutKey` passou a incluir tipo, bairro e endereço. Outro bairro ou endereço é outro pedido (outro total e outro destino); reaproveitar o pedido anterior entregaria no endereço errado.

**Dados:** `orders.delivery_fee_cents` guarda a taxa do recebimento escolhido (de entrega **ou de retirada**; o tipo e a origem ficam em `meta.fulfillment`, junto do nome do bairro: snapshot, a loja pode mudar os preços depois). `orders.fulfillment_type` ganhou CHECK (`pickup` | `delivery`) e o cast `FulfillmentType` (migração aditiva `2026_11_08_000001`, já aplicada no banco de dev: 22 pickup e 5 delivery, todos válidos). Os bairros ficam em `tenants.settings.delivery.regions` (JSONB, até 100, com id estável para os pedidos antigos).

**Telas:** admin "Retirada e Entrega" (interruptores, taxa de retirada, taxa padrão, frete grátis, tempo estimado, bairros com nome, preço e ativo; ao menos uma forma ligada; nomes de bairro sem repetição); checkout (escolha, bairro, endereço com cidade/UF pré-preenchidas, resumo com subtotal, taxa e total, aviso e botões bloqueados abaixo do mínimo; loja só de retirada **não vê a seção nova**); carrinho (aviso e botão bloqueado); cabeçalho da loja (taxa "a partir de", tempo e mínimo, só se a loja entrega); telas de pedido do admin (rótulo da taxa, bairro, endereço).

**Provas:** `FulfillmentServiceTest` (20), `OrderFulfillmentTest` (16), `OrderServiceTest` (+6), `CheckoutFulfillmentTest` (14), `ProcessPaymentFulfillmentTest` (11), `DeliverySettingsFulfillmentTest` (12), `CartMinimumAndHeaderTest` (6), `CarolDocesFulfillmentSeedTest` (2). 7 mutações (mínimo desligado, taxa fora do total, endereço sem validar, carrinho vazio aceito, chave ignorando o recebimento, frete grátis ignorado, mínimo contando a taxa) derrubam de 2 a 7 testes cada.

**Defeitos achados pelos próprios testes durante o trabalho:** (1) a seção "Como você quer receber?" aparecia também para lojas só de retirada, mudando a tela de todo tenant existente; agora só aparece havendo escolha ou entrega única. (2) o cast para enum faria as telas de pedido do admin compararem `=== 'delivery'` com um enum e mostrarem sempre "Retirada"; corrigido. (3) o cabeçalho da loja passou a depender do interruptor de entrega, então um teste antigo que montava taxa sem `enabled` precisou declarar a entrega ligada.

**Não provado / fora de escopo:**
- Nada disto foi visto num navegador real, e o E2E local (`src/e2e/`) **não foi atualizado**: os specs de checkout seguem válidos para o fluxo de retirada (padrão), mas não cobrem a entrega.
- Agendamento de entrega (`scheduled_for`), áreas por CEP ou distância, e cálculo de frete por transportadora: fora do escopo.
- A tela de admin "Retirada e Entrega" substitui a antiga "Configurações de Entrega" na mesma rota; o item de menu continua rotulado "Entrega".
- O seeder da Carol Doces só preenche bairros de demonstração se a loja ainda não tem essa configuração; **não foi executado no banco de dev**, então a loja de desenvolvimento continua só com retirada até o dono ligar a entrega no admin.

## 20. Critério #3 fechado: isolamento de tenant em escrita e re-hidratação (2026-11-10)

Decisão do dono (2026-11-10): **#2 e #22 deixam de ser obrigatórios** (CI com Playwright, branch protection e E2E como gate); **#3 é vital**, porque diz respeito ao isolamento do tenant. Até aqui o #3 era "parcial com limite documentado": o global scope só filtrava LEITURA, e a escrita por instância e a re-hidratação do Livewire ignoravam o escopo.

| Caminho | Antes | Agora |
|---|---|---|
| Leitura por query builder | filtrada pelo `TenantScope` | igual |
| `save/update/delete/restore/forceDelete` de instância | **sem barreira** (`newModelQuery()` não aplica scopes) | `assertWritableInCurrentTenant()` em `saving`, `deleting`, `restoring`: com tenant no contexto, linha (atual ou original) de outro tenant lança `CrossTenantWriteException` |
| Mover uma linha para outro tenant (`tenant_id` trocado) | possível | recusado (confere o `tenant_id` original) |
| Criar já em outro tenant com `tenant_id` explícito | possível | recusado |
| `public Model $x` do Livewire re-hidratado do snapshot | `newQueryForRestoration` = **sem scopes**; só o checksum protegia | sobrescrito: restaura pelo escopo e pelo soft delete; outro tenant ou registro apagado dá **404**, mesmo com checksum válido |
| Model novo com `tenant_id` sem a trait, opt-out novo, SQL cru novo | nada impedia | `TenantIsolationAuditTest`: listas fechadas e justificadas (`TenantCounter` e `User` sem a trait; 2 opt-outs; 2 usos de SQL cru) |

**Provas** (`TenantWriteIsolationTest` 39, `ForgedSnapshotAcrossTenantsTest` 5, `TenantIsolationAuditTest` 4):
- 5 models (Product, Category, Customer, Showcase, Order) × update, delete, mover, criar em outro tenant: todos recusados e a linha intacta; restore e forceDelete de soft delete também;
- o mesmo tenant escreve normalmente; sem tenant no contexto (webhook, seeder, job, comando) e no contexto de plataforma nada muda; `runFor` troca a fronteira junto com o tenant;
- snapshot com checksum **válido** (gerado por `Livewire\Mechanisms\HandleComponents\Checksum::generate`) apontando para o pedido de outro tenant: 404 na hidratação, pedido intacto; o controle com o pedido do próprio tenant funciona;
- 4 mutações derrubam testes: re-hidratação sem escopo (6 falhas), sem a barreira de escrita (22), barreira só em `saving` (6), ignorando o tenant original (5). `TenantIsolationAuditTest` também morde: um opt-out escondido num service e um model novo sem a trait o derrubam.

**Efeito colateral bom:** o `newQueryForRestoration` escopado também resolve o achado antigo "formulários Livewire que podem salvar sobre um registro já excluído" (o soft delete passa a valer na hidratação).

**Testes antigos que codificavam o comportamento anterior** e foram ajustados (não a regra): `TenantScopeTest` criava linhas do tenant B com o contexto em A (agora o setup roda sem contexto ou em `runFor(B)`, e o teste "tenant_id explícito" passou a provar a recusa); `PaymentReconciliationTest` cadastrava uma loja nova com o contexto da requisição anterior (em produção cada requisição tem o seu contexto).

**Ressalvas honestas (o que o #3 ainda NÃO é):**
- Sem tenant no contexto nada é barrado (plataforma, job, seeder, comando, webhook). É decisão de arquitetura (D2) e está na trait; quem grava assim usa `runFor()`. O painel de plataforma da Sprint 07 deve usar `runForPlatform` de forma explícita.
- SQL cru e os 2 opt-outs seguem fora da barreira; estão justificados e travados por teste, mas continuam dependendo de revisão humana ao serem criados.
- Autorização continua sendo outra camada (policies e guards). O isolamento barra o acesso entre lojas; "este ator pode escrever aqui" é decidido por elas.
- A barreira lança exceção em runtime: uma regressão aparece como erro 500 (e log), não como dado vazado. É o comportamento desejado, mas um caminho legítimo mal contextualizado quebraria.

