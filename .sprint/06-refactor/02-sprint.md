# Planejamento da Sprint 06 — Refactor & Hardening

**Sprint:** 06-refactor
**Tipo:** Dívida técnica / Fundação (nenhuma feature nova)
**Base:** `01-review.md` (auditoria técnica completa)
**Design System:** `03-design-system.md`

---

## 1. Objetivos da Sprint

### 1.1 Objetivo Principal

Construir as camadas transversais ausentes — isolamento de tenant, autenticação, autorização, integridade de dados, design system e rede de testes — de forma que as sprints 07+ possam entregar features sem reintroduzir as mesmas classes de defeito.

### 1.2 Objetivos Específicos

- Inverter todos os defaults de segurança de **fail-open** para **fail-closed**
- Corrigir o schema enquanto ainda não existem dados de produção
- Eliminar a duplicação de lógica de domínio que vazou para a camada Livewire
- Substituir a padronização visual por copy-paste por uma biblioteca de componentes Blade
- Estabelecer CI com testes de integração, análise estática e formatação

### 1.3 Princípio de execução (não-negociável)

> **Um item só está PRONTO quando existe um teste automatizado que falha antes da correção e passa depois.**

Justificativa em `01-review.md` seção 7 (QA-006): as sprints 01–05 registraram corretamente vários destes mesmos problemas, foram aprovadas condicionalmente, e as condições nunca foram cumpridas. Revisão manual como critério de conclusão já falhou neste projeto cinco vezes.

Consequências práticas:
- Nenhum item é marcado como concluído sem o teste correspondente commitado.
- Nenhum item é marcado como concluído sem o CI verde.
- A coluna "Prova" de cada entregável abaixo é obrigatória, não opcional.

### 1.4 Métricas de Sucesso

| Métrica | Atual | Meta |
|---|---|---|
| Arquivos versionados no git | 1 | 100% do projeto |
| Foreign keys no schema | 2 | Todas as relações |
| Testes de feature/integração | 1 | ≥ 40 |
| Testes de isolamento cross-tenant | 0 | ≥ 8 |
| Chamadas `forTenant()` manuais necessárias | 41 | 0 (global scope) |
| Ações admin sem `authorize()` | ~30 | 0 |
| Componentes Blade de layout/form | 0 | ≥ 8 |
| `style=` inline nas views | 49 | ≤ 5 |
| Banco de testes | SQLite | PostgreSQL |
| CI | inexistente | verde e obrigatório |
| Nível PHPStan | — | ≥ 5 sem erros |

---

## 2. Escopo

### 2.1 Incluído

- Versionamento do projeto (F0)
- Correção do BUG-001 (`customer_id`)
- Trait `BelongsToTenant` com global scope
- Guards Laravel para admin e customer; middlewares que bloqueiam
- Policies e `authorize()` em todas as ações de admin
- OTP no login do cliente **ou** redução do escopo da área do cliente (decisão 10.2)
- Correção das migrations existentes: FKs, `tenant_id` em pivôs, tipos monetários, soft deletes
- Enums `OrderStatus` e `PaymentStatus`
- `CartService` eliminando triplicação de cálculo
- Normalização unificada de telefone
- Idempotência do webhook e transação abrangendo pedido + pagamento
- Biblioteca de componentes Blade + escala tipográfica e de espaçamento
- Remoção do Tailwind morto; consolidação de CDNs no Vite
- Escape de CSS no tema do tenant; hardening de uploads
- CI (GitHub Actions) com PHPUnit, Pint e PHPStan
- Migração dos testes para PostgreSQL
- Playwright para E2E do checkout e visual regression (após o design system)
- Atualização do `README.md`

### 2.2 Fora do Escopo

- Qualquer feature nova (cupons, notificações, PWA, fidelidade — ver roadmap em `05-buyer-experience/01-review.md`)
- Segundo gateway de pagamento (apenas desacoplar, não implementar)
- Controle de estoque, **exceto** se a decisão 10.1 determinar que o produto precisa dele
- Redesign visual (o objetivo é **padronizar** o visual existente, não redesenhá-lo)
- Laravel Octane
- Domínio customizado por tenant
- Migração de Bootstrap para outro framework CSS

### 2.3 Premissa habilitadora

O sistema **nunca foi para produção**; não há dados reais. Portanto:

- Migrations podem ser **editadas no lugar** e o banco recriado via `migrate:fresh`, em vez de empilhar migrations de correção.
- Não há necessidade de scripts de migração de dados, janela de manutenção ou retrocompatibilidade.
- Nenhum achado é incidente ativo — todos são dívida técnica.

