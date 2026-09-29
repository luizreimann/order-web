# Decisões — Sprint 06

**Data:** 2026-09-19
**Status:** Fechadas. Este documento é a fonte canônica; `02-sprint.md` e `03-design-system.md` referenciam daqui.

---

## 0. Reframe do produto

As decisões abaixo revelaram um produto substancialmente maior do que o código e o `README.md` documentam.

| | Documentado hoje | Direção real |
|---|---|---|
| Escopo | Loja de encomendas (doceria) | **Plataforma SaaS multi-vertical** |
| Verticais | Sob encomenda | Sob encomenda **e** com estoque (petshop) |
| Administração | Admin por tenant | Admin por tenant **+ admin de plataforma** |
| Monetização | — | **Planos** |
| Pagamento | Mercado Pago + manual | **Múltiplos gateways configuráveis** |
| Raiz `/` | `redirect('/caroldoces')` | **Landing da plataforma** |

Isso não invalida a Sprint 06 — reforça sua necessidade. Mas várias decisões marcadas como "futuramente" têm consequência **hoje**, porque são caras de reverter. A seção 7 separa o que muda agora do que fica para depois.

---

## D1 — Estoque: opt-in por produto

**Decisão:** o sistema suporta as duas verticais. `track_inventory` (bool, default `false`) e `stock_quantity` (nullable) entram **agora**, por **produto**.

### Por que por produto, e não por tenant

Amarrar o comportamento ao tenant erra as duas verticais:

- Um petshop vende ração (estoque) **e** banho e tosa agendado (sob encomenda)
- Uma doceria vende bolo por encomenda **e** brigadeiro pronto na vitrine

É o modelo do Shopify, e é o mais barato de implementar.

### Por que agora

Sem dados de produção, adicionar coluna é editar migration e rodar `migrate:fresh`. O custo real não é a coluna — é o **ponto de decisão no `OrderService`**. Adicionar depois significa reabrir o fluxo de criação de pedido, que é justamente onde estão os acertos que não queremos arriscar (OK-02, OK-03, OK-04 de `01-review.md`).

### Comportamento

| `track_inventory` | Comportamento |
|---|---|
| `false` (default) | Idêntico ao atual — sem validação, sem decremento |
| `true` | Valida disponibilidade e decrementa com `lockForUpdate` no fechamento do pedido |

**Fora do escopo:** alertas de estoque baixo, backorder, reserva temporária no carrinho, histórico de movimentação.

**Prova:** teste de venda concorrente do último item com `track_inventory = true` — apenas um pedido conclui. Teste de que produto com `track_inventory = false` mantém comportamento atual.

### Achado relacionado

O inverso já existe e está **pela metade**: `min_days_notice` e `requires_scheduling` são configuráveis no admin (`Products/Form.php:78-79,104-105`) e **nunca usados no checkout**. `orders.scheduled_for` só é exibido no admin, nunca capturado.

Ou seja, já existe uma capability de vertical incompleta. Registrada no backlog da Sprint 07 (seção 8).

---

## D2 — Papéis: plataforma vs tenant

**Decisão:** o modelo de papéis passa a ter **duas dimensões**. A Sprint 06 **acomoda** a dimensão de plataforma; **não constrói** o painel.

### Dimensões

| Dimensão | Valores |
|---|---|
| Escopo | `platform` \| `tenant` |
| Nível | `admin` \| (futuros: `staff`, `owner`) |

Papéis existentes na Sprint 06: `platform_admin` (sem tenant) e `tenant_admin`.

O papel `tenant_staff` é **removido**: existe hoje apenas em dois `whereIn` (`EnsureTenantAdminAuthenticated.php:37`, `TenantAdminAuthController.php:34`), nenhum seeder o cria, nenhum código ramifica nele. Mantê-lo dá falsa sensação de controle de acesso. Volta quando houver necessidade real de contas de equipe, aí sim com matriz de permissões definida.

### Restrição crítica de schema

> **`users.tenant_id` DEVE permanecer `nullable`.**
> Um admin de plataforma não pertence a tenant nenhum.

