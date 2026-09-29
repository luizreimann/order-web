# Review Técnico — Sprint 06: Refactor & Hardening

**Data:** 2026-09-15
**Sprint:** 06-refactor
**Tipo:** Auditoria técnica completa (pré-sprint)
**Escopo auditado:** `src/app`, `src/database`, `src/routes`, `src/resources`, `src/tests`, `src/config`, `docker/`, `.sprint/01..05`

---

## 1. Contexto

O projeto está parado desde a Sprint 05. Antes de retomar o desenvolvimento de features, foi conduzida uma auditoria completa para decidir entre três caminhos: **dar sequência**, **refatorar** ou **reconstruir do zero**.

**Premissa crítica confirmada:** o sistema **nunca foi para produção**. Não há dados reais, clientes reais ou pagamentos reais. Isso tem duas consequências diretas no planejamento:

1. Nenhum achado desta auditoria é um incidente de segurança ativo — são dívidas técnicas.
2. **Não há restrição de compatibilidade de dados.** Migrations podem ser corrigidas no lugar e o banco pode ser recriado, em vez de empilhar migrations de correção.

### 1.1 Veredito da auditoria

**REFATORAR.** A reconstrução do zero destruiria valor.

O raciocínio está na **natureza** dos defeitos: eles são quase todos **aditivos**, não emaranhados. Não existe god object, dependência circular ou hierarquia de herança perversa. São 57 arquivos PHP com camadas rasas mas legíveis (`Livewire → Service → Model`). Não há nada para desemaranhar — há coisa faltando.

| Defeito | Natureza do trabalho |
|---|---|
| Sem global scope de tenant | **Adicionar** trait |
| Sem guards/policies | **Adicionar** camada |
| Sem foreign keys | **Adicionar** migration |
| Sem CI/análise estática | **Adicionar** config |
| Auth por telefone | **Trocar** um Service isolado |
| `customer_id` não persistido | **Uma linha** |

Reconstruir preservaria o mesmo stack (que é a escolha correta), o mesmo formato de schema (~80% correto) e o `MercadoPagoGateway` (a parte mais subtil e madura do código) — chegando quase ao mesmo lugar, mas com risco de **perder os acertos difíceis** listados na seção 2.

---

## 2. O que está correto e NÃO deve ser tocado

Registrado explicitamente para que a refatoração não destrua acertos. Estes pontos resolvem problemas clássicos e estão implementados corretamente:

| # | Acerto | Local |
|---|---|---|
| OK-01 | **Dinheiro em centavos (int)**, nunca float | `app/Services/OrderService.php` |
| OK-02 | **Snapshot dos itens do pedido** — `name`, `sku`, `unit_price_cents` copiados na compra; `product_id` nullable para preservar histórico | `app/Services/OrderService.php:125-136`, `database/migrations/..._create_order_items_table.php:15` |
| ~~OK-03~~ | ~~**Numeração sequencial por tenant** com `lockForUpdate()` em tabela de contadores — correto sob concorrência~~ **RETRATADO — ver BUG-002 na seção 3.** O desenho está certo; a implementação está quebrada | `app/Models/Order.php:70-95` |
| OK-04 | **Total recalculado no servidor** a partir do preço do banco; frontend não dita valor | `app/Services/OrderService.php:84-117` |
| OK-05 | **Webhook com defesa em profundidade** — reconsulta a API do MP e valida `metadata.tenant_id`/`order_id` antes de mutar. Não é possível forjar "pago" apenas enviando JSON | `app/Gateways/MercadoPagoGateway.php:172-181` |
| OK-06 | **Credenciais cifradas** com `Crypt` (APP_KEY), não criptografia caseira | `app/Support/EncryptionHelper.php` |
| OK-07 | **HMAC do webhook** com `hash_equals` (timing-safe) e manifest correto | `app/Gateways/MercadoPagoGateway.php:269-277` |
| OK-08 | UUID como PK, `unique(tenant_id, slug)`, índices compostos por tenant | migrations de `products`, `categories`, `orders` |
| OK-09 | Testes de helpers/enums genuinamente bons, cobrindo casos de borda | `tests/Unit/Support/*`, `tests/Unit/Enums/*` |
| OK-10 | Docker coerente com a stack declarada (PHP 8.4, PG 16, Redis 7) | `docker-compose.yml`, `docker/php/Dockerfile` |

---