**Esta premissa expira no primeiro cliente real.** É o principal motivo para a fase F2 acontecer agora.

---

## 3. Entregáveis

### F0 — Fundação de trabalho

#### E0.1 Versionamento (QA-004)

| Item | Detalhe |
|---|---|
| Problema | Único arquivo versionado é `README.md`; 221 arquivos untracked; sem `.gitignore` na raiz |
| Ação | `.gitignore` na raiz (`.DS_Store`, `docker/**/.DS_Store`); commit de todo o projeto; push para o remoto |
| **Prova** | `git status --porcelain` vazio (exceto ignorados); `git ls-files \| wc -l` > 200; branch remoto atualizado |

> Este é o primeiro item da sprint e pré-requisito de todos os outros. Refatorar sem histórico de versão não é refatorar, é reescrever sem rede.

#### E0.2 Ambiente de testes em PostgreSQL (QA-002)

| Item | Detalhe |
|---|---|
| Problema | `phpunit.xml:20-34` usa SQLite in-memory; produção usa PG com `jsonb`, `meta->campo` e `lockForUpdate()` |
| Ação | Serviço `db_test` no `docker-compose.yml`; `phpunit.xml` apontando para PG; `.env.testing` |
| **Prova** | Suíte atual (89 testes) verde em PostgreSQL; teste de `lockForUpdate` no `TenantCounter` sob concorrência |

#### E0.3 CI e análise estática (QA-003)

| Item | Detalhe |
|---|---|
| Ação | `.github/workflows/ci.yml` (PHPUnit + Pint `--test` + PHPStan); `pint.json`; `phpstan.neon` nível 5; scripts `lint`, `format`, `analyse`, `test` no `composer.json` |
| **Prova** | CI verde no push; PHPStan nível 5 sem erros; `pint --test` sem diferenças |

> PHPStan entra em F0 de propósito: BUG-001 (passar chave não-fillable para `create()`) é exatamente a classe de erro que ele detecta. Ter a ferramenta antes das correções aumenta o retorno de todas as fases seguintes.

#### E0.4 BUG-001 — `customer_id`

| Item | Detalhe |
|---|---|
| Problema | `app/Models/Order.php:17-38` não tem `customer_id` no `$fillable`; `OrderService.php:26-42` envia o campo. Confirmado empiricamente: grava `NULL` |
| Ação | Adicionar `customer_id` ao `$fillable` |
| **Prova** | Teste que cria pedido via `OrderService::createFromCart` e assertivamente verifica `$order->fresh()->customer_id` não-nulo **e** igual ao customer resolvido |

---

### F1 — Fundação de segurança

#### E1.1 Trait `BelongsToTenant` com global scope (SEC-003)

| Item | Detalhe |
|---|---|
| Problema | Isolamento opt-in via 41 chamadas manuais de `forTenant()`; nenhum global scope |
| Ação | Trait `App\Models\Concerns\BelongsToTenant` com `addGlobalScope` lendo o tenant do contexto; auto-preenchimento de `tenant_id` no `creating`; aplicar em `Product`, `Category`, `Order`, `OrderItem`, `Customer`, `CustomerAddress`, `Payment`, `Showcase`, `User`, `TenantCounter`; escopo explícito `withoutTenantScope()` para o webhook |
| **Prova** | Teste que cria dados em 2 tenants e verifica que `Model::all()` retorna só os do tenant ativo, **para cada model**; teste que `create()` sem `tenant_id` preenche automaticamente; teste que `withoutTenantScope()` exige `tenant_id` explícito |

> Depois desta entrega, as 41 chamadas de `forTenant()` tornam-se redundantes. Remover na E1.2, não aqui, para manter os diffs auditáveis.

#### E1.2 Limpeza dos `forTenant()` redundantes

| Item | Detalhe |
|---|---|
| Ação | Remover as chamadas manuais agora cobertas pelo global scope; manter `scopeForTenant` apenas onde há consulta deliberadamente cross-tenant |
| **Prova** | Suíte completa verde após remoção; testes de E1.1 continuam passando |

#### E1.3 Contexto de tenant request-bound (ARC-002)

| Item | Detalhe |
|---|---|
| Problema | `ResolveTenantFromPath.php:27` usa `app()->instance(Tenant::class, ...)` — inseguro em Octane, ausente em jobs |
| Ação | Classe `App\Support\TenantContext` (scoped) com `set()`/`get()`/`forget()`/`runFor()`; middleware popula e limpa; base para o global scope de E1.1 |
| **Prova** | Teste de que duas resoluções sequenciais de tenants diferentes não vazam contexto; teste de `runFor()` isolando contexto em closure |