Isto está registrado como restrição porque o E2.1 ("adicionar FKs em todo o schema") tende naturalmente a tornar a coluna `NOT NULL` ao "corrigir integridade" — o que bloquearia o roadmap silenciosamente. A FK entra, a nulidade permanece.

A coluna já é `nullable()` hoje (`..._add_tenant_and_role_to_users_table.php:11`). Não regredir.

### Restrição crítica no global scope

O global scope de E1.1 deve nascer suportando **três contextos**, não um:

| Contexto | Comportamento |
|---|---|
| Request de tenant | Filtra por `tenant_id` |
| Admin de plataforma | Consulta **através** dos tenants |
| Impersonation | **Assume** um tenant deliberadamente |

Implementar assumindo que a ausência de tenant é erro quebraria o painel de plataforma, e a correção seria invasiva (todos os models). Projetar para três contextos agora custa quase nada; depois custa uma refatoração transversal.

### Consequência para auditoria

Impersonation sem trilha de auditoria é indefensável — alguém opera como o tenant e não há registro de quem foi.

`01-review.md` classificou DB-005 ("sem soft deletes e sem auditoria") como P1 genérico. Com impersonation no roadmap, **o log de auditoria deixa de ser melhoria e vira pré-requisito** da feature. Não muda o escopo da Sprint 06, mas muda a prioridade do item na Sprint 07.

---

## D3 — Login do cliente: manter o fator, corrigir o encanamento

**Decisão:** o fator de autenticação (telefone sem verificação) **permanece como está**. Risco conhecido e aceito. OTP via WhatsApp fica para quando houver payoff.

Contexto que sustenta a decisão: o checkout é **público** (`routes/web.php:55-56` está fora do grupo autenticado), então o login é conveniência, não barreira de compra. A área logada expõe histórico de pedidos, nome, e-mail e telefone — endereço não aparece no storefront (`CustomerAddress` só é usado em `Admin/Customers/Form.php`).

### O que NÃO está coberto por "manter como está"

"Como está" engloba três coisas distintas. Apenas a primeira é decisão de produto:

| # | Item | Decisão |
|---|---|---|
| 1 | Telefone sem verificação (SEC-001) | **Mantém.** Documentado como risco aceito |
| 2 | Middleware que não bloqueia (SEC-002) | **Corrige.** Um middleware chamado `tenant.customer.auth` que deixa todos passarem não é "login fraco" — é armadilha para o próximo desenvolvedor. Corrigir não fortalece o login; faz o middleware cumprir o nome |
| 3 | `public Customer $customer` não inicializada | **Corrige.** Crash latente no caminho de falha do `mount()` (`Account/Index.php:14,22-28`). É bug, não política |

### Sequenciamento

Migrar para guards do Laravel (E1.4) **não altera a força do login**, mas é pré-requisito de `@can` e das policies (E1.6). Se "manter como está" incluísse a session crua, o E1.6 inteiro ficaria bloqueado.

Resultado: guard `customer` é criado normalmente; o que ele valida continua sendo só o telefone.

### Registro de risco aceito

Deve constar no `README.md`: *a autenticação de cliente não verifica posse do telefone; qualquer pessoa que conheça o número acessa a conta correspondente. A área do cliente não deve receber dados sensíveis enquanto isso não mudar.*

Esta última frase é a mitigação que importa: ela vira uma **regra de projeto**, não só um aviso.

---

## D4 — Gateways: investimento completo na abstração

**Decisão:** a plataforma terá múltiplos gateways configuráveis por tenant. E3.5 vai para o caminho de investimento completo.

A fundação já existe e é boa: o enum `PaymentMethod` já abstrai **dois provedores reais** — `manual` (dinheiro, cartão na entrega, PIX chave) e `mercadopago` — com `provider()`, `isManual()`, `isMercadoPago()` e `defaultConfig()`.

### Escopo na Sprint 06

