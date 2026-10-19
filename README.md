# order-web

Plataforma SaaS multi-tenant de lojas white label. Cada loja (tenant) tem storefront, carrinho, checkout com pagamento online ou manual e um admin próprio. O caso de uso inicial é loja de encomendas (a demo é a doceria "Carol Doces"); a direção é atender mais de uma vertical, ver abaixo.

Este arquivo descreve o sistema como ele está hoje. O histórico de decisões e o log de cada sprint estão em `.sprint/` (ver o fim do arquivo).

## Direção do produto

Registrada em `.sprint/06-refactor/04-decisions.md`:

- Plataforma multi-vertical: vendas sob encomenda e vendas com estoque (opt-in por produto).
- Administração por tenant e, no futuro, admin de plataforma (papel `platform_admin` já existe no enum; o painel e a impersonation são da sprint 07).
- Múltiplos gateways de pagamento configuráveis por tenant. Hoje existem `manual` e `mercadopago`.
- Planos e capabilities por tenant virão em colunas/tabelas explícitas, não em `tenants.settings` (D6).
- A raiz `/` é a landing da plataforma. Não há loja na raiz.

## Stack

- PHP 8.4, Laravel 12, Livewire 3
- PostgreSQL 16 (jsonb, índices parciais e `SELECT ... FOR UPDATE` são usados de verdade: SQLite não é suportado)
- Bootstrap 5.3, Font Awesome, Splide e SortableJS empacotados pelo Vite (sem CDN; só o SDK do Mercado Pago, o gtag e o Google Fonts são externos)
- Docker Compose: `app` (php-fpm), `nginx`, `db`, `db_test`, `redis`, `mailpit`
- Qualidade: Pint, PHPStan (Larastan) nível 5, PHPUnit, Playwright (E2E e regressão visual), GitHub Actions

## Como rodar

Pré-requisitos: Docker e Node 22 no host (o container PHP não tem Node; os assets são compilados no host).

```bash
docker compose up -d
cp src/.env.example src/.env
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
(cd src && npm ci && npm run build)
```

Acessos locais:

| O quê | Onde |
|---|---|
| Landing da plataforma | http://localhost:8080/ |
| Loja demo | http://localhost:8080/caroldoces |
| Admin da loja demo | http://localhost:8080/caroldoces/admin/login |
| Mailpit (e-mails de dev) | http://localhost:8025 |
| PostgreSQL de dev | `localhost:5433` (db `wl`, user `wl`, senha `wl`) |
| PostgreSQL dos testes | `localhost:5434` (db `wl_test`, tmpfs, descartável) |
| Redis | `localhost:6379` |

Login do admin de demo: `admin@caroldoces.com` / `password`. Essas credenciais existem só no seed de desenvolvimento.

`.env.example` já aponta para o Postgres do compose (`DB_HOST=db`). Em produção troque todas as credenciais e defina `APP_DEBUG=false`.

## Como testar

```bash
docker compose exec app php artisan test        # suíte inteira, contra o PostgreSQL `db_test`
docker compose exec app composer lint           # Pint (--test)
docker compose exec app composer format         # Pint (aplica)
docker compose exec app composer analyse        # PHPStan nível 5
```

- A suíte usa o serviço `db_test` (definido em `src/phpunit.xml`), nunca o banco de dev, porque `RefreshDatabase` apaga os dados. Vários testes abrem processos PHP separados contra o Postgres para provar concorrência (estoque, pagamentos, limite de vitrines).
- Um teste de Node (validações puras do checkout) fica `skipped` no container, que não tem Node; ele roda no host.
- O baseline do PHPStan (`src/phpstan-baseline.neon`) só pode diminuir. Se precisar aumentá-lo, justifique no PR.
- Regra da sprint 06: nenhuma correção é dada como pronta sem um teste que falhe antes e passe depois.

CI (`.github/workflows/ci.yml`): em push na `main` e em pull requests roda `composer install`, build dos assets, `composer lint`, `composer analyse` e `php artisan test` com PostgreSQL 16 como service. O CI faz `cp .env.example .env` e sobrescreve só `DB_HOST`/`DB_PORT`. O Playwright **não roda no CI** (ver "Testes E2E"). Ainda não há branch protection.