## 3. Achados — Bloqueadores (P0)

### BUG-001 — `customer_id` nunca é persistido em `orders` (CONFIRMADO EMPIRICAMENTE)

**Severidade:** Crítica | **Esforço:** Trivial

`OrderService::createFromCart` passa `customer_id` para `Order::create()`, mas `customer_id` **não está** no `$fillable` do model. O mass-assignment descarta o campo silenciosamente.

- `app/Services/OrderService.php:26-42` — envia `'customer_id' => $customer->id`
- `app/Models/Order.php:17-38` — `$fillable` não contém `customer_id`
- `database/migrations/..._add_customer_id_to_orders_table.php:11` — coluna existe
- `app/Models/Order.php:59-62` — relacionamento `customer()` definido

**Prova:** teste temporário executado contra o `OrderService` real:

```
>>> customer_id em memoria: '...'
>>> customer_id no banco:   NULL
```

**Impacto:** todo pedido criado pelo checkout nasce órfão do cliente. O relacionamento `Order::customer()` é decorativo. A área do cliente (`/conta`) não consegue listar pedidos de forma confiável.

**O que este bug revela é mais grave que o bug:** os 89 testes existentes passam e ninguém nunca validou o fluxo central do produto de ponta a ponta.

---

### BUG-002 — `TenantCounter` faz UPDATE sem WHERE e corrompe contadores entre tenants (CONFIRMADO EMPIRICAMENTE)

**Severidade:** Crítica | **Esforço:** Baixo | **Descoberto:** durante a execução da sprint, não nesta auditoria

> ⚠️ **Retratação:** esta auditoria classificou originalmente a numeração de pedidos como **OK-03**, um acerto que não deveria ser tocado. Isso estava errado. O *desenho* (tabela de contadores + `lockForUpdate`) está correto; a *implementação* está silenciosamente quebrada. O erro passou porque a análise foi estática e não questionou o `$primaryKey = null` — e porque com um único tenant no banco o bug é invisível.

`App\Models\TenantCounter` tenta representar a chave composta `(tenant_id, key)` assim:

```php
public $incrementing = false;
protected $primaryKey = null; // chave composta
```

O Eloquent **não suporta chave primária composta**. Com `$primaryKey = null`, o `$counter->save()` em `Order::booted()` (`app/Models/Order.php:92`) gera um UPDATE sem cláusula `WHERE`:

```
select * from "tenant_counters" where "tenant_id" = ? and "key" = ? limit 1 for update
insert into "tenant_counters" (...) values (...)
update "tenant_counters" set "value" = ?, "updated_at" = ?        <-- SEM WHERE
```

**Reprodução:** com 2 tenants, criar 1 pedido no tenant B resetou o contador do tenant A de `10` para `1`:

```
ANTES:   caroldoces value=10
DEPOIS:  caroldoces value=1     <-- resetado por um pedido de OUTRO tenant
         tenant-b   value=1
```

**Consequência comprovada** — o pedido seguinte do tenant A falha:

```
SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint
"orders_tenant_id_number_unique"
```

**Impacto:** o checkout quebra por completo assim que existe um segundo tenant. Numa plataforma multi-tenant, isso é bloqueador absoluto de produção. Também corrompe silenciosamente o histórico de numeração.

**Por que só apareceu agora:** o banco de desenvolvimento só tinha o tenant `caroldoces`. Um UPDATE sem WHERE numa tabela de uma linha só é indistinguível de um UPDATE correto. O bug só se manifesta a partir do segundo tenant — exatamente o caso que nenhum teste cobria (`01-review.md` QA-001: zero testes de isolamento cross-tenant).

**Lição para a sprint:** reforça que o critério de conclusão da seção 1.3 de `02-sprint.md` (teste que falha antes, passa depois) não é burocracia. Este bug sobreviveu a cinco sprints e a uma auditoria completa.

---

### SEC-001 — Login de cliente sem qualquer verificação de posse

**Severidade:** Crítica | **Esforço:** Médio

`CustomerAuthService::login()` recebe **apenas o telefone** e autentica. Sem OTP, sem SMS, sem senha.

- `app/Services/CustomerAuthService.php:11-41`
- `app/Livewire/Tenant/Auth/LoginPopup.php:38-55`

**Impacto:** digitar o telefone de outra pessoa concede acesso total à conta dela — histórico de pedidos, endereço, dados pessoais. Também cria `Customer` automaticamente, permitindo poluir a base com registros arbitrários.

