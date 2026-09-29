# Registro de Desenvolvimento — Sprint 06

**Sprint:** 06-refactor
**Status:** 🟡 **EM ANDAMENTO** — 4 de 25 critérios de conclusão satisfeitos (16%), 3 parciais
**Última atualização:** 2026-09-29

> Log de execução escrito **durante** o trabalho, conforme a convenção das sprints 01–05 (`03-dev.md`) e o requisito de `02-sprint.md` §9: para cada entregável, registrar **o teste que prova a correção** e o resultado antes/depois.
>
> **Não é um relatório de encerramento.** Os blocos 3 a 10 não foram iniciados.

---

## 1. Status geral

| | Início da sprint | Agora |
|---|---|---|
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

### Bloco 2 — restante
| Item | Status |
|---|---|
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