## Testes E2E

> **Só local.** A pasta `src/e2e/` (specs, snapshots e suporte) está no `.gitignore` por decisão do dono do projeto: não vai para o repositório nem para o CI, e um clone novo não a terá. Tudo abaixo vale para quem já tem a pasta. `playwright.config.ts`, `bin/e2e.sh`, o `E2eSeeder` e o profile `e2e` do compose continuam versionados, mas sem `src/e2e/` não há o que rodar. Consequência: **a regressão visual deixou de ter proteção automática**.

Playwright (versão exata em `src/package.json`) cobre o que os testes Livewire não alcançam: o **fluxo de ponta a ponta no navegador** (checkout, login do admin, drag-and-drop, tema, offcanvas, carrossel, popup) e o **congelamento visual do design system** (snapshots de ~70 telas do storefront e do admin, desktop e mobile). Regra de negócio continua nos testes PHPUnit/Livewire: não duplique aqui.

### Como rodar (um comando)

```bash
cd src
npm run e2e                      # build dos assets + servidor isolado + seed + testes
npm run e2e -- --grep "checkout" # argumentos extras vão para o `playwright test`
npm run e2e:report               # abre o último relatório HTML
```

Pré-requisitos: Docker, Node 22+ no host e `composer install` feito (o `e2e-app` monta `src/vendor`). O script `bin/e2e.sh` faz, em ordem: `npm run build`; sobe `db_test` e `e2e-app` (profile `e2e` do compose); cria o banco `wl_e2e`, migra e semeia com o `E2eSeeder`; guarda uma cópia limpa (`wl_e2e_tpl`); roda os testes **dentro da imagem oficial do Playwright** (`mcr.microsoft.com/playwright:v<versão>-jammy`, a mesma versão do `package.json`); e, ao final (mesmo com falha), derruba o servidor e apaga os bancos do E2E.

Laço de desenvolvimento (sem rebuild/seed a cada execução):

```bash
E2E_KEEP_UP=1 npm run e2e                 # deixa o servidor e o banco de pé
E2E_SKIP_SETUP=1 npm run e2e -- admin-js  # roda só os testes contra o ambiente de pé
docker compose --profile e2e rm -sf e2e-app   # derruba depois
```

Outras variáveis: `E2E_SKIP_BUILD=1` (reusa `public/build`). `npm run e2e` sobrescreve `src/public/build` (é o mesmo build do desenvolvimento).

### Isolamento (nunca toca `wl` nem `wl_test`)

- **Servidor**: serviço `e2e-app` (`php -S`, porta de host **8081**; não é o `nginx` da 8080). Uploads, logs e cache de framework dele ficam em tmpfs.
- **Banco**: `wl_e2e` dentro do `db_test` (tmpfs, descartável). O `wl` (demo da Carol Doces) e o `wl_test` (PHPUnit) não são acessados. O `E2eSeeder` recusa qualquer banco cujo nome não termine em `_e2e` e **não** está no `DatabaseSeeder`.
- **Dados**: `E2eSeeder` cria duas lojas (`loja-e2e` e `loja-b-e2e`) com ids, slugs e datas fixos; admins `admin@loja-e2e.test` / `admin@loja-b-e2e.test`, senha `e2e-password`. Mercado Pago **não** configurado (nenhuma chamada externa); só dinheiro e PIX manual habilitados.
- **Independência**: cada teste restaura o banco a partir do template (`DROP/CREATE ... TEMPLATE`, ~100 ms), então não há dependência de ordem.

### Determinismo visual