#### E1.4 Guards Laravel para admin e customer (ARC-001)

| Item | Detalhe |
|---|---|
| Problema | Admin usa `session()->put('tenant_admin_user_id')` cru; customer usa cookie + coluna `session_token`; `config/auth.php:38-43` tem só o guard `web` não-usado |
| Ação | Guard `tenant_admin` (session, provider `User`, escopado por tenant) e guard `customer` (provider `Customer`); refatorar `TenantAdminAuthController` e `CustomerAuthService` para usar `Auth::guard(...)`; prefixar chave de sessão do admin por tenant |
| **Prova** | Testes de feature: login admin válido; admin do tenant A recebe 403/redirect no admin do tenant B; login simultâneo em dois tenants no mesmo browser funciona; `Auth::guard('customer')->user()` disponível nas views |

#### E1.5 Middlewares que bloqueiam (SEC-002)

| Item | Detalhe |
|---|---|
| Problema | `TenantCustomerAuth.php:20-40` chama `$next()` em todos os caminhos; proteção real duplicada no `mount()` de cada componente |
| Ação | Middleware rejeita não-autenticado; remover as checagens duplicadas de `Account/Index.php:22-28` e `Account/Profile.php:25-31`; corrigir propriedade tipada `public Customer $customer` não-inicializada |
| **Prova** | Teste de feature: GET `/{tenant}/conta` sem cookie → redirect/401; com cookie válido → 200; com cookie de outro tenant → redirect. Teste de que nenhuma rota do grupo alcança o componente sem autenticação |

#### E1.6 Policies e autorização (SEC-004)

| Item | Detalhe |
|---|---|
| Problema | Zero `authorize()` em ~30 componentes; `tenant_staff` == `tenant_admin` |
| Ação | Enum `Role`; Policies para `Product`, `Category`, `Customer`, `Order`, `Showcase`, `Tenant`; `authorize()` em toda action pública de admin (lista em `01-review.md` SEC-004); mover a closure de `routes/web.php:174-187` para controller/componente com autorização; `@can` nos menus |
| **Prova** | Para cada ação destrutiva/sensível: teste de que `tenant_staff` recebe 403 e `tenant_admin` sucede. Cobertura obrigatória de `Settings::save()` (slug) e `PaymentSettings::saveIntegrations()` (credenciais) |

#### E1.7 Login de cliente (SEC-001)

Depende da decisão **10.2**.

| Caminho | Ação | Prova |
|---|---|---|
| **A — OTP** | Código de 6 dígitos por WhatsApp/SMS, tabela `customer_login_codes` (expiração, tentativas, rate limit), verificação antes de emitir sessão | Teste: login exige código válido; código expirado rejeitado; rate limit após N tentativas; telefone sozinho nunca autentica |
| **B — Escopo reduzido** | Manter login fraco, mas remover endereço e dados pessoais da área do cliente; documentar a limitação | Teste: área do cliente não expõe endereço nem e-mail; `/conta` só mostra pedidos com dados já conhecidos pelo comprador |

> Caminho B é aceitável para MVP, mas precisa ser uma **decisão registrada**, não uma omissão. Hoje é omissão.

#### E1.8 Cookies seguros por ambiente (ARC-003)

| Item | Detalhe |
|---|---|
| Problema | `secure = false` hardcoded em `CustomerAuthService.php:89` e `LoginPopup.php:67` |
| Ação | Derivar de `config('session.secure')` / `App::environment()`; `SameSite=Lax`; revisar `config/session.php` |
| **Prova** | Teste de que em ambiente de produção o cookie é emitido com flag `Secure`, e sem ela em local |

---

### F2 — Integridade de dados

> **Abordagem:** editar as migrations existentes e recriar o banco com `migrate:fresh`. Sem dados de produção (2.3), não há motivo para migrations de correção empilhadas. Isto também elimina o retrabalho visível no histórico (`add_customer_id_to_orders_table`, `add_tenant_and_role_to_users_table`).

#### E2.1 Foreign keys em todo o schema (DB-001)

| Item | Detalhe |
|---|---|
| Problema | 2 FKs no schema inteiro |
| Ação | FK em `products.tenant_id`, `categories.tenant_id`, `orders.tenant_id`, `orders.customer_id`, `order_items.tenant_id`, `order_items.order_id`, `customers.tenant_id`, `customer_addresses.{tenant_id,customer_id}`, `users.tenant_id`, `tenant_counters.tenant_id`; políticas `onDelete` explícitas por relação |
| **Prova** | Teste de que inserir `tenant_id` inexistente lança violação de FK; teste de comportamento de cascade/restrict por relação |

