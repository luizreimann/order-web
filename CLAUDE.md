# order-web — Contexto do Projeto

> SaaS multi-tenant de lojas sob encomenda (produto **quitanda.app**). Cada loja é um `Tenant` com slug na URL (`/caroldoces`). `/` é a landing da plataforma e não tem tenant. Código da aplicação em `src/`. Stack: Laravel 12, Livewire 3, PHP 8.4, PostgreSQL 16, Bootstrap 5.3, Vite. **IMPORTANT**: a invariante nº 1 é o isolamento por loja (ver Regras de Código). Antes de alterar um diretório, leia o `CLAUDE.md` local dele. O contrato longo fica lá; este arquivo não o copia.

O histórico de produto está no `README.md` e em `.sprint/` (decisões em `.sprint/06-refactor/04-decisions.md`). Aponte para eles. Não os reescreva aqui.

## Setup / Rodar Localmente

Pré-requisitos: Docker e Node 22 no host. O container PHP não tem Node; os assets compilam no host.

```bash
docker compose up -d
cp src/.env.example src/.env
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate:fresh --seed
(cd src && npm ci && npm run build)
```

| O quê | Onde |
| --- | --- |
| Landing | http://localhost:8080/ |
| Loja demo | http://localhost:8080/caroldoces |
| Admin da demo | http://localhost:8080/caroldoces/admin/login |
| Mailpit | http://localhost:8025 |
| Postgres de dev | `localhost:5433`, banco `wl`, user `wl`, senha `wl` |
| Postgres dos testes | `localhost:5434`, banco `wl_test` (tmpfs) |
| Redis | `localhost:6379` |

Login do admin de demo: `admin@caroldoces.com` / `password`. Existe só no seed de desenvolvimento. `.env.example` aponta `DB_HOST=db`. Em produção troque as credenciais e mantenha `APP_DEBUG=false`.

O serviço `scheduler` roda `php artisan schedule:work`. Sem ele, o Pix vencido só expira quando alguém consulta o status.

## Documentação de Libs

Não dependa do conhecimento interno para a API destas versões. Confira a doc da versão instalada em `src/composer.lock` e `src/package-lock.json` antes de mudar hidratação, middleware, HTTP ou build:

- `laravel/framework` ^12, `livewire/livewire` ^3.7
- `phpunit/phpunit` ^11.5, `phpstan/phpstan` 2.2 + `larastan/larastan` ^3, `laravel/pint` ^1
- Vite ^7, Bootstrap 5.3, `@splidejs/splide` 4.1.4, `sortablejs` 1.15.7, `@playwright/test` 1.64 (E2E, só local)

O HTTP ao Mercado Pago usa o client do Laravel (`Http::timeout(30)` em `MercadoPagoGateway`). Não há SDK de pagamento no `composer.json`.

## Etiqueta do Repositório

- **Git**: nunca execute operação git sem pedido explícito. Confirme a branch antes de começar.
- **CI**: `.github/workflows/ci.yml`, em push na `main` e em pull request, roda `composer lint`, `composer analyse` e `php artisan test` com PostgreSQL 16. O Playwright não entra no CI. Ainda não há branch protection.
- **Logs**: `src/storage/logs` (`LOG_CHANNEL=stack`). Incidentes de pagamento usam as chaves `payment.incident.*`. Não há Sentry neste repositório.
- **Siga o arquivo vizinho.** Há comportamento que é dívida ou armadilha documentada. A seção Regra vs. dívida diz o que não replicar.
- **Um `CLAUDE.md` por diretório.** Os dois, `CLAUDE.md` e `AGENTS.md`, carregam juntos: não crie o segundo. Não use `@import` de README ou de `.sprint/`.
- Pasta com uma ideia documenta o contrato dessa ideia. Gaveta (`Support`, `views`) é índice: uma regra por arquivo. Não escreva um ensaio da primeira classe da pasta.

## Regras de Código (Mandatórias)