Não é um bug: é o design. O próprio `.sprint/05-buyer-experience/01-review.md` (seção 9.1.5) registrou isso como **"risco alto — login sem verificação (segurança)"** e a sprint foi aprovada assim.

---

### SEC-002 — Middleware `tenant.customer.auth` não bloqueia ninguém (fail-open)

**Severidade:** Alta | **Esforço:** Baixo

O middleware apenas popula o request e chama `$next()` em todos os caminhos. Não existe rejeição.

- `app/Http/Middleware/TenantCustomerAuth.php:20-40`

A proteção real está duplicada no `mount()` de cada componente:
- `app/Livewire/Tenant/Account/Index.php:22-28`
- `app/Livewire/Tenant/Account/Profile.php:25-31`

**Impacto:** qualquer rota nova adicionada ao grupo `tenant.customer.auth` fica desprotegida por padrão. O nome do middleware promete uma garantia que ele não oferece. Além disso, `Account\Index` declara `public Customer $customer` (tipado, não-nullable) e retorna do `mount()` sem inicializá-lo no caminho de falha — risco de `Error: must not be accessed before initialization`.

---

### SEC-003 — Isolamento entre tenants é opt-in (fail-open)

**Severidade:** Alta | **Esforço:** Médio

Não existe global scope, trait `BelongsToTenant` nem observer. O isolamento depende do desenvolvedor lembrar de chamar `->forTenant($id)` manualmente — **41 chamadas** espalhadas pelo código.

- Nenhum `addGlobalScope` no projeto (verificado por grep)
- `app/Models/Product.php:96-99`, `app/Models/Category.php:40-43`, `app/Models/Order.php:64-67` — apenas scope local
- `app/Models/User.php:10-34` — não tem nem `scopeForTenant`

**Queries sem escopo de tenant já existentes:**
- `app/Gateways/MercadoPagoGateway.php:158-160` — `Order::query()->where('meta->mercadopago->payment_id', ...)`
- `app/Gateways/MercadoPagoGateway.php:201-204` — `Payment::query()->where('gateway_payment_id', ...)`
- `app/Http/Controllers/MercadoPagoWebhookController.php:29-31` — idem, em rota sem autenticação

No webhook isso é mitigado por OK-05, mas a busca inicial atinge linhas de qualquer tenant antes da validação.

**Impacto:** um único `::query()` esquecido vaza dados entre lojas. O default é inseguro.

---

### SEC-004 — Ausência total de camada de autorização

**Severidade:** Alta | **Esforço:** Médio

Zero `authorize()`, zero Policy, zero Gate em ~30 componentes Livewire. O middleware `EnsureTenantAdminAuthenticated` autentica mas não autoriza, e **não distingue `tenant_admin` de `tenant_staff`**.

- `app/Http/Middleware/EnsureTenantAdminAuthenticated.php:34-38` — `whereIn('role', ['tenant_admin','tenant_staff'])`, sem diferenciação posterior

Ações destrutivas ou sensíveis sem checagem de papel:
- `app/Livewire/Admin/Products/Index.php:28,39` — `toggleActive()`, `delete()`
- `app/Livewire/Admin/Categories/Index.php:22,33` — idem
- `app/Livewire/Admin/Customers/Index.php:22,33` — idem
- `app/Livewire/Admin/Showcases/Index.php:18,29` — idem
- `app/Livewire/Admin/Orders/Show.php:33` — `updateStatus()`
- `app/Livewire/Admin/Tenant/Settings.php:69` — `save()` altera o **slug do tenant**
- `app/Livewire/Admin/Tenant/PaymentSettings.php:221,236` — grava **credenciais de pagamento**
- `routes/web.php:174-187` — closure que altera `settings` do tenant

**Impacto:** `tenant_staff` pode trocar o slug da loja e alterar credenciais do Mercado Pago.

---

## 4. Achados — Integridade de dados (P1)

### DB-001 — Schema praticamente sem integridade referencial

**2 foreign keys no schema inteiro** (verificado por grep em `database/migrations`). Todo o resto usa apenas `->index()`.

Sem FK: `products.tenant_id`, `categories.tenant_id`, `orders.tenant_id`, `customers.tenant_id`, `order_items.order_id`, `customer_addresses.customer_id`, `users.tenant_id`, `tenant_counters.tenant_id`.