- `TenantPaymentController` passa a depender de `PaymentGatewayInterface`, não da classe concreta (`TenantPaymentController.php:13-15`)
- Resolver de gateway por tenant
- Rota genérica `/webhooks/{gateway}` substituindo `/webhooks/mercadopago` (`routes/web.php:41-42`)
- Remover IDs do Mercado Pago hardcoded de `Checkout.php:48`
- Teste de checkout ponta a ponta com **gateway fake**, sem tocar código do MP

**Fora do escopo:** implementar um segundo gateway real. A prova de que a abstração funciona é o gateway fake passar no teste.

### Alerta

A configuração de pagamento vive hoje em `tenants.settings` (JSONB). Com N gateways por tenant, isso agrava o problema descrito em D6. Extrair para tabela dedicada está no backlog da Sprint 07 (já constava em `02-sprint.md` §8).

---

## D5 — Vitrine `TYPE_ALL` passa a respeitar `max_items`

**Decisão:** `TYPE_ALL` respeita `max_items`, com 100 como teto de segurança.

Diagnóstico: `Showcase.php:96` faz `$limit = $this->type === self::TYPE_ALL ? 100 : $this->max_items;`. O código é deliberado, mas a **UX está quebrada** — o admin preenche `max_items = 5`, salva, e vê 10 produtos. O campo existe no formulário e não tem efeito.

O `BUG-001` reportado em `03-showcases/04-qa-review.md:17-48` acertou o sintoma e errou o diagnóstico: tratou como asserção de teste errada, quando era divergência entre interface e comportamento.

**Ação:** `TYPE_ALL` usa `min($max_items, 100)`. Atualizar `tests/Unit/Models/ShowcaseTest.php:163-175`, que hoje documenta o comportamento antigo como intencional.

**Prova:** vitrine `TYPE_ALL` com `max_items = 5` e 10 produtos ativos retorna 5. Com `max_items = 500`, retorna no máximo 100.

---

## D6 — Capabilities de vertical: estrutura explícita, não JSONB

**Decisão de arquitetura** derivada de D1 e do roadmap de planos. Não é entregável da Sprint 06, é restrição de design.

Com duas verticais e planos no horizonte, vão surgir flags de capacidade por tenant. O caminho de menor resistência é despejá-las em `tenants.settings`.

**Não fazer isso.** `01-review.md` (DB-005) já registra 9 colunas JSON/JSONB servindo de saco de gatos. Adicionar capabilities e planos ali torna impossível responder por query perguntas operacionais básicas:

- Quais tenants têm estoque ativo?
- Quais estão no plano X?
- Quantos usam gateway Y?

Essas são exatamente as perguntas que um SaaS com planos precisa responder para cobrar e para operar.

**Diretriz:** capabilities e plano do tenant em colunas/tabelas explícitas. `settings` fica para preferências de apresentação (tema, textos, horários) — que é o uso legítimo dele hoje.

---

## D7 — Raiz `/`: landing da plataforma

**Decisão:** `/` passa a ser a landing institucional do SaaS. Remove `Route::get('/', fn () => redirect('/caroldoces'))` (`routes/web.php:37-39`).

Na Sprint 06 entra uma landing mínima (o suficiente para eliminar o hardcode). Conteúdo de marketing e página de planos acompanham o painel de plataforma, na Sprint 07.

---

## D8 — Decisões de UI

Tomadas por padrão para desbloquear os componentes. São reversíveis e de baixo impacto — contestar se discordar.

| # | Questão | Decisão | Racional |
|---|---|---|---|
| 9.1 | Título de seção leva `border-bottom`? | **Não** | Só 4 de ~17 usam. Separação por espaçamento é mais limpa e evita ruído visual em cards pequenos |
| 9.2 | `text-muted` em título é o quê? | **Subtítulo** (nível `h3`) | Vira nível semântico próprio na escala, não variação do título de seção |
| 9.4 | Estado de loading | **Padronizar em `<x-btn>`** | `wire:loading` do botão fica no próprio botão. `<x-loader>` permanece para carregamento de página inteira; `<x-inline-loader>` é absorvido por `<x-btn>` |
| 9.5 | O que é `/` | Resolvido em **D7** | — |
| 9.6 | Componentes compartilhados admin/storefront? | **Sim, compartilhados** | Resolvido pelo código — ver abaixo |