- **IMPORTANT — Isolamento por loja é a trait, e é obrigatório.** Model de loja implementa `TenantScoped` e usa `BelongsToTenant`. O contexto é `TenantContext` (binding `scoped`). Com tenant definido, a leitura filtra `tenant_id` e a escrita de outra loja lança `CrossTenantWriteException`. Contexto indefinido (job, seeder, comando, webhook) não filtra e não bloqueia escrita. Opt-out de leitura: `Model::withoutTenantScope()`. `withoutGlobalScopes()` derruba os outros scopes. SQL cru, opt-out novo e model novo entram na lista fechada de `TenantIsolationAuditTest`. `Tenant`, `User` e `TenantCounter` ficam fora da trait de propósito.
- **`TenantCounter` tem PK composta `(tenant_id, key)`.** Nunca `save()`, `update()` nem `delete()` de instância (BUG-002: UPDATE sem WHERE). Incremento só por `nextValue()`. Outra escrita: `queryFor()`.
- **Autorização é Policy, não o menu.** `TenantResourcePolicy::allows()` exige `User` com papel de loja, `Role::can(Permission)` e `users.tenant_id` igual ao do recurso. `platform_admin` não passa (impersonation é a sprint 07; o único lugar a mudar é `allows()`). Componente admin usa `AuthorizesOnHydrate` e, na escrita, busca de novo com `forTenant`. Esconder item de menu não autoriza a rota.
- **Dinheiro é inteiro em centavos.** Reais digitados passam por `Money::toCents`, que devolve null se não entender. Não coerce inválido para 0. `"1.250"` sem vírgula é inválido.
- **O servidor recalcula preço, taxa, mínimo e estoque.** Propriedade pública de Livewire é editável pelo cliente. Segredo não entra em propriedade pública: o valor chega como argumento do método (copie `PaymentSettings`).
- **Três status não se misturam:** `orders.status`, `orders.payment_status`, `payments.status`. Status de gateway nunca sai do corpo do webhook; sai de `getPayment()` com o token da loja.
- **Pedido e cobrança.** Fechar pedido é `OrderService::createFromCart` (uma transação; a baixa é `SELECT … FOR UPDATE`). Pagamento online: `PaymentService::begin()` grava pedido e tentativa numa transação curta; `charge()` faz HTTP fora de transação e consolida depois. Método manual não tem gateway.
- **`tenants.settings` é JSON.** `save()` de configuração substitui o bloco inteiro (`data_set`). Chave irmã sobrevive; chave extra dentro do bloco some. Plano e capability futuros não entram nesse JSON (decisão D6).
- **PostgreSQL 16.** Sem SQLite: jsonb, `FOR UPDATE`, `insertOrIgnore` e unique parcial. Três bancos: `wl` (dev), `wl_test` (PHPUnit), `wl_e2e` (Playwright). `E2eSeeder` não entra no `DatabaseSeeder`.
- **Fila da aplicação é `database`** (`.env.example`). O PHPUnit força `QUEUE_CONNECTION=sync` em `src/phpunit.xml`. Não conclua que produção é sync. O agendador (`routes/console.php`) roda `payments:reconcile --pix-only` todo minuto.
- **Fuso da aplicação é `UTC`** (`config/app.php`). O horário comercial da loja usa `America/Sao_Paulo` quando o JSON não diz outro. `APP_LOCALE` do exemplo é `en`.
- **`declare(strict_types=1)` não é o padrão deste código.** Não introduza num arquivo só. Siga o arquivo que está editando.
- **Regra vs. dívida.** As regras acima são o padrão. Não copie: `save()` em `TenantCounter`; toast de pausa que diz que os pedidos pararam (o servidor só inverte `business_hours.paused`, e `isOpen()` não recusa pedido); interpolar `settings` cru num `<style>`; registrar `FakeGateway` no provider de produção; chamar `E2eSeeder` em `wl`; tratar o snapshot Livewire de `Customer` como a linha autorizada.

## Segurança