Com FK: `payments` (`tenant_id`, `order_id`), `showcases`, `showcase_product`.

**Impacto:** nada impede registros órfãos ou `tenant_id` inexistente no nível do banco.

### DB-002 — Pivôs sem `tenant_id` e sem FK

- `database/migrations/..._create_category_product_table.php:10-17`
- `database/migrations/..._create_showcase_product_table.php:11-16`

Relações que consomem os pivôs sem escopo:
- `app/Models/Product.php:106-113`, `app/Models/Category.php:50-57`, `app/Models/Showcase.php:87-92`
- `app/Models/Showcase.php:140-143` — `TYPE_CUSTOM` exibiria produto de outro tenant se vinculado

**Impacto:** vinculação cross-tenant não é impedida por constraint alguma.

### DB-003 — Tipos monetários inconsistentes e com risco de overflow

- `products.price_cents` → `unsignedBigInteger` (correto)
- `orders.{subtotal,discount,delivery_fee,total}_cents` → `integer` (32 bits)
- `order_items.{unit_price,total}_cents` → `integer`
- `payments.amount_cents` → `integer`

**Impacto:** teto de ~R$ 21.474.836,47. Inconsistência entre tabelas do mesmo domínio.

### DB-004 — `unique(tenant_id, slug)` com `slug` nullable

Em PostgreSQL múltiplos `NULL` não colidem, permitindo N registros com `slug = NULL` por tenant. Afeta `products`, `categories`, `showcases`.

### DB-005 — Sem soft deletes e sem auditoria

Nenhuma tabela tem `deleted_at`. Deletar um produto ou cliente é irreversível e pode quebrar relatórios históricos. Sem `created_by`/`updated_by` nem histórico de mudança de status de pedido.

### DB-006 — Estoque não existe

`products` não tem coluna de quantidade. `OrderService` não decrementa nada. Qualquer produto pode ser vendido infinitamente.

**Decisão de produto necessária:** o README descreve o sistema como *"order-first"* (encomendas sob demanda), onde estoque pode ser legitimamente desnecessário. **Isso precisa ser decidido explicitamente**, não por omissão.

### DB-007 — `showcases.meta` usa `json` em vez de `jsonb`

`database/migrations/..._create_showcases_table.php:25`. Todas as outras 8 colunas equivalentes usam `jsonb`. Sem motivo técnico aparente.

---

## 5. Achados — Arquitetura (P1)

### ARC-001 — Autenticação ignora o sistema de guards do Laravel

- **Admin:** `session()->put('tenant_admin_user_id', ...)` cru — `app/Http/Controllers/TenantAdminAuthController.php:46`
- **Customer:** cookie + coluna `session_token` — `app/Services/CustomerAuthService.php:31-35`
- `config/auth.php:38-43` — só existe o guard `web` default, não usado

**Impacto:** `auth()->user()`, `Auth::check()`, Policies e `@can` não funcionam. Obriga a reimplementar manualmente o que o framework já oferece — origem direta de SEC-002 e SEC-004. A chave de sessão do admin não é prefixada por tenant, impedindo login simultâneo em lojas diferentes.

### ARC-002 — Tenant como singleton no container

`app/Http/Middleware/ResolveTenantFromPath.php:27` — `app()->instance(Tenant::class, $tenant)`.

Funciona em PHP-FPM (container morre no fim da request), mas é **inseguro em Laravel Octane/Swoole** (container reaproveitado entre requests) e não é populado em queue jobs ou comandos Artisan.

### ARC-003 — Cookies com `secure = false` hardcoded

- `app/Services/CustomerAuthService.php:89` — `false, // secure = false para funcionar em localhost`
- `app/Livewire/Tenant/Auth/LoginPopup.php:67` — idem

**Impacto:** em produção o cookie de sessão trafegaria sem flag `Secure`.

### ARC-004 — Interface de gateway existe mas não é usada

`app/Contracts/PaymentGatewayInterface.php:8-15` está definida, porém:
- `app/Http/Controllers/TenantPaymentController.php:13-15` injeta `MercadoPagoGateway` **concreto**
- `app/Livewire/Tenant/Checkout.php:48` tem IDs de métodos do MP hardcoded
- `routes/web.php:41-42` — rota de webhook específica do MP

### ARC-005 — Webhook não é idempotente