#### E2.2 `tenant_id` e FKs nos pivôs (DB-002 / VAL-001,002 da sprint 03)

| Item | Detalhe |
|---|---|
| Problema | `category_product` e `showcase_product` sem `tenant_id` e sem FK; `Showcase.php:140-143` exibiria produto de outro tenant |
| Ação | `tenant_id` + FKs + unique composta nos dois pivôs; restringir `belongsToMany` em `Product::categories()`, `Category::products()`, `Showcase::products()`; validar `tenant_id` nos formulários com `Rule::exists` escopado |
| **Prova** | Teste que tenta vincular produto do tenant A a categoria do tenant B → rejeitado no banco **e** no formulário; teste de que vitrine `TYPE_CUSTOM` nunca retorna produto de outro tenant |

#### E2.3 Tipos monetários consistentes (DB-003)

| Item | Detalhe |
|---|---|
| Problema | `orders.*_cents`, `order_items.*_cents`, `payments.amount_cents` são `integer` (teto ~R$ 21,4M); `products.price_cents` é `unsignedBigInteger` |
| Ação | Padronizar todas as colunas monetárias como `unsignedBigInteger` |
| **Prova** | Teste com valor acima de 2^31 persistindo e relendo corretamente em todas as tabelas monetárias |

#### E2.4 Unique de slug e `json`→`jsonb` (DB-004, DB-007)

| Item | Detalhe |
|---|---|
| Ação | Slug `NOT NULL` com geração garantida, ou unique index parcial (`WHERE slug IS NOT NULL`); `showcases.meta` de `json` para `jsonb` |
| **Prova** | Teste de que dois registros com slug nulo/duplicado no mesmo tenant são rejeitados; teste de query com operador `meta->` em `showcases` |

#### E2.5 Soft deletes (DB-005)

| Item | Detalhe |
|---|---|
| Ação | `softDeletes()` e trait nas tabelas de negócio (`products`, `categories`, `customers`, `showcases`); avaliar `orders` (provável que não deva permitir delete); revisar interação com global scope e uniques |
| **Prova** | Teste de que deletar produto preserva itens de pedidos históricos; teste de que registro deletado não aparece em listagens nem no storefront |

#### E2.6 Enums de status (ARC-010)

| Item | Detalhe |
|---|---|
| Problema | `orders.status`, `orders.payment_status`, `payments.status` são strings soltas; literais espalhados em `Payment.php:58,68`, `Showcase.php:118` |
| Ação | Enums `OrderStatus` e `PaymentStatus` (seguindo o padrão de `App\Enums\PaymentMethod`); casts nos models; CHECK constraint nas migrations; substituir literais |
| **Prova** | Teste de que status inválido é rejeitado pelo cast e pelo banco; PHPStan sem erros de comparação de string com enum |

#### E2.7 Estoque — condicional

Depende da decisão **10.1**. Se o produto exigir inventário: `track_inventory` + `stock_quantity` em `products`, decremento com `lockForUpdate` no fechamento do pedido.
**Prova:** teste de venda concorrente do último item — apenas um pedido conclui.
Se a decisão for "order-first sem estoque": registrar no `README.md` e encerrar o item.

---

### F3 — Domínio

#### E3.1 `CartService` (ARC-008)

| Item | Detalhe |
|---|---|
| Problema | Carrinho carregado identicamente em `Cart.php:24-68` e `Checkout.php:116-158`; subtotal somado em 3 lugares (`OrderService.php:114-117`, `Cart.php:124-127`, `Checkout.php:160-163`) |
| Ação | `App\Services\CartService` como única fonte de verdade: leitura da sessão, resolução de linhas, cálculo de totais, add/increment/decrement/remove/clear; `Cart`, `Checkout` e `OrderService` passam a consumi-lo |
| **Prova** | Testes unitários do `CartService`; teste de que `Cart` e `Checkout` exibem o mesmo total para o mesmo carrinho; teste de que produto inativo ou de outro tenant é excluído das linhas |

#### E3.2 Normalização unificada de telefone (ARC-009)

| Item | Detalhe |
|---|---|
| Problema | `CustomerAuthService.php:71-74` normaliza; `OrderService.php:52-54` usa cru → clientes duplicados |
| Ação | Helper/value object único de telefone, usado por ambos e na validação dos formulários |
| **Prova** | Teste de que `(11) 99999-9999` e `11999999999` resolvem para o **mesmo** `Customer`; teste do unique `(tenant_id, phone)` sob ambos os formatos |