Fonte `system` (sem Google Fonts), locale `pt-BR`, UTC, tema claro, `reducedMotion`, viewports fixos (desktop 1280x800, mobile 390x844) e **toda requisição externa é abortada** (Google Fonts, gtag, SDK do Mercado Pago, wa.me) e registrada. O relógio **não** é congelado (testado e descartado: o fake clock do Playwright quebra o SortableJS e `Carbon::setTestNow` apaga uploads do Livewire): os dados têm datas absolutas, nenhuma tela renderiza `now()`, a loja só fecha pelo botão (`auto_close` desligado) e o único valor variável, o ano do rodapé, é mascarado.

### Snapshots visuais

Ficam em `src/e2e/__screenshots__/<projeto>/<spec>/<nome>.png` (**não versionados**), e **só valem se gerados no container oficial** (renderização de texto muda entre macOS e Linux). Por isso o `npm run e2e` já roda neles; não rode `npx playwright test` direto no host.

```bash
cd src
npm run e2e:update              # regrava TODOS os snapshots (compare com os PNGs anteriores antes de aceitar; sem git, guarde uma cópia)
npm run e2e:update -- visual/admin.spec.ts   # só um arquivo
```

Tolerância (`src/playwright.config.ts`): `threshold: 0.02` e `maxDiffPixelRatio: 0.0002`. Está apertada de propósito: com `threshold: 0.1` e 0.1% de pixels (valores usuais), tirar `shadow-sm` do `<x-card>` **passava** na suíte; com estes valores falha em 54 de 67 testes visuais.

**Política de atualização**

1. Snapshot que falha é um **diff para revisar** (o CI sobe `*-expected/-actual/-diff.png` como artefato), não um número para "atualizar até passar".
2. Só atualize quando a mudança visual for **intencional** (ex.: novo componente, novo espaçamento do design system). Abra o diff do PR e confirme tela por tela.
3. Nunca rode `e2e:update` para "fazer o CI passar" sem olhar o que mudou; nunca afrouxe `threshold` para esconder uma diferença.
4. Mudança de dados do `E2eSeeder` ou de versão do Playwright/da imagem também muda os snapshots: regrave **no mesmo PR** e explique.

### Bugs conhecidos são `test.fail`

O que o navegador revelou e ainda **não foi corrigido** está em testes marcados com `test.fail(true, 'BUG: ...')` (o teste descreve o comportamento correto e hoje falha por causa do bug). Quando alguém corrigir, o teste passa a "falhar" ("expected to fail but passed"): remova a anotação e ele vira regressão normal. Procure por `BUG:` em `src/e2e`.

### Estrutura

```
bin/e2e.sh                     orquestração (build, servidor, banco, seed, container do Playwright)
src/playwright.config.ts       config (2 projetos: desktop e mobile)
src/e2e/specs/*.spec.ts        comportamento (checkout, estoque, validação, admin, isolamento, JS, segredos)
src/e2e/visual/*.spec.ts       snapshots (storefront e admin)
src/e2e/support/               fixtures (reset do banco, bloqueio de rede), db, env, helpers
src/e2e/__screenshots__/       baseline visual (versionado)
src/database/seeders/E2eSeeder.php
```

## Arquitetura multi-tenant

- O tenant é resolvido pelo path: `/{tenant:slug}` (loja) e `/{tenant:slug}/admin` (admin). Domínio próprio por tenant não existe.
- `ResolveTenantFromPath` carrega o tenant e o grava em `App\Support\TenantContext`, registrado como `scoped` (uma instância por requisição; seguro em Octane e testável). O contexto tem três estados: tenant, plataforma e indefinido.
- Os 8 models de negócio (`Category`, `Customer`, `CustomerAddress`, `Order`, `OrderItem`, `Payment`, `Product`, `Showcase`) usam a trait `BelongsToTenant`: global scope por `tenant_id` e preenchimento automático do `tenant_id` ao criar. `User` e `TenantCounter` ficam fora do scope de propósito (ver o docblock da trait).
- Sem contexto (webhook, seeder, job, artisan) o scope não filtra e não lança exceção. Endurecer esse estado é uma mudança só em `TenantScope::apply()`.
- As ~57 chamadas explícitas `->forTenant($id)` continuam como defesa em profundidade; removê-las (E1.2) foi adiado.
- Banco: toda FK do schema existe; os pivôs `category_product` e `showcase_product` têm `tenant_id` e FK composta `(tenant_id, X_id)`, então o banco rejeita vínculo entre tenants. Ainda aceitam referência cross-tenant (FK só por `id`): `showcases.category_id`, `orders.customer_id` e `order_items.product_id`.