`app/Gateways/MercadoPagoGateway.php:143-223` reprocessa qualquer reenvio (o MP reenvia legitimamente). Sem `lockForUpdate`, sem controle de `processed_at` ou de evento já visto.

### ARC-006 — Credenciais em claro no estado do componente Livewire

`app/Livewire/Admin/Tenant/PaymentSettings.php:58-60` descriptografa `access_token` e `webhook_secret` para exibir em campos de formulário. Propriedades públicas de Livewire são serializadas no payload e trafegam a cada request AJAX.

### ARC-007 — Falta de transação abrangendo pedido + pagamento

`OrderService::createFromCart` é transacional (`app/Services/OrderService.php:21-48`), mas a criação do `Payment` acontece fora dela e **depois** da chamada à API do MP — `app/Gateways/MercadoPagoGateway.php:83-96`. Falha no insert após aprovação do pagamento deixa o pedido inconsistente.

### ARC-008 — Duplicação de lógica de domínio na camada de apresentação

- **Carrinho carregado em dois lugares idênticos:** `app/Livewire/Tenant/Cart.php:24-68` e `app/Livewire/Tenant/Checkout.php:116-158`
- **Subtotal somado em três lugares:** `OrderService.php:114-117`, `Cart.php:124-127`, `Checkout.php:160-163`
- **Regra de pagamento no componente de UI:** `Checkout.php:84-106` filtra métodos com base nas credenciais do MP

Não existe `CartService`.

### ARC-009 — Normalização de telefone inconsistente (gera clientes duplicados)

- `CustomerAuthService.php:71-74` normaliza (`preg_replace` de não-dígitos)
- `OrderService.php:52-54` usa o telefone **cru**

**Impacto:** `(11) 99999-9999` e `11999999999` criam dois `Customer` distintos para a mesma pessoa, e conflitam de formas diferentes com o `unique(tenant_id, phone)`.

### ARC-010 — Status de pedido/pagamento como string solta

`orders.status`, `orders.payment_status` e `payments.status` são strings com default, sem enum e sem CHECK constraint. Existe `App\Enums\PaymentMethod`, mas não `OrderStatus` nem `PaymentStatus` — os literais estão espalhados (`Payment.php:58,68`, `Showcase.php:118`).

### ARC-011 — Tenant hardcoded na raiz

`routes/web.php:37-39` — `Route::get('/', fn () => redirect('/caroldoces'))`. Um slug de cliente específico fixado na raiz de um SaaS.

---

## 6. Achados — Frontend e Design System (P1)

Detalhamento completo e especificação dos componentes em **`03-design-system.md`**. Resumo:

### UI-001 — O padrão visual existe, mas não é executável

Inventário quantitativo de `resources/views`:

| Padrão | Ocorrências | Diagnóstico |
|---|---|---|
| `<h1 class="h4 mb-0">` | 23 | consistente |
| `card shadow-sm` | 33 (+12 com margens variadas) | consistente |
| `btn btn-primary` | 26 | consistente |
| `btn btn-outline-secondary` | 26 | consistente |
| `card-body` escrito à mão | 50 | **sem componente** |

A consistência é mantida por **copy-paste**. Sem componente que a imponha, ela deriva a cada tela nova. Componentes Blade existentes: apenas 6 (`loader`, `inline-loader`, `toast-container`, `contact-modal`, `tenant-header`, `whatsapp-button`) — nenhum deles de layout/formulário.

### UI-002 — Escala tipográfica quebrada

27 `<h1>` → salta direto para `<h5>`/`<h6>`, sem `h2`/`h3`/`h4`. **Seis tratamentos diferentes** para o mesmo nível semântico ("título de seção dentro de card"): `h5 mb-0`, `h6 mb-3`, `h5 mb-3 pb-2 border-bottom`, `h6 fw-semibold mb-3`, `h6 fw-bold mb-2`, `h6 text-muted mb-2`.

Problema de acessibilidade além de estética.

### UI-003 — 49 `style=` inline

Concentrados em `livewire/admin/tenant/customization.blade.php` (11), `livewire/tenant/home.blade.php` (5), `layouts/tenant-admin.blade.php` (5).

### UI-004 — Tailwind 4 instalado e morto

`package.json:10-20` declara `tailwindcss` e `@tailwindcss/vite`, mas `resources/css/app.css:1` só faz `@import "bootstrap/dist/css/bootstrap.min.css"`. Nenhuma diretiva `@tailwind` no projeto; todas as classes das views são Bootstrap 5.3. Indecisão arquitetural não limpa.