#### E3.3 Idempotência do webhook (ARC-005)

| Item | Detalhe |
|---|---|
| Problema | `MercadoPagoGateway.php:143-223` reprocessa qualquer reenvio; o MP reenvia legitimamente |
| Ação | Tabela/coluna de eventos processados (id do evento + status); `lockForUpdate` no pedido; early-return quando o estado não muda; validação de assinatura **antes** de qualquer consulta (`MercadoPagoWebhookController.php:28-44`) |
| **Prova** | Teste de que o mesmo webhook entregue 3× produz uma única transição de estado; teste de que assinatura inválida é rejeitada **mesmo quando o pedido não existe**; teste de webhooks concorrentes |

#### E3.4 Transação abrangendo pedido + pagamento (ARC-007)

| Item | Detalhe |
|---|---|
| Problema | `Payment` criado fora da transação e **depois** da chamada à API do MP (`MercadoPagoGateway.php:83-96`) |
| Ação | Registrar a tentativa de `Payment` antes da chamada externa; consolidar estado após resposta; definir compensação para falha pós-aprovação |
| **Prova** | Teste com falha simulada após resposta de aprovação do MP — sistema não fica com pedido inconsistente e o incidente é registrado |

#### E3.5 Desacoplamento do gateway (ARC-004)

Depende da decisão **10.5**.

| Item | Detalhe |
|---|---|
| Problema | `TenantPaymentController.php:13-15` injeta classe concreta; `Checkout.php:48` tem IDs do MP hardcoded; rota de webhook específica |
| Ação (se manter multi-gateway) | Injetar `PaymentGatewayInterface` via resolver por tenant; remover IDs hardcoded; rota de webhook genérica `/webhooks/{gateway}` |
| Ação (se abandonar) | Remover a interface e assumir acoplamento deliberado, documentado |
| **Prova** | Teste com gateway fake implementando a interface, exercitando checkout de ponta a ponta sem tocar o código do MP |

#### E3.6 Credenciais write-only no admin (ARC-006)

| Item | Detalhe |
|---|---|
| Problema | `PaymentSettings.php:58-60` descriptografa `access_token` e `webhook_secret` para campos de formulário; propriedades públicas Livewire são serializadas no payload |
| Ação | Campos write-only; exibir apenas máscara (`••••1234`); não popular o estado do componente com segredo; revisar `testMercadoPagoConnection` (`:256-274`) |
| **Prova** | Teste de que o payload/estado do componente não contém o token em claro; teste de que salvar sem alterar o campo preserva a credencial existente |

---

### F4 — Design System

Especificação completa em **`03-design-system.md`**. Resumo dos entregáveis:

#### E4.1 Escala tipográfica e de espaçamento (UI-002)

| Item | Detalhe |
|---|---|
| Problema | 27 `<h1>` saltando para `<h5>`/`<h6>` sem h2–h4; **6 tratamentos** distintos para "título de seção em card" |
| Ação | Definir escala; corrigir hierarquia semântica; unificar tratamento de título de seção |
| **Prova** | Grep de classes de heading retorna conjunto fechado e documentado; auditoria de a11y sem salto de nível |

#### E4.2 Biblioteca de componentes Blade (UI-001)

| Item | Detalhe |
|---|---|
| Problema | 50 `card-body` à mão, 33 `card shadow-sm`, 26+26 variantes de botão — consistência por copy-paste, sem componente que a imponha |
| Ação | `<x-card>`, `<x-page-header>`, `<x-section-title>`, `<x-form-field>`, `<x-empty-state>`, `<x-data-table>`, `<x-btn>`, `<x-confirm-button>`; migrar as views |
| **Prova** | Contagem de `class="card` e `card-body` escritos à mão cai a ~0; nenhuma view de CRUD admin com mais de N linhas; screenshots equivalentes antes/depois (E4.5) |

#### E4.3 Quebra do layout admin (UI-006)

| Item | Detalhe |
|---|---|
| Problema | `layouts/tenant-admin.blade.php` com 697 linhas; menus desktop (`:95-249`) e mobile (`:305-463`) duplicados (~150 linhas); ~180 linhas de JS inline |
| Ação | Estrutura de navegação única em PHP renderizada por `<x-admin-nav>`; JS para módulo Vite; remover CSS que reescreve Bootstrap |
| **Prova** | Layout abaixo de 150 linhas; adicionar um item de menu requer alterar **um** lugar (teste de que ele aparece em ambos os menus) |

#### E4.4 Limpeza da stack de assets (UI-003, UI-004, UI-005)