- **Laravel 12**: `$fillable` contra mass assignment; SQL cru com binding `?`; CSRF ligado, com a exceção já existente de `webhooks/*` (assinatura HMAC, sem sessão). Autorização é Policy. A UI não substitui.
- **Livewire 3**: propriedade pública é entrada do cliente. Autorize de novo em todo `POST /livewire/update` (`AuthorizesOnHydrate`). Não grave o model que o snapshot reidratou sem scope. Middleware de tenant e de auth está em `Livewire::addPersistentMiddleware` porque o Livewire não repete o middleware da rota sozinho.
- **HTTP ao Mercado Pago**: mantenha timeout e verificação TLS. Não logue `access_token`, `webhook_secret` nem `payments.meta` (pode ter dado de Pix). Cartão não é armazenado: o Brick manda o token. Credencial de loja fica cifrada em `settings` (`enc:` via `EncryptionHelper`), não no `.env`.
- **Proxy**: `TRUSTED_PROXIES` vazio não confia em `X-Forwarded-*`. `*` confia em todos. Não use `*` em produção.

## Referências de Código Real

> Antes de implementar, leia o arquivo que já estabelece o padrão:
> - `src/app/Models/Concerns/BelongsToTenant.php` — scope, escrita e o que fica de fora.
> - `src/app/Models/Scopes/TenantScope.php:apply` — filtro de leitura.
> - `src/app/Models/TenantCounter.php:nextValue` — incremento seguro do número do pedido.
> - `src/app/Support/Money.php:toCents` — única conversão real → centavos.
> - `src/app/Services/OrderService.php:createFromCart` — transação, cotação, estoque.
> - `src/app/Services/PaymentService.php:begin` e `:charge` — transação curta, HTTP fora dela.
> - `src/app/Services/PaymentWebhookProcessor.php:process` — status vem da consulta, não do corpo.
> - `src/app/Gateways/MercadoPagoGateway.php` — Orders API, timeout, corpo montado no servidor.
> - `src/app/Policies/TenantResourcePolicy.php:allows` — autorização de recurso de loja.
> - `src/app/Providers/AppServiceProvider.php:boot` — guards, middleware persistente, rate limit.
> - `src/app/Support/AdminNavigation.php:definition` — única lista do menu admin.
> - `src/bootstrap/app.php` — aliases de middleware e a exceção CSRF de `webhooks/*`.
> - `src/routes/web.php` — rotas. Webhook vem antes do grupo `{tenant:slug}`.
> - `src/routes/console.php` — `payments:reconcile --pix-only`.

## Localização de Conhecimento (CLAUDE.md Locais)

| Diretório | O que documenta |
| --- | --- |
| `src/app/Models/` | Isolamento, identidade, dinheiro, os três status |
| `src/app/Services/` | Carrinho, cotação, pedido, pagamento, login do cliente |
| `src/app/Gateways/` | Mercado Pago e resolução de gateway |
| `src/app/Contracts/` | Fronteira que o gateway implementa |
| `src/app/Enums/` | Papel, método, os três status |
| `src/app/Exceptions/` | Qual falha aborta o quê |
| `src/app/Rules/` | Telefone e dinheiro de formulário |
| `src/app/Policies/` | Quem pode |
| `src/app/Auth/` | Guards `tenant_admin` e `customer` |
| `src/app/Http/Middleware/` | `tenant`, cliente, admin, proxy |
| `src/app/Http/Controllers/` | Checkout online, login do admin, pausa |
| `src/app/Livewire/Tenant/` | Vitrine, carrinho, checkout, conta |
| `src/app/Livewire/Admin/` | Catálogo, clientes, pedidos |
| `src/app/Livewire/Admin/Tenant/` | JSON de configuração da loja |
| `src/app/Support/` | Índice da gaveta e o menu do admin |
| `src/app/Providers/` | O que o boot registra |
| `src/routes/` | Onde a rota entra e qual middleware leva |
| `src/database/` | Bancos e seeders |
| `src/tests/` | Onde a suíte trava um contrato |
| `src/resources/views/` | Layouts, tema, SEO, landing |
| `src/resources/js/` | JS da loja e do painel |

Não há `CLAUDE.md` em `src/app/` de propósito: o mapa é esta tabela, e o contrato carrega só quando o trabalho entra na pasta. `<x-admin-nav>` só desenha; a lista é `AdminNavigation`. O comando `payments:reconcile` está em `Services`.