### UI-005 — Seis CDNs fora do pipeline Vite

Font Awesome (`layouts/tenant-admin.blade.php:10`, `layouts/app.blade.php:10`), Splide (`layouts/app.blade.php:11,45`), Google Fonts (`partials/tenant-theme.blade.php:28-30`), SortableJS (3 views, com duas estratégias de carregamento diferentes), SDK do Mercado Pago (`livewire/tenant/checkout.blade.php:145`), gtag.js (`partials/tenant-meta.blade.php:43`).

### UI-006 — Duplicação massiva no layout admin

`resources/views/layouts/tenant-admin.blade.php` tem **697 linhas**. Menu desktop (`:95-249`) e menu mobile (`:305-463`) são cópias quase literais — ~150 linhas duplicadas. Mais ~180 linhas de JS inline (`:498-680`) e ~75 linhas de CSS que reescrevem comportamento já existente no Bootstrap.

### UI-007 — Injeção de CSS via tema do tenant

`resources/views/partials/tenant-theme.blade.php:44` — `--tenant-font-family: {{ $fontFamily }};`. O valor vem de `settings.theme.font_family` sem escape para contexto CSS. Mesmo padrão em `livewire/admin/tenant/customization.blade.php:134-138`.

A validação em `app/Livewire/Admin/Tenant/Customization.php:48` restringe a opções conhecidas na escrita, mas a view não deve assumir isso na leitura (defense in depth). Cores são validadas por regex hex; a fonte não.

### UI-008 — `google_analytics_id` sem formato validado

`partials/tenant-meta.blade.php:43-49` injeta o valor em `src` de `<script>` e em `gtag('config', '...')`. Validado apenas como `string|max:50`, sem regex `^G-[A-Z0-9]+$`.

### UI-009 — Uploads sem validação de conteúdo real

`Products/Form.php` (`image`), `Tenant/Customization.php` (`logo`, `banner`, `favicon`), `Tenant/SeoSettings.php` (`og_image`) validam apenas `image` + `max:` (regra baseada no mime declarado pelo cliente) e salvam usando `getClientOriginalExtension()` sem allow-list. Sem verificação de magic bytes.

Pontos positivos: paths são construídos a partir de variáveis controladas (sem path traversal) e isolados em `tenants/{tenantId}/`.

### UI-010 — `innerHTML` com retorno do gateway

`livewire/tenant/checkout.blade.php:253` e adjacentes montam QR code e ticket via `innerHTML`.

### UI-011 — Sem `client_max_body_size` no nginx

`docker/nginx/default.conf` não define a diretiva (default 1MB), enquanto `docker/php/php.ini:1-8` permite 50MB. Uploads entre 1MB e 50MB falham com 413.

---

## 7. Achados — Qualidade e processo (P1/P2)

### QA-001 — Ausência de testes de integração

89 testes, todos unitários, `0.68s` de execução — sinal de que nada toca o fluxo real. Único teste de feature: `tests/Feature/ExampleTest.php:13-19`, que verifica se `/` redireciona.

**Sem cobertura alguma:** checkout E2E, webhook via HTTP, login admin, login cliente, autorização, **acesso cross-tenant**, componentes Livewire, uploads, concorrência no `TenantCounter`.

BUG-001 passou despercebido exatamente por essa lacuna.

### QA-002 — Testes rodam em SQLite, produção é PostgreSQL

`phpunit.xml:20-34` — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. A aplicação usa `jsonb`, operadores `meta->campo` e `lockForUpdate()`, cujo comportamento difere. O teste de OK-03 (contador com lock) não prova nada em SQLite.

### QA-003 — Sem análise estática, sem CI, sem hooks

- `laravel/pint` está em `require-dev` mas **sem `pint.json` e nunca invocado**
- Sem PHPStan/Larastan configurado (sem `phpstan.neon`)
- Sem `.github/workflows/`
- Sem pre-commit hooks
- `composer.json` não tem script `lint`, `format`, `analyse` ou `coverage`

PHPStan nível moderado teria detectado BUG-001.

### QA-004 — Controle de versão praticamente inexistente

**O único arquivo versionado no repositório é `README.md`.** Um commit na história (`b2e778d Initial commit`); todo o `src/` está untracked (221 arquivos). Sem `.gitignore` na raiz.

