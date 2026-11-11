# order-web

Código da plataforma **quitanda.app**: SaaS multi-tenant de lojas white label. Cada loja (tenant) tem storefront, carrinho, checkout com pagamento online ou manual e um admin próprio. O caso de uso inicial é loja de encomendas (a demo é a doceria "Carol Doces"); a direção é atender mais de uma vertical, ver abaixo.

Este arquivo descreve o produto como ele está hoje: o que a loja faz, como rodar e as regras de pagamento, estoque e tenant. O contrato de cada pasta, os comandos e as invariantes de código estão nos `CLAUDE.md`. O histórico das sprints 01 a 06 está em `.sprint/`. Sprint nova segue [Como alterar o código](#como-alterar-o-código).

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
- Bootstrap 5.3, Font Awesome, Splide e SortableJS empacotados pelo Vite (sem CDN; só o SDK e o `security.js` (Device ID) do Mercado Pago, o gtag e o Google Fonts são externos)
- Docker Compose: `app` (php-fpm), `nginx`, `scheduler` (`schedule:work`: expira os Pix vencidos), `db`, `db_test`, `redis`, `mailpit`
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
- Regra herdada da sprint 06 e mantida no ciclo atual: a tarefa só fica pronta quando a prova passa. O relatório final mede de novo; o log não substitui essa medição.

CI (`.github/workflows/ci.yml`): em push na `main` e em pull requests roda `composer install`, build dos assets, `composer lint`, `composer analyse` e `php artisan test` com PostgreSQL 16 como service. O CI faz `cp .env.example .env` e sobrescreve só `DB_HOST`/`DB_PORT`. O Playwright **não roda no CI** (ver "Testes E2E"). Ainda não há branch protection.

## Retirada e entrega

Cada loja liga/desliga **retirada** e **entrega** em Admin → Retirada e Entrega (`tenants.settings.pickup` e `.delivery`). Sem configuração a loja só faz retirada, sem taxa e sem mínimo.

- **Pedido mínimo:** vale para todo pedido, sobre o subtotal (antes de qualquer taxa). Abaixo dele o carrinho e o checkout bloqueiam o envio e o servidor recusa.
- **Retirada:** pode ter uma taxa adicional.
- **Entrega:** exige endereço; a taxa vem do **bairro** escolhido (cadastro de nome e preço, até 100) ou, sem bairro ativo, da taxa padrão. "Entrega grátis acima de" zera a taxa de entrega.
- O servidor sempre refaz taxa e total ao gravar o pedido (e o gateway é cobrado por esse total); o cliente só escolhe tipo, bairro e endereço. O pedido guarda um snapshot em `orders.meta.fulfillment`.
- `OrderService::createFromCart` recusa carrinho sem nada para comprar (`EmptyCartException`) antes de gravar qualquer coisa.

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

1. Snapshot que falha é um **diff para revisar** no relatório local do Playwright, não um número para "atualizar até passar". O Playwright não roda no CI, então esse diff não aparece como artefato de lá.
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
src/e2e/__screenshots__/       baseline visual (não versionado; a pasta `src/e2e/` está no `.gitignore`)
src/database/seeders/E2eSeeder.php
```

## Arquitetura multi-tenant

- O tenant é resolvido pelo path: `/{tenant:slug}` (loja) e `/{tenant:slug}/admin` (admin). Domínio próprio por tenant não existe.
- `ResolveTenantFromPath` carrega o tenant e o grava em `App\Support\TenantContext`, registrado como `scoped` (uma instância por requisição; seguro em Octane e testável). O contexto tem três estados: tenant, plataforma e indefinido.
- Os models de loja implementam `TenantScoped` e usam `BelongsToTenant`: `Category`, `Customer`, `CustomerAddress`, `Order`, `OrderItem`, `Payment`, `PaymentWebhookEvent`, `Product`, `Showcase`. `Tenant`, `User` e `TenantCounter` ficam fora de propósito (ver o docblock da trait).
- Com tenant definido, a leitura filtra `tenant_id`. `save()`, `update()`, `delete()`, `restore()` e `forceDelete()` de uma linha de outra loja lançam `CrossTenantWriteException`. A re-hidratação do Livewire também passa pelo escopo.
- Sem contexto (webhook, seeder, job, artisan) e no modo plataforma (`usePlatformScope`) nada é filtrado e a escrita não é barrada. Endurecer o estado indefinido continua localizado no `TenantScope`.
- As chamadas explícitas `->forTenant($id)` continuam como defesa em profundidade. Zerá-las foi meta da sprint 06 e o relatório final registrou que não foi cumprida.
- Banco: toda FK do schema existe; os pivôs `category_product` e `showcase_product` têm `tenant_id` e FK composta `(tenant_id, X_id)`, então o banco rejeita vínculo entre tenants. Ainda aceitam referência cross-tenant (FK só por `id`): `showcases.category_id`, `orders.customer_id` e `order_items.product_id`.

### O que o isolamento não cobre

- SQL cru (`DB::table`) ignora a trait.
- `withoutTenantScope()` é o opt-out explícito. No código da aplicação, o uso atual é `PaymentWebhookProcessor`, que lê `payments.tenant_id` no webhook sem tenant.
- Autorização é outra barreira. O isolamento não diz quem pode escrever: isso fica nas Policies e nos middlewares de admin e de cliente.
- Model novo, opt-out novo e SQL cru novo entram na lista fechada de `TenantIsolationAuditTest`. `RouteMiddlewareAuditTest` e `LivewireAdminAuthorizationAuditTest` travam rota e autorização.

Todo opt-out continua greppável:

```bash
grep -rn "withoutTenantScope\|runForPlatform\|usePlatformScope" src/app
```

## Autenticação e autorização

- Dois guards próprios, ambos com chave por tenant (login simultâneo em dois tenants no mesmo navegador funciona):
  - `tenant_admin` (`App\Auth\TenantAdminGuard`): sessão do Laravel; revalida tenant e papel a cada leitura do usuário. Usuários em `users`, papel em `users.role`.
  - `customer` (`App\Auth\CustomerSessionGuard`): cookie `customer_session_{tenant_id}` com token revogável. Clientes ficam em `customers`, não em `users`.
- Papéis (`App\Enums\Role`): `platform_admin` (sem tenant; `users.tenant_id` é nullable de propósito) e `tenant_admin`. `tenant_staff` foi removido.
- Autorização por Policies (`app/Policies`) sobre `Role::permissions()`; centralizada em um lugar. Toda ação pública dos componentes Livewire de admin chama `authorize()`, e a trait `AuthorizesOnHydrate` reautoriza a cada `POST /livewire/update`.
- O Livewire não reexecuta o middleware da rota: os middlewares de auth estão registrados como persistentes em `AppServiceProvider`. Sem isso, ações de admin passariam sem sessão (isso já aconteceu e está coberto por teste).
- Cookies: `HttpOnly`, `SameSite=Lax`; a flag `Secure` vem de `SESSION_SECURE_COOKIE` e, se omitida, de `APP_ENV=production` ou requisição HTTPS.

## Pagamentos

- Gateways plugáveis: `PaymentGatewayInterface` + `PaymentGatewayManager` (resolve por tenant e método; `extend()` registra um novo). `TenantPaymentController`, `PaymentService` e o processador de webhook só conhecem a interface. Existe `MercadoPagoGateway` (API de **Orders**, `/v1/orders`); os métodos `manual` (dinheiro/cartão na entrega, PIX chave) não passam por gateway.
- Credenciais do Mercado Pago são por tenant, cifradas em `tenants.settings` e write-only no admin (o segredo nunca volta ao HTML nem ao estado do Livewire; a máscara é calculada no servidor).
- Pedido e tentativa de pagamento (`payments`) nascem numa transação curta, sem transação aberta durante a chamada HTTP ao gateway. Duplo submit é resolvido por `UNIQUE (gateway, idempotency_key)`. Falha do gateway cancela o pedido e devolve o estoque uma vez.
- API do Mercado Pago: **Orders** (`POST /v1/orders`, consulta em `GET /v1/orders/{id}`), não a antiga de Payments. `payments.gateway_payment_id` guarda o id da ORDER (`ORD...`); o da transação (`PAY...`) e o status bruto do MP ficam em `payments.meta` (`transaction_id`, `gateway_details`). O vínculo com o pedido é o `external_reference` (id do pedido): a API de Orders não aceita `metadata`. A tabela de status do MP (order e transação) para o vocabulário do sistema está em `App\Gateways\MercadoPago\OrderStatusMapper`; valor desconhecido nunca vira "pago". Cartão recusado volta como HTTP 402 com a order em `data`: é tratado como recusa registrada (pedido cancelado, estoque devolvido). Débito: a API de Orders só aceita Elo (`debelo`) e débito virtual Caixa; Visa/Mastercard débito não passam por ela.
- Webhook (tópico **Order**): `POST /webhooks/{gateway}/{tenantId}` e `POST /webhooks/{gateway}` (compatibilidade). A assinatura (`x-signature`/`x-request-id`, HMAC-SHA256 do manifesto `id:<data.id da query, em minúsculas>;request-id:...;ts:...;`) é validada com o secret do tenant antes de qualquer consulta a pedido, pagamento ou API. O status vem sempre da consulta `GET /v1/orders/{id}`, nunca do corpo do webhook. Tópicos que não sejam `order` e ids numéricos da API antiga são respondidos com 200 e ignorados.
- **O que o lojista precisa fazer**: na API de Orders a URL de notificação NÃO vai na cobrança, só vale a cadastrada no painel do MP (Suas integrações > Webhooks > Configurar notificações, evento **Order (Mercado Pago)**). Admin > Pagamentos > Integrações mostra a URL exata (`https://<APP_URL>/webhooks/mercadopago/<id do tenant>`, ou o aviso "indisponível em ambiente local") e o passo a passo; a chave secreta que o MP gera ao salvar é colada no campo "Webhook Secret" (write-only). Sem o secret os webhooks reais são recusados (401) e a confirmação depende da reconciliação.
- Idempotência: tabela `payment_webhook_events` (UNIQUE `(gateway, gateway_payment_id, status)`) mais `lockForUpdate` em pedido e pagamento. Reenvios e webhooks simultâneos produzem uma única transição.
- Sem secret configurado: produção falha fechado (401); o bypass só vale em `local`/`testing`.
- `App\Support\WebhookUrl` calcula a URL do webhook só para `APP_URL` pública (`https`, domínio com DNS ou IP público, sem `localhost`/loopback/redes privadas/TLDs locais como `.local` e `.test`, porta padrão); o MP não alcança as demais. Ela é exibida na tela de Pagamentos; o corpo da order nunca leva `notification_url`.
- **Pix**: `transactions.payments[0].payment_method = {id: pix, type: bank_transfer}` com `expiration_time` (ISO 8601) de `MERCADOPAGO_PIX_EXPIRATION` (padrão `PT30M`; a documentação do MP aceita de 30 minutos a 30 dias e valores fora disso caem no padrão, com aviso no log). Esse prazo é só o teto de segurança do lado do MP: **o cliente tem uma janela bem menor, imposta pelo nosso servidor** (próximo item). A order de Pix é assíncrona: nasce `processing` sem QR, o servidor espera no máximo `MERCADOPAGO_PIX_QR_WAIT` segundos (padrão 8, 0 desliga) pelo QR e, se não vier, o modal mostra "Gerando o seu Pix…" e `GET /{tenant}/checkout/status` entrega o QR (`pix.qr_code|qr_code_base64|ticket_url`, só para o dono da sessão e enquanto o pedido está pendente) assim que aparecer.
- **Janela de pagamento do Pix (`MERCADOPAGO_PIX_WINDOW`, segundos; padrão e TETO 300, piso 60; valor inválido, zero ou negativo cai em 300)**: o resultado do Pix é um modal com QR, copia e cola e contador regressivo MM:SS. Ao aceitar a cobrança o servidor grava `payments.meta.pix_expires_at` (instante absoluto, UTC, arredondado para cima ao segundo) e devolve `expires_at` + `server_now` (em `process_payment`, em `checkout/status` enquanto pendente e na tela restaurada ao recarregar): o contador é calculado do prazo do servidor, corrigido pela diferença de relógio do cliente, e nunca "volta". **Quem decide a expiração é o servidor**, não o navegador (`App\Services\PixExpiryEnforcer`): passada a janela + 10 s de tolerância, ele consulta a order no MP; se já está paga, é paga (nunca é cancelada); se ainda aguardando (`created`/`action_required`), cancela a order no MP (`POST /v1/orders/{id}/cancel`) e SÓ depois cancela o pedido e devolve o estoque (uma vez, pelo mesmo caminho idempotente do webhook). Se o cancelamento no MP falhar (rede/5xx), nada local muda: o pedido fica pendente, é logado `payment.pix_expiry.cancel_failed` e o próximo ciclo tenta de novo. Isto roda no `GET /{tenant}/checkout/status` e em `payments:reconcile` (caso o cliente tenha fechado a aba). Pix anterior a esta regra (sem `pix_expires_at`) não é tocado por ela.
- **Device ID** (antifraude): a página de checkout (e só ela) carrega `https://www.mercadopago.com/v2/security.js`; o navegador envia `window.MP_DEVICE_SESSION_ID` em `process_payment`, o servidor valida (`App\Support\DeviceSessionId`: `[A-Za-z0-9._-]`, 8 a 512 caracteres; o ponto é necessário porque os valores reais são `armor.<hex>.<hex>`) e o repassa no cabeçalho `X-meli-session-id`. Inválido ou ausente é descartado em silêncio: nunca bloqueia o pagamento.
- **Cartão**: o Card Payment Brick continua gerando o token; o servidor monta `payment_method = {id: <bandeira do Brick>, type: credit_card|debit_card (pelo método escolhido), token, installments: 1}` e `payer` com e-mail e nome (`first_name`/`last_name`) do formulário e a identificação do Brick.
- Reconciliação sem webhook: `GET /{tenant}/checkout/status` com pedido pendente consulta o pagamento na API (`PaymentReconciler`, no máximo 1x a cada 3 s por pagamento, `Cache::add` atômico; use um cache compartilhado, como `database` ou `redis`, para o limite valer entre processos) e aplica o resultado pelo MESMO caminho idempotente do webhook (`PaymentWebhookProcessor::reconcile`). Falha do gateway, da rede ou do cache nunca derruba o endpoint: devolve o estado atual e loga `payment.reconcile.*`. `php artisan payments:reconcile [--minutes=10] [--limit=200] [--pix-only]` varre primeiro os Pix com a janela vencida (qualquer idade) e depois os demais pagamentos em aberto mais velhos que `--minutes`. **Só a varredura `payments:reconcile --pix-only` está agendada (no Docker de desenvolvimento o serviço `scheduler` já a executa)** (`routes/console.php`, a cada minuto, `withoutOverlapping`); em produção o agendador do Laravel precisa rodar, p. ex. cron `* * * * * cd /caminho && php artisan schedule:run >> /dev/null 2>&1` (ou `php artisan schedule:work`). Sem ele, o Pix de quem fechou a aba só expira na próxima consulta de status ou execução manual.
- Falha de cobrança: log `payment.charge.failed` (warning para recusa/4xx, error para 5xx/timeout) com tenant, pedido, pagamento, status HTTP e `errors[]` do gateway, sem token, e-mail, cartão nem corpo da requisição. Falha de configuração/indisponibilidade (5xx, 401/403/429, timeout, erros de integração da API de Orders como `invalid_credentials` ou `payment_method_not_allowed_for_collector`) mostra ao cliente "O pagamento online está indisponível no momento..."; erro de dado do cliente mantém "confira os dados e tente novamente".
- Ainda não há fila, retry próprio nem dead-letter para webhooks, e a única reconciliação agendada é a de Pix vencidos (acima); quando a API do gateway está fora, o webhook responde 503 e depende do reenvio do próprio gateway.

### Pagamentos em desenvolvimento (webhooks)

Com `APP_URL=http://localhost` (o padrão do `.env.example`) o Mercado Pago não consegue chamar o seu computador, então o webhook não funciona e a confirmação do PIX vem da reconciliação: a tela de espera consulta `/checkout/status` e o pedido muda para pago sozinho (pode levar alguns segundos além do pagamento). Para testar o webhook de verdade, exponha a aplicação por um túnel `https` (ngrok, cloudflared) e ajuste `APP_URL` para a URL do túnel, por exemplo `APP_URL=https://x.ngrok-free.app`, reiniciando o container (`php artisan config:clear`). A URL a cadastrar no painel do MP (evento **Order**) é `https://<APP_URL>/webhooks/mercadopago/<id do tenant>`; a tela Admin > Pagamentos > Integrações a mostra pronta para copiar.
- **Túneis e proxies (`TRUSTED_PROXIES`)**: atrás de um túnel ou proxy HTTPS o esquema e o host chegam em `X-Forwarded-*`, que qualquer cliente pode forjar; por isso o padrão é não confiar em ninguém. Defina `TRUSTED_PROXIES` com os IPs do proxy separados por vírgula, ou `*` só em desenvolvimento com túnel, senão `route()`/`url()` saem `http://`. Coberto por `tests/Feature/Security/TrustProxiesTest.php`.
- `MERCADOPAGO_INTEGRATOR_ID` (opcional, VAZIO por padrão) define o cabeçalho `X-Integrator-Id`; só é enviado quando preenchido e deve ser o identificador do seu próprio cadastro de integrador (não é credencial).
- `MERCADOPAGO_PIX_EXPIRATION` (padrão `PT30M`), `MERCADOPAGO_PIX_WINDOW` (padrão e teto `300`) e `MERCADOPAGO_PIX_QR_WAIT` (padrão `8`): ver Pix acima. Na sandbox a conta de teste atual não gera o QR do Pix (a order fica `processing`); ver `.sprint/06-refactor/05-development.md`, seção 15.

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
3. O isolamento de tenant não substitui autorização: SQL cru, opt-out e contexto indefinido continuam fora da trait, e quem pode escrever segue nas Policies.

## Como alterar o código

Antes de editar uma pasta, leia o `CLAUDE.md` dela. O da raiz junta o setup, as regras que atravessam pastas, os comandos e o mapa. Esse contrato não é copiado aqui.

Uma sprint nova é uma pasta `.sprint/NN-slug/` com seis arquivos:

| Arquivo | Papel |
| --- | --- |
| `spec.md` | O que muda, para quem, o aceite e o que fica de fora. Sem stack. |
| `plan.md` | Como implementar, preso à spec aceita. |
| `tasks.md` | Tarefas pequenas. Cada uma tem uma prova. |
| `log.md` | O que o dev tentou. Não é a fonte da verdade. |
| `qa.md` | Rodadas medidas contra a spec e o código. |
| `report.md` | Fechamento medido de novo. |

O formato, os portões, o ciclo de um achado e a atualização dos `CLAUDE.md` estão em `.grok/skills/sprint/references/contract.md`, na seção `CLAUDE.md na sprint`. No chat, `/sprint` lê a pasta e faz a fase atual. Cada chamada faz uma fase e para. `aceito` grava o aceite da spec, do plano ou das tarefas. `/sprint-spec`, `/sprint-plan`, `/sprint-tasks`, `/sprint-dev`, `/sprint-qa` e `/sprint-close` só forçam uma fase. Quem implementa é um subagente por tarefa; o QA é um subagente de leitura. Você não abre um agente por tarefa.

As pastas `.sprint/01-payment` a `.sprint/06-refactor` ficam no formato em que foram escritas.

## Documentação das sprints

Histórico:

- `.sprint/01-payment` a `.sprint/05-buyer-experience`: pagamentos, vitrines, personalização, experiência do comprador.
- `.sprint/06-refactor/01-review.md`: auditoria que originou a sprint 06.
- `.sprint/06-refactor/02-sprint.md`: plano e critérios de conclusão.
- `.sprint/06-refactor/03-design-system.md`: design system.
- `.sprint/06-refactor/04-decisions.md`: decisões de produto D1 a D8 (fonte canônica).
- `.sprint/06-refactor/05-development.md`: log do que foi feito, com o teste que prova cada item.
- `.sprint/06-refactor/06-inherited-items.md`: situação de cada pendência herdada das sprints 01 a 05.
- `.sprint/06-refactor/07-final-report.md`: o que a sprint 06 fechou de fato. Onde o log e o relatório divergem, vale o relatório.