### Limites do global scope

O scope é um filtro de leitura por query builder, nada mais. Ele NÃO protege:

- escrita por instância (`$model->save()`/`delete()` usam `newModelQuery()`, sem scopes);
- propriedades Livewire hidratadas (`public Model $x` é restaurado sem scopes);
- uma ação que não verifica quem é o ator.

Trate-o como defesa contra consulta esquecida, nunca como prova de isolamento. Toda escrita precisa de checagem própria (Policies, `AuthenticatesTenantCustomer`).

### Auditoria de acessos cross-tenant

Todo opt-out é greppável e deve estar justificado no código:

```bash
grep -rn "withoutTenantScope\|runForPlatform\|usePlatformScope" src/app
```

Hoje existe um uso: `PaymentWebhookProcessor` lê `payments.tenant_id` na rota de webhook sem tenant (marcado `CROSS-TENANT DELIBERADO`). Os testes `RouteMiddlewareAuditTest` (toda rota resolve tenant, salvo uma allowlist explícita) e `LivewireAdminAuthorizationAuditTest` (toda ação pública de admin autoriza) travam as regras estruturais.

## Autenticação e autorização

- Dois guards próprios, ambos com chave por tenant (login simultâneo em dois tenants no mesmo navegador funciona):
  - `tenant_admin` (`App\Auth\TenantAdminGuard`): sessão do Laravel; revalida tenant e papel a cada leitura do usuário. Usuários em `users`, papel em `users.role`.
  - `customer` (`App\Auth\CustomerSessionGuard`): cookie `customer_session_{tenant_id}` com token revogável. Clientes ficam em `customers`, não em `users`.
- Papéis (`App\Enums\Role`): `platform_admin` (sem tenant; `users.tenant_id` é nullable de propósito) e `tenant_admin`. `tenant_staff` foi removido.
- Autorização por Policies (`app/Policies`) sobre `Role::permissions()`; centralizada em um lugar. Toda ação pública dos componentes Livewire de admin chama `authorize()`, e a trait `AuthorizesOnHydrate` reautoriza a cada `POST /livewire/update`.
- O Livewire não reexecuta o middleware da rota: os middlewares de auth estão registrados como persistentes em `AppServiceProvider`. Sem isso, ações de admin passariam sem sessão (isso já aconteceu e está coberto por teste).
- Cookies: `HttpOnly`, `SameSite=Lax`; a flag `Secure` vem de `SESSION_SECURE_COOKIE` e, se omitida, de `APP_ENV=production` ou requisição HTTPS.

## Pagamentos

- Gateways plugáveis: `PaymentGatewayInterface` + `PaymentGatewayManager` (resolve por tenant e método; `extend()` registra um novo). `TenantPaymentController`, `PaymentService` e o processador de webhook só conhecem a interface. Existe `MercadoPagoGateway`; os métodos `manual` (dinheiro/cartão na entrega, PIX chave) não passam por gateway.
- Credenciais do Mercado Pago são por tenant, cifradas em `tenants.settings` e write-only no admin (o segredo nunca volta ao HTML nem ao estado do Livewire; a máscara é calculada no servidor).
- Pedido e tentativa de pagamento (`payments`) nascem numa transação curta, sem transação aberta durante a chamada HTTP ao gateway. Duplo submit é resolvido por `UNIQUE (gateway, idempotency_key)`. Falha do gateway cancela o pedido e devolve o estoque uma vez.
- Webhook: `POST /webhooks/{gateway}/{tenantId}` (URL enviada como `notification_url`) e `POST /webhooks/{gateway}` (compatibilidade). A assinatura é validada com o secret do tenant antes de qualquer consulta a pedido, pagamento ou API. O status vem sempre da consulta à API do gateway, nunca do corpo do webhook.
- Idempotência: tabela `payment_webhook_events` (UNIQUE `(gateway, gateway_payment_id, status)`) mais `lockForUpdate` em pedido e pagamento. Reenvios e webhooks simultâneos produzem uma única transição.
- Sem secret configurado: produção falha fechado (401); o bypass só vale em `local`/`testing`.
- Ainda não há fila, retry próprio nem dead-letter para webhooks, nem reconciliação periódica de pagamentos pendentes; quando a API do gateway está fora, o endpoint responde 503 e depende do reenvio do próprio gateway.
- `MERCADOPAGO_INTEGRATOR_ID` (opcional) define o header `x-integrator-id`. É um identificador de integrador, não uma credencial; o default está em `config/services.php`.