| Item | Detalhe |
|---|---|
| Ação | Remover `tailwindcss` e `@tailwindcss/vite` do `package.json` (mortos — `app.css:1` só importa Bootstrap); trazer Font Awesome, Splide e SortableJS para o Vite; unificar a estratégia dupla de carregamento do SortableJS; mover `public/js/carousel.js` para o bundle; eliminar os 49 `style=` inline; remover `routes/web.php:37-39` (`redirect('/caroldoces')`) |
| **Prova** | `npm run build` sem dependência não-utilizada; grep de `<script src="https://` retorna apenas o SDK do Mercado Pago (que precisa ser externo); `style=` ≤ 5 |

#### E4.5 Playwright — E2E e visual regression

> **Ordem importa:** Playwright entra **depois** de E4.1–E4.4. Rodar antes apenas capturaria um baseline da inconsistência atual. Visual regression previne *desvio futuro*; não descobre qual deveria ser o padrão.

| Item | Detalhe |
|---|---|
| Ação | Playwright no projeto; specs E2E do **checkout completo** (storefront → carrinho → checkout → pedido criado) e do login admin; snapshots de visual regression de todas as telas admin e storefront, congelados após o design system; integrar ao CI |
| **Prova** | E2E do checkout verde no CI; alteração de componente que mude o visual falha a suíte de snapshots |

> Nota de custo/benefício: a maior parte da lógica é coberta mais barato por testes de feature Livewire (F1/F6) do que por Playwright. O valor único do Playwright aqui é (a) o checkout de ponta a ponta com o Brick do MP e (b) travar o design system. Não usar Playwright para testar regra de negócio.

---

### F5 — Segurança de UI

#### E5.1 Escape de CSS no tema do tenant (UI-007)

| Item | Detalhe |
|---|---|
| Problema | `partials/tenant-theme.blade.php:44` interpola `$fontFamily` direto em CSS; mesmo padrão em `customization.blade.php:134-138`. Cores têm regex hex; a fonte não |
| Ação | Sanitizar/allow-list na leitura (defense in depth, sem assumir a validação de escrita de `Customization.php:48`); helper único de valor CSS seguro |
| **Prova** | Teste com `font_family` malicioso (`"a"; } body { background: url(...) } /*`) persistido diretamente no banco → CSS renderizado permanece inerte |

#### E5.2 `google_analytics_id` validado (UI-008)

| Item | Detalhe |
|---|---|
| Problema | `partials/tenant-meta.blade.php:43-49` injeta o valor em `src` de `<script>` e em `gtag()`; validado só como `string\|max:50` |
| Ação | Regex `^G-[A-Z0-9]{4,20}$` na validação e na renderização |
| **Prova** | Teste com valor malicioso no banco → nenhum script injetado |

#### E5.3 Hardening de uploads (UI-009)

| Item | Detalhe |
|---|---|
| Problema | `Products/Form.php`, `Tenant/Customization.php`, `Tenant/SeoSettings.php` validam só `image` + `max:` (mime declarado pelo cliente) e salvam com `getClientOriginalExtension()` sem allow-list |
| Ação | `mimes:` explícito, verificação de magic bytes via `getMimeType()`, extensão derivada do mime real, limites de dimensão, nome com hash |
| **Prova** | Teste de upload de PHP renomeado para `.jpg` → rejeitado; teste de que a extensão gravada vem do conteúdo real, não do nome |

#### E5.4 `innerHTML` no checkout (UI-010)

| Item | Detalhe |
|---|---|
| Problema | `livewire/tenant/checkout.blade.php:253` e adjacentes montam QR/ticket via `innerHTML` |
| Ação | Criar nós de texto / `textContent`; renderizar QR de forma controlada |
| **Prova** | Teste E2E com payload de gateway contendo HTML → não é interpretado |

#### E5.5 `client_max_body_size` no nginx (UI-011)

| Item | Detalhe |
|---|---|
| Problema | `docker/nginx/default.conf` sem a diretiva (default 1MB) vs `docker/php/php.ini` com 50MB |
| Ação | `client_max_body_size 50M;` alinhado ao PHP |
| **Prova** | Upload de imagem de ~5MB conclui sem 413 |

---

### F6 — Qualidade e documentação

#### E6.1 Testes de integração (QA-001)

| Item | Detalhe |
|---|---|
| Problema | 89 testes, todos unitários, `0.68s`; único teste de feature verifica redirect da raiz |
| Ação | Testes de feature/Livewire para: checkout E2E, webhook via HTTP, login admin, login cliente, CRUDs admin, uploads, **acesso cross-tenant em toda rota autenticada** |
| **Prova** | ≥ 40 testes de feature; ≥ 8 de isolamento cross-tenant; suíte verde em PostgreSQL no CI |