Todo o projeto existe apenas no disco local. **Este é o risco mais urgente de todos os achados — maior que qualquer item de código.**

### QA-005 — Configuração de ambiente

- `src/.env` existe com `APP_ENV=local`, `APP_DEBUG=true` e credenciais de desenvolvimento (`wl/wl/wl`). Nenhum segredo real. `.gitignore` de `src/` protege `.env` corretamente.
- `src/.env.example:23` sugere `DB_CONNECTION=sqlite` enquanto o Docker roda Postgres — quem copia o exemplo no container usa o banco errado.
- `config/services.php:39` tem `mercadopago.integrator_id` **hardcoded** em vez de `env()`.
- `src/README.md` é o template default do Laravel (inútil).
- `README.md` da raiz está **desatualizado**: descreve pagamento como "manual/simples" e o storefront como pendente, quando ambos existem.

### QA-006 — Dívidas registradas em sprints anteriores e nunca resolvidas

Este é o achado mais importante sobre **processo**. Os documentos das sprints 01–05 identificaram corretamente vários problemas, aprovaram condicionalmente, e os itens nunca foram corrigidos:

| Origem | Item | Status atual |
|---|---|---|
| `01-payment/04-qa-review.md:261-263` | QA-011: model `Payment` criado e não usado | Parcialmente resolvido |
| `01-payment/04-qa-review.md` | QA-005: webhook não valida `x-signature` | **Resolvido** (OK-07) |
| `01-payment/01-review.md:4.1` | Duplicação de criação de Customer/Order entre `Checkout` e `TenantPaymentController` | Ver ARC-008 |
| `01-payment/01-review.md:4.4` | Webhook sem retry/queue/timeout/dead letter | **Aberto** (ARC-005) |
| `03-showcases/04-qa-review.md:96-133` | VAL-001/002: falta validar `tenant_id` em `category_id` e produtos | **Aberto** (DB-002) |
| `03-showcases/04-qa-review.md:147-155` | VAL-004: race condition no limite de 10 vitrines | **Aberto** |
| `03-showcases/04-qa-review.md:222-226` | MEL-006: faltam testes de integração | **Aberto** (QA-001) |
| `05-buyer-experience/01-review.md:9.1.5` | Login sem verificação — "risco alto" | **Aberto** (SEC-001) |
| `05-buyer-experience/01-review.md:9.1.1` | Conflito de sessão entre tenants | Mitigado (cookie por tenant) |

**Conclusão de processo:** "aprovação condicional" vem sendo tratada como "aprovado", e a condição nunca é cumprida. Documentar não é suficiente.

**Consequência direta para a Sprint 06:** todo critério de aceitação desta sprint deve ser **um teste automatizado que falha antes e passa depois**. "Definição de pronto" = teste no CI, não revisão manual.

Um exemplo de por que revisão manual falhou: `tests/Unit/Models/ShowcaseTest.php:163-175` foi reportado como `BUG-001` em `03-showcases/04-qa-review.md:17-48` (asserção `assertCount(10)` com `max_items = 5`). Hoje o teste foi **renomeado** para `test_type_all_ignores_max_items_and_uses_fixed_limit` e documenta o comportamento como intencional. Pode ser correto — mas é preciso confirmar se `TYPE_ALL` ignorar `max_items` é regra de negócio desejada ou se o bug foi normalizado na documentação.

---

## 8. Notas por área

| Área | Nota | Observação |
|---|---|---|
| Multi-tenancy | 4/10 | Estratégia correta, enforcement fail-open |
| Pagamentos | 4/10 | Núcleo sólido (OK-05/06/07), faltam idempotência e transação |
| Modelagem de dados | 4/10 | Acertos em centavos/snapshot; schema sem FK |
| Organização do domínio | 4/10 | Services finas demais, lógica vazando para Livewire |
| Frontend | 4/10 | Padrão por copy-paste, sem design system |
| Segurança | 5/10 | Sem XSS clássico; falha em authz e auth de cliente |
| Testes | 3,5/10 | Unitários decentes, integração inexistente |
| Tooling/Infra | 2,5/10 | Docker bom; sem CI, sem análise estática |
| Documentação | 4/10 | `.sprint` rico, mas README desatualizado |

**Nenhuma área recebeu nota que justifique descarte.** O padrão é consistente: fundações corretas, camadas transversais ausentes.