## Comandos

Na raiz, com o Compose de pé. Artisan e Composer rodam dentro de `app` porque o código e o `vendor` estão em `src/`.

| Comando | O que faz |
| --- | --- |
| `docker compose up -d` | Sobe app, nginx, scheduler, db, db_test, redis, mailpit. |
| `docker compose exec app php artisan test` | Suíte PHPUnit em `wl_test`. `--filter Classe` ou `--filter Classe::metodo` isola um caso. |
| `docker compose exec app composer lint` | Pint em modo `--test`. |
| `docker compose exec app composer format` | Pint aplica o estilo. |
| `docker compose exec app composer analyse` | PHPStan nível 5. O baseline `src/phpstan-baseline.neon` só diminui. |
| `docker compose exec app php artisan migrate:fresh --seed` | Recria `wl` e roda o seed de demo. Não use no `wl_test` na mão: o PHPUnit já faz `RefreshDatabase`. |
| `(cd src && npm run build)` | Assets do Vite. O CI precisa do manifest para as views. |
| `(cd src && npm run e2e)` | Playwright, só se `src/e2e/` existir. Banco `wl_e2e`. Não toca `wl` nem `wl_test`. |
| `docker compose exec app php artisan payments:reconcile --pix-only` | A mesma rotina do scheduler, na mão. |

## Contexto Técnico Rápido

| Item | Valor |
| --- | --- |
| PHP | 8.4 no Docker (`php:8.4-fpm-alpine`); `composer.json` pede `^8.2` |
| Framework | Laravel 12, Livewire 3 |
| Banco | PostgreSQL 16. Três papéis: `wl`, `wl_test`, `wl_e2e` |
| Auth | Guards próprios `tenant_admin` (sessão) e `customer` (cookie). Sem Sanctum |
| Fila | `database` na aplicação; `sync` só no `phpunit.xml` |
| Cache / sessão | `database` no exemplo; a suíte usa `array` |
| Front-end | Blade + Livewire, Bootstrap 5.3, Vite. SDK do Mercado Pago, `security.js`, gtag e Google Fonts são os externos |
| Pagamento | Mercado Pago Orders API (`/v1/orders`). Métodos manuais não têm gateway |
| Testes | PHPUnit 11. Playwright 1.64 fora do git e do CI |
| CI | GitHub Actions, PHP 8.4, Node 22, Postgres 16 |
| Timezone / locale | App `UTC` / `en`. Horário da loja padrão `America/Sao_Paulo` |

## Testes e Verificação

- Rode `docker compose exec app php artisan test` para provar a alteração. Filtro por classe ou método quando a suíte inteira for desproporcional. Lint e PHPStan entram na mesma prova quando o diff mexe em estilo ou tipo.
- A suíte aponta para `db_test` / `wl_test` (`src/phpunit.xml`). `RefreshDatabase` recria esse banco. Não aponte `DB_HOST` para `db` / `wl`.
- Um teste de Node fica `skipped` dentro do container, que não tem Node. Ele roda no host.
- E2E: `npm run e2e` em `src/` quando a pasta existir. Não rode `npx playwright test` no host para snapshot visual: a fonte muda entre macOS e Linux.
- Ao alterar uma convenção, atualize este arquivo e o `CLAUDE.md` da pasta. Não deixe segredo no arquivo.

## Sprint

Sprint nova segue `.grok/skills/sprint/references/contract.md`. O chat orquestra com `/sprint`. Quem implementa é um subagente por tarefa, em `/sprint-dev`. Quando a sprint muda uma convenção, a atualização dos `CLAUDE.md` segue a seção `CLAUDE.md na sprint` desse contrato. As pastas `.sprint/01-*` a `.sprint/06-*` permanecem no formato em que foram escritas.

## Manutenção deste arquivo

> **Limite de 300 linhas.** Se passar, mova o excedente para `order-web-claude-pt1.md` no mesmo diretório e referencie-o aqui. Continuações `-pt2`, `-pt3` encadeadas. Regra válida para qualquer edição futura.