#### E6.2 Revisão de dívidas herdadas (QA-006)

| Item | Detalhe |
|---|---|
| Ação | Fechar formalmente cada item aberto das sprints 01–05 (tabela em `01-review.md` §7): VAL-004 (race condition no limite de 10 vitrines), webhook sem retry/dead-letter, e confirmar a decisão 10.4 sobre `TYPE_ALL`/`max_items` em `ShowcaseTest.php:163-175` |
| **Prova** | Cada item com teste ou decisão registrada; nenhum item herdado sem status explícito |

#### E6.3 Configuração e documentação (QA-005)

| Item | Detalhe |
|---|---|
| Ação | `.env.example` alinhado ao Docker (`DB_CONNECTION=pgsql`, `DB_HOST=db`), `APP_DEBUG=false` como default; mover `mercadopago.integrator_id` de `config/services.php:39` para `env()`; atualizar o `README.md` da raiz (descreve pagamento como "manual" e storefront como pendente — ambos existem); substituir o `src/README.md` default do Laravel; healthcheck e `depends_on` com `condition` no `docker-compose.yml` |
| **Prova** | Setup limpo a partir do `README` em máquina zerada funciona; nenhum segredo ou ID de integração hardcoded no código |

---

## 4. Dependências entre fases

```
E0.1 (git) ──> tudo

E0.2 (PG) ──> E0.3 (CI) ──> todas as "Provas"

E1.3 (TenantContext) ──> E1.1 (global scope) ──> E1.2 (limpeza)
                                    │
                                    └──> E2.* (schema)

E1.4 (guards) ──> E1.5 (middleware) ──> E1.6 (policies) ──> E1.7 (OTP)

E2.1/E2.2 (FKs) ──> E2.5 (soft deletes)
E2.6 (enums) ──> E3.3 (idempotência)

E3.1 (CartService) ──> E4.2 (componentes de checkout/carrinho)

E4.1 (escala) ──> E4.2 (componentes) ──> E4.3 (layout) ──> E4.5 (Playwright)
E4.4 (assets) ──> E4.5
```

**Caminho crítico:** `E0.1 → E0.2 → E0.3 → E1.3 → E1.1 → E1.4 → E1.6`.

**Regra de ordem inegociável:** F1 antes de F2. Aplicar o global scope depois de mexer no schema obrigaria a revalidar todas as migrations.

---

## 5. Sequenciamento sugerido

| Bloco | Entregáveis | Resultado ao final do bloco |
|---|---|---|
| 1 | E0.1, E0.4, E0.2, E0.3 | Projeto versionado, CI verde, bug crítico corrigido com teste, testes em PG |
| 2 | E1.3, E1.1, E1.2 | Isolamento de tenant fail-closed e provado por teste |
| 3 | E1.4, E1.5, E1.6, E1.8 | Auth e authz sobre o framework, com testes cross-tenant |
| 4 | E1.7 | Decisão 10.2 implementada |
| 5 | E2.1–E2.6 (+E2.7) | Schema íntegro, recriado via `migrate:fresh` |
| 6 | E3.1, E3.2 | Domínio sem duplicação |
| 7 | E3.3, E3.4, E3.6, E3.5 | Pagamentos idempotentes e consistentes |
| 8 | E4.1, E4.2, E4.3, E4.4 | Design system aplicado |
| 9 | E4.5, E5.1–E5.5 | Playwright e hardening de UI |
| 10 | E6.1, E6.2, E6.3 | Rede de testes completa e documentação verdadeira |

---

## 6. Critérios de conclusão da sprint

A sprint só está encerrada quando **todos** os itens abaixo são verdadeiros:

- [ ] Projeto 100% versionado e no remoto
- [ ] CI obrigatório e verde: PHPUnit (PostgreSQL) + Pint + PHPStan ≥ 5 + Playwright
- [ ] Nenhuma query de model capaz de ignorar o tenant sem opt-out explícito
- [ ] Nenhuma action de admin sem `authorize()`
- [ ] `tenant_staff` e `tenant_admin` com permissões efetivamente distintas e testadas
- [ ] Nenhum middleware de autenticação que permita passagem de não-autenticado
- [ ] Todas as relações do schema com FK; pivôs com `tenant_id`
- [ ] Todas as colunas monetárias em `unsignedBigInteger`
- [ ] Status de pedido/pagamento como enum com CHECK constraint
- [ ] Cálculo de carrinho em um único lugar
- [ ] Webhook idempotente, com assinatura validada antes de qualquer consulta
- [ ] Nenhuma credencial em claro no estado de componente Livewire
- [ ] Biblioteca de componentes Blade em uso; ≤ 5 `style=` inline
- [ ] Tailwind removido; CDNs consolidados no Vite
- [ ] ≥ 40 testes de feature, ≥ 8 de isolamento cross-tenant
- [ ] E2E do checkout verde
- [ ] Todo item herdado das sprints 01–05 com status explícito
- [ ] `README.md` refletindo a realidade do sistema
- [ ] As 5 decisões de produto da seção 10 registradas