---

## 9. Priorização recomendada

| Fase | Itens | Justificativa |
|---|---|---|
| **F0 — Imediato** | QA-004, BUG-001 | Versionar antes de qualquer alteração; corrigir bug crítico com teste que o prova |
| **F1 — Fundação de segurança** | SEC-001..004, ARC-001..003 | Inverter os defaults de fail-open para fail-closed. Bloqueia todo o resto |
| **F2 — Integridade de dados** | DB-001..007 | Janela aberta: sem dados de produção, migrations podem ser corrigidas no lugar |
| **F3 — Domínio** | ARC-004..010 | `CartService`, enums, idempotência, transação |
| **F4 — Design System** | UI-001..006 | Base para todo desenvolvimento de UI futuro |
| **F5 — Segurança de UI** | UI-007..011 | Escape de CSS, uploads, nginx |
| **F6 — Qualidade** | QA-001..003, QA-005 | CI, PHPStan, testes de integração, Postgres nos testes |

**F2 tem urgência não-óbvia:** é a única fase cuja dificuldade cresce de forma irreversível. Enquanto não há dados de produção, corrigir o schema é editar migrations e recriar o banco. Depois do primeiro cliente real, cada correção vira migration de migração de dados com downtime.

---

## 10. Decisões de produto — FECHADAS

Resolvidas em **`04-decisions.md`**, com racional completo. Resumo e impacto nos achados desta auditoria:

| Decisão | Resultado | Achados afetados |
|---|---|---|
| Estoque | **Ambas as verticais.** `track_inventory` opt-in **por produto** | DB-006 deixa de ser lacuna e vira entregável E2.7 |
| Login do cliente | **Fator mantido** (risco aceito e documentado); encanamento corrigido | SEC-001 vira risco registrado; SEC-002 continua sendo corrigido |
| Papéis | **`tenant_staff` removido**; nova dimensão plataforma vs tenant | SEC-004 ganha escopo; ARC-001 ganha restrição |
| `TYPE_ALL`/`max_items` | **Passa a respeitar** `max_items`, teto 100 | Fecha o item herdado de `03-showcases/04-qa-review.md` |
| Gateways | **Múltiplos gateways**, abstração com investimento completo | ARC-004 vira entregável pleno |

### 10.1 Reframe do produto

As respostas revelaram um produto substancialmente maior do que este repositório documenta: **plataforma SaaS multi-vertical** (petshop com estoque, doceria sob encomenda), com **admin de plataforma**, **planos**, **impersonation** e **gateways plugáveis** — não "uma loja de encomendas com um tenant hardcoded na raiz".

Isso **reforça** a conclusão da seção 11. Um produto com essa ambição não sustenta isolamento de tenant opt-in, zero autorização e zero teste de integração.

Três consequências que incidem **agora**, detalhadas em `04-decisions.md` §7.2:

1. **`users.tenant_id` deve permanecer `nullable`** — admin de plataforma não pertence a tenant. O item DB-001 ("adicionar FKs em todo o schema"), se executado sem essa ressalva, tornaria a coluna `NOT NULL` e bloquearia o roadmap silenciosamente.
2. **O global scope precisa nascer com três contextos** (tenant, plataforma, impersonation). Implementá-lo assumindo tenant sempre presente exigiria refatoração transversal em todos os models depois.
3. **Impersonation torna o log de auditoria pré-requisito**, não melhoria — o que eleva a prioridade de DB-005 além do P1 genérico atribuído aqui.

---

## 11. Conclusão

O projeto está em um estado incomum: **as partes difíceis estão certas e as fáceis estão ausentes**. Quem escreveu isto entendeu snapshot de pedido, concorrência em numeração sequencial, dinheiro em inteiro e validação de webhook em profundidade — e ao mesmo tempo não adicionou uma foreign key, um global scope ou um teste de integração.

Isso é o perfil típico de um projeto construído sprint a sprint por feature, sem nenhuma parada para consolidar camadas transversais. O resultado não é código ruim: é código **sem fundação**.

A Sprint 06 não deve entregar nenhuma feature nova. Deve construir a fundação que permite que as features das sprints 07+ sejam escritas com segurança — e, principalmente, deve estabelecer que **um item só está pronto quando existe um teste que provaria a regressão**.

Plano de execução em `02-sprint.md`. Especificação do design system em `03-design-system.md`.