### 9.6 — por que compartilhar é seguro

`partials/tenant-theme.blade.php` é incluído nos **dois** layouts (`layouts/app.blade.php:12` e `layouts/tenant-admin.blade.php:11`) e funciona sobrescrevendo as variáveis do próprio Bootstrap:

```css
--bs-primary: var(--tenant-primary);
--bs-link-color: var(--tenant-primary);
--bs-focus-ring-color: color-mix(in srgb, var(--tenant-primary) 35%, transparent);
```

Logo, `btn-primary` já herda a cor do tenant automaticamente, nos dois contextos. O tema atua na camada de **variável CSS**, não na de classe — então componentes compartilhados funcionam sem adaptação.

Isso elimina o risco levantado em `03-design-system.md` §9.6. É design bem feito e deve ser preservado.

### 9.3 — semântica de cor dos status

Consequência direta de 9.6: como `--bs-primary` é sobrescrito pelo tenant, **status nunca pode usar `primary`** — a cor mudaria de loja para loja e perderia o significado semântico.

| `OrderStatus` | Variante |
|---|---|
| `pending` | `warning` |
| `confirmed` | `info` |
| `done` | `success` |
| `cancelled` | `secondary` |

| `PaymentStatus` | Variante |
|---|---|
| `unpaid` | `warning` |
| `paid` | `success` |
| `refunded` | `secondary` |

`danger` fica reservado para ações destrutivas e erros — não para estado de pedido. Um pedido cancelado é um desfecho normal do negócio, não um erro do sistema.

O mapeamento vive no enum (accessor) ou em `<x-status-badge>`, nunca no Blade. Hoje está em `payment-settings.blade.php:270-310`.

---

## 7. Impacto consolidado na Sprint 06

### 7.1 Construir agora

| Item | Entregável | Origem |
|---|---|---|
| `track_inventory` + `stock_quantity` por produto, opt-in | E2.7 | D1 |
| Remover `tenant_staff` | E1.6 | D2 |
| Guards com dimensão plataforma/tenant | E1.4 | D2 |
| Global scope com três contextos | E1.1 | D2 |
| Middleware de customer que bloqueia | E1.5 | D3 |
| Guard `customer` (mantendo fator fraco) | E1.4 | D3 |
| Resolver de gateway + rota `/webhooks/{gateway}` + gateway fake | E3.5 | D4 |
| `TYPE_ALL` respeita `max_items` | E6.2 | D5 |
| Landing mínima em `/` | E4.4 | D7 |
| Componentes Blade compartilhados | E4.2 | D8/9.6 |
| `<x-status-badge>` sem usar `primary` | E4.2 | D8/9.3 |

### 7.2 Acomodar agora, construir depois

| Restrição | Onde incide |
|---|---|
| `users.tenant_id` permanece `nullable` | E2.1 |
| Global scope suporta contexto de plataforma e impersonation | E1.1 |
| Capabilities e planos **não** vão para `tenants.settings` | E2.x |
| Configuração de gateway sairá de `tenants.settings` | Sprint 07 |

### 7.3 Sprint 07+

- Painel de administrador de plataforma (CRUD de tenants, planos, impersonation)
- **Log de auditoria** — pré-requisito de impersonation (prioridade elevada por D2)
- OTP via WhatsApp (D3)
- Completar agendamento: capturar `scheduled_for` no checkout respeitando `requires_scheduling` e `min_days_notice` (achado de D1)
- Tabela dedicada para configuração de gateways (D4)
- Conteúdo da landing e página de planos (D7)
- Segundo gateway real
- Estoque avançado: alerta de baixa, backorder, reserva no carrinho

### 7.4 Não muda

O escopo central da Sprint 06 permanece: versionamento, isolamento de tenant, integridade de schema, `CartService`, idempotência de webhook, design system, CI e testes de integração.

Nenhuma decisão reduziu o escopo. D1, D2 e D4 o ampliaram de forma controlada — majoritariamente em **design**, não em implementação.