---

## 7. Riscos

| # | Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|---|
| R1 | Global scope quebra queries legítimas cross-tenant (webhook) | Alta | Médio | Implementar E1.3/E1.1 com `withoutTenantScope()` explícito e teste dedicado do webhook antes de E1.2 |
| R2 | Migração para guards do Laravel quebra sessões/fluxos existentes | Média | Médio | Sem usuários reais (2.3): sessões podem ser descartadas. Testes de feature de login antes da refatoração |
| R3 | Refatoração das views regride o visual silenciosamente | Média | Médio | Capturar screenshots de referência **antes** de E4.2; comparar após; Playwright em E4.5 congela o resultado |
| R4 | Escopo da sprint inflar com features | Alta | Alto | Seção 2.2 é vinculante. Qualquer feature vai para a sprint 07 |
| R5 | Sprint 06 repetir o padrão de "aprovação condicional" das sprints 01–05 | **Alta** | **Alto** | Seção 1.3: item sem teste não é concluído. CI bloqueia merge. Seção 6 é checklist binário |
| R6 | Decisões de produto (seção 10) travarem a execução | Média | Médio | Somente E1.7, E2.7 e E3.5 dependem de decisão. Blocos 1–3 e 5–10 seguem sem elas |
| R7 | PHPStan nível 5 revelar volume grande de problemas latentes | Média | Baixo | Começar com baseline e reduzir progressivamente, sem bloquear o CI no primeiro dia |
| R8 | `migrate:fresh` como estratégia criar hábito perigoso | Baixa | Alto | Documentar no `README` que a janela se fecha no primeiro cliente real; a partir daí, só migrations aditivas |

---

## 8. Fora do escopo, registrado para a sprint 07+

Itens legítimos identificados na auditoria e no roadmap de `05-buyer-experience/01-review.md`, deliberadamente adiados:

- Cache de vitrines
- Webhook com fila, retry e dead-letter queue (ARC-005 entrega apenas idempotência)
- Histórico/auditoria de mudança de status de pedido
- Extração de `payment` de `tenants.settings` para tabela dedicada
- `OrderItem::product()` para relatórios
- Segundo gateway de pagamento
- Domínio customizado por tenant
- Laravel Octane
- Cupons, notificações, PWA, fidelidade

---

## 9. Observação sobre o `03-dev.md`

Seguindo a convenção das sprints anteriores, o `03-dev.md` deve ser escrito **durante** a execução, registrando o que foi efetivamente feito. Para esta sprint ele tem um requisito adicional: para cada entregável, registrar **o teste que prova a correção** e o resultado antes/depois.

Isto existe para que a próxima auditoria possa verificar o trabalho sem repetir esta análise inteira.

---

## 10. Decisões de produto pendentes

Bloqueiam apenas E1.7, E2.7 e E3.5. Todo o resto pode começar imediatamente.

| # | Decisão | Bloqueia | Opções |
|---|---|---|---|
| 10.1 | O sistema controla estoque ou é "order-first" sob encomenda? | E2.7 | (a) `track_inventory` + `stock_quantity` com decremento sob lock; (b) sem estoque, documentado no README |
| 10.2 | Login do cliente exige verificação de posse do telefone? | E1.7 | (a) OTP por WhatsApp/SMS; (b) manter login fraco e remover dados sensíveis da área do cliente |
| 10.3 | `tenant_staff` precisa existir no MVP? O que o diferencia de `tenant_admin`? | E1.6 | (a) definir matriz de permissões; (b) remover o papel e simplificar |
| 10.4 | `TYPE_ALL` ignorar `max_items` é intencional? | E6.2 | (a) sim — manter e documentar; (b) não — corrigir `Showcase::getProducts()` e o teste |
| 10.5 | Haverá segundo gateway de pagamento? | E3.5 | (a) sim — usar `PaymentGatewayInterface` de fato; (b) não — remover a interface e assumir acoplamento |

Recomendação para começar: responder **10.3** (barata e desbloqueia E1.6, que está no caminho crítico) e **10.2** (a de maior impacto no produto).