## Estoque

Opt-in por produto (D1): `track_inventory` (padrão `false`) e `stock_quantity`. Sem controle, o comportamento é de encomenda (sem validação nem baixa). Com controle, `OrderService::createFromCart` trava os produtos com um único `SELECT ... FOR UPDATE ORDER BY id`, valida e baixa dentro da transação; falta de saldo lança `InsufficientStockException` e nada é gravado. Cancelar o pedido devolve o estoque uma única vez.

## Modelo de dados em pontos

- Valores monetários em centavos (`*_cents`, `bigint`, `CHECK >= 0`).
- Status como enum (`OrderStatus`: `pending`, `confirmed`, `done`, `cancelled`; `PaymentStatus`: `unpaid`, `paid`, `refunded`) com cast no model e `CHECK` no PostgreSQL; `label()` e `variant()` vivem no enum. `payments.status` guarda o status bruto do gateway e por isso é string.
- Soft deletes em `products`, `categories`, `customers` e `showcases`; as unicidades (ex.: `(tenant_id, slug)`) são índices parciais `WHERE deleted_at IS NULL`. `orders` não usa soft delete: o ciclo de vida é o status `cancelled`.
- Número do pedido sequencial por tenant via `tenant_counters` com `lockForUpdate`.
- Vitrines: máximo de 10 por tenant, criação serializada por lock na linha do tenant. `max_items` vale para todos os tipos; em `all` o teto de segurança é 100.
- Telefone é normalizado para dígitos em um único lugar (`App\Support\Phone`).

## Riscos aceitos

1. **Login de cliente por telefone, sem verificação (D3).** A autenticação do cliente não verifica a posse do telefone: qualquer pessoa que conheça o número acessa a conta correspondente. Regra de projeto: **a área do cliente não deve receber dado sensível enquanto isso não mudar.** Hoje ela mostra perfil e histórico de pedidos; endereço e dados de pagamento não devem entrar ali. A verificação (OTP via WhatsApp) está planejada para a sprint 07+. O teste `test_d3_accepted_risk_phone_alone_authenticates` marca esse comportamento de propósito.
2. **`migrate:fresh` como estratégia de schema.** Vale somente enquanto não há cliente real nem dados de produção: migrations ainda são editadas e fundidas. Ao existir o primeiro dado real, esta regra acaba: a partir daí só migrations aditivas, nunca editar uma migration já aplicada e nunca rodar `migrate:fresh` fora do ambiente local.
3. Os limites do global scope descritos acima: o isolamento depende também de Policies e de checagens explícitas.

## Documentação das sprints

- `.sprint/01-payment` a `.sprint/05-buyer-experience`: histórico das cinco primeiras sprints (pagamentos, vitrines, personalização, experiência do comprador).
- `.sprint/06-refactor/01-review.md`: auditoria que originou a sprint 06.
- `.sprint/06-refactor/02-sprint.md`: plano e critérios de conclusão.
- `.sprint/06-refactor/04-decisions.md`: decisões de produto D1 a D8 (fonte canônica).
- `.sprint/06-refactor/05-development.md`: log do que foi feito, com o teste que prova cada item.
- `.sprint/06-refactor/06-inherited-items.md`: situação de cada pendência herdada das sprints 01 a 05.
