# Design System — Sprint 06

**Sprint:** 06-refactor
**Entregáveis cobertos:** E4.1 a E4.5 de `02-sprint.md`
**Base:** achados UI-001 a UI-006 de `01-review.md`

---

## 1. Diagnóstico

### 1.1 O problema não é o que parece

A percepção inicial era "a interface está sem padronização visual". O inventário quantitativo de `resources/views` mostra algo diferente e mais específico:

| Padrão | Ocorrências | Leitura |
|---|---|---|
| `<h1 class="h4 mb-0">` | 23 | Título de página: **consistente** |
| `card shadow-sm` | 33 (+12 com margens variadas) | Container: **consistente** |
| `btn btn-primary` | 26 | Ação primária: **consistente** |
| `btn btn-outline-secondary` | 26 | Ação secundária: **consistente** |
| `btn-sm` | 37 | Tamanho: **consistente** |
| `class="form-label"` | 74 | **consistente** |
| `class="form-control"` | 71 | **consistente** |
| `card-body` escrito à mão | 50 | **sem componente** |

**A UI tem padrão.** O que ela não tem é um mecanismo que o **imponha**. A consistência atual é fruto de copy-paste disciplinado — o que significa que ela é frágil por construção: cada tela nova é uma nova chance de divergir, e nada detecta a divergência.

Componentes Blade existentes: **6**, nenhum de layout ou formulário (`loader`, `inline-loader`, `toast-container`, `contact-modal`, `tenant-header`, `whatsapp-button`).

### 1.2 Onde a inconsistência é real

O copy-paste já falhou em pontos específicos e mensuráveis:

#### a) Estado de erro de formulário — a divergência mais grave

| Elemento | Ocorrências |
|---|---|
| `@error` (exibe mensagem) | **84** |
| `is-invalid` (marca o campo) | **4** |
| `invalid-feedback` | **2 arquivos** |

Em 80 dos 84 campos, a mensagem de erro aparece mas **o input não recebe marcação visual de inválido**. O usuário lê o erro sem que o campo correspondente esteja destacado. É inconsistência funcional, não só estética — e é invisível em code review campo por campo.

#### b) Título de seção — 6 tratamentos para o mesmo nível semântico

```
h5 mb-0
h5 mb-3
h5 mb-3 pb-2 border-bottom
h6 mb-3
h6 fw-semibold mb-3
h6 fw-bold mb-2
h6 text-muted mb-2
```

#### c) Hierarquia de headings quebrada

| Tag | Ocorrências |
|---|---|
| `h1` | 27 |
| `h2` | 5 |
| `h4` | 2 |
| `h5` | 17 |
| `h6` | 12 |

Salta de `h1` para `h5`/`h6`, praticamente sem `h2`/`h3`/`h4`. Não existe escala tipográfica — existe escolha ad-hoc de tag por aparência desejada. Problema de acessibilidade além de estética.

#### d) Tabelas — 6 combinações de classes

```
table table-hover align-middle mb-0   (4)
table align-middle mb-0               (3)
table table-sm table-borderless mb-0  (1)
table table-sm align-middle mb-0      (1)
table table-hover                     (1)
```

#### e) Espaçamento ad-hoc em containers

`card shadow-sm` aparece isolado (33) e com `mb-3`, `mb-4`, `mt-3`, `mt-4`, `h-100` avulsos (12). A margem é decidida caso a caso em vez de derivar do contexto de layout.

#### f) 49 `style=` inline

| Arquivo | Ocorrências |
|---|---|
| `livewire/admin/tenant/customization.blade.php` | 11 |
| `livewire/tenant/home.blade.php` | 5 |
| `layouts/tenant-admin.blade.php` | 5 |
| `livewire/admin/tenant/payment-settings.blade.php` | 4 |
| `livewire/tenant/checkout.blade.php` | 3 |
| outros 18 arquivos | 1–2 cada |

#### g) Badges sem semântica definida

`badge bg-success` (3), `bg-secondary` (3), `bg-primary` (2) e um `badge bg-` dinâmico — sem mapeamento documentado entre status de domínio e cor.

### 1.3 Conclusão do diagnóstico

O trabalho não é redesenhar. É **extrair o padrão que já existe de fato para componentes**, corrigir os pontos onde o copy-paste divergiu, e então travar o resultado.

Isso também explica por que o risco R3 de `02-sprint.md` (regressão visual silenciosa) é gerenciável: o alvo visual é o estado atual, não um novo design.

---

## 2. Por que Playwright não é o ponto de partida

Registrado porque foi a proposta inicial.

**Visual regression testing previne desvio futuro. Ele não descobre qual deveria ser o padrão.** Rodar Playwright hoje produziria um baseline de snapshots que *canoniza a inconsistência atual* — incluindo os 80 campos sem `is-invalid` e os 6 estilos de título de seção. A suíte passaria a defender exatamente o que queremos eliminar.

A sequência correta:

```
1. Definir a escala (E4.1)          → decidir o padrão
2. Extrair componentes (E4.2)       → tornar o padrão executável
3. Migrar as views (E4.2/E4.3)      → aplicar o padrão
4. Playwright snapshots (E4.5)      → congelar o padrão
```

Além disso, o maior valor do Playwright neste projeto não é visual: é **E2E do checkout**, que hoje tem cobertura zero (`01-review.md` QA-001). O fluxo storefront → carrinho → checkout → pedido criado, com o Brick do Mercado Pago, é genuinamente difícil de cobrir com testes de feature Livewire.

**Regra:** Playwright para (a) E2E do checkout e login e (b) congelar o design system. **Não** para testar regra de negócio — isso é mais barato e mais rápido em testes de feature Livewire.

Para a fase de migração (passo 3), a ferramenta útil é bem mais simples: um script que capture screenshot de cada rota **antes** da refatoração, para comparação manual depois. Isso é a mitigação do risco R3, e não precisa de suíte de snapshots versionada.

---

## 3. Fundações (E4.1)

### 3.1 Escala tipográfica

Uma decisão por nível semântico, com a tag correta e a classe visual desacoplada dela (padrão Bootstrap `h1.h4`, já usado corretamente em 23 lugares):

| Nível semântico | Tag | Classe visual | Uso |
|---|---|---|---|
| Título de página | `h1` | `h4` | Um por tela (já é o padrão de fato) |
| Título de seção | `h2` | `h6 fw-semibold` | Seção dentro de card/página |
| Subtítulo de bloco | `h3` | `h6 text-muted` | Agrupamento interno |

Decisões a tomar e registrar:
- Se o título de seção leva `border-bottom` (hoje 4 de ~17 levam)
- Se `text-muted` é subtítulo ou variação de título de seção (hoje ambíguo)

**Meta verificável:** o grep de classes de heading deve retornar um conjunto fechado de ≤ 4 combinações, e a hierarquia `h1 → h2 → h3` sem saltos.

### 3.2 Escala de espaçamento

Regra única: **quem posiciona é o container, não o componente.**

- `<x-card>` não tem margem própria
- O espaçamento entre blocos vem de um wrapper (`vstack gap-*` ou classe de seção)
- Elimina a decisão caso a caso entre `mb-3`, `mb-4`, `mt-3`, `mt-4`

### 3.3 Semântica de cor

Mapeamento explícito de estado de domínio → variante visual, consumindo os enums de `E2.6`:

| Domínio | Estado | Variante |
|---|---|---|
| `OrderStatus` | pending / confirmed / done / cancelled | a definir |
| `PaymentStatus` | unpaid / paid / refunded | a definir |
| Ativo/inativo | — | a definir |

O mapeamento vive em **um** lugar (accessor no enum ou no componente `<x-status-badge>`), não espalhado no Blade. Hoje `livewire/admin/tenant/payment-settings.blade.php:270-310` faz esse mapeamento dentro da view.

### 3.4 Decisões aplicadas (piloto, E4.1)

Fechadas com D8 (`04-decisions.md`) e executáveis nos componentes `resources/views/components/`:

| Nível | Tag + classes | Componente | Observação |
|---|---|---|---|
| Título de página | `h1.h4.mb-0` | `<x-page-header>` | Um por tela. Subtítulo da página é `div.text-muted.small` (descrição, não heading) |
| Título de seção | `h2.h6.fw-semibold.mb-0` | `<x-section-title>` | **Sem `border-bottom`** (D8/9.1). O `mb-0` existe para que o espaçamento seja do container |
| Subtítulo de bloco | `h3.h6.text-muted.mb-0` | `<x-section-title sub>` | D8/9.2: nível próprio, não variação do título de seção |

- **Hierarquia sem saltos:** `h1` (página) → `h2` (seção) → `h3` (subtítulo). Nenhum componente emite `h4`–`h6` como tag; `h6` é só classe visual.
- **Espaçamento:** o container posiciona, o componente não tem margem própria. Nenhum componente emite `m*-`/`mb-*` externo (`x-card`, `x-page-header`, `x-section-title`, `x-form-field`, `x-btn`, `x-data-table`, `x-empty-state`). Entre blocos usa-se `vstack gap-3` (equivale ao `mb-3` que as telas usavam); entre campos, `row g-3`/`col-*` ou `vstack gap-*`. Margens internas de composição (`me-1` do ícone em `x-btn`, `mt-3` da ação em `x-empty-state`) são parte do componente, não do posicionamento dele.
- **`x-form-field` não tem wrapper** (emite label, controle, feedback e ajuda como fragmento): o `col-*` / `vstack` do chamador é o container.
- **Cor:** componentes nunca fixam cor do tenant (`bg-primary`); `btn-primary` herda `--bs-primary`. `x-status-badge` só emite `success|warning|info|secondary|light|dark` (nunca `primary`/`danger`, D8/9.3).
- **Tabela:** uma combinação: `table table-hover align-middle mb-0` dentro de `table-responsive` (`<x-data-table>`).
- **Erro de formulário:** `is-invalid` no controle + `invalid-feedback` com a mensagem, sempre derivados de `$errors` pelo nome do campo (`<x-form-field>`); não se escreve mais `@error` à mão.

---

## 4. Biblioteca de componentes (E4.2)

Priorizada por retorno: quantas ocorrências de copy-paste cada componente elimina.

### 4.1 `<x-form-field>` — prioridade máxima

**Elimina:** 74 `form-label` + 71 `form-control` + 84 `@error` + 29 `form-text` escritos à mão.
**Corrige:** a divergência 84 `@error` vs 4 `is-invalid` — de uma vez, em todos os formulários.

Encapsula: label (com marcação de obrigatório), controle, `is-invalid` condicional, `invalid-feedback` com a mensagem, texto de ajuda (`form-text`), e o `wire:model` correspondente.

Deve cobrir também `form-select` (17), `form-check` (10/18/15) e textarea — via atributo de tipo ou componentes irmãos (`<x-form-select>`, `<x-form-check>`).

> Este é o componente de maior impacto da sprint. É o único item do design system que corrige um defeito **funcional** e não apenas estético.

### 4.2 `<x-card>`

**Elimina:** 50 `card-body` + 45 variações de `card shadow-sm`.
Slots para cabeçalho, corpo e rodapé. Sem margem própria (3.2). Variantes para os casos legítimos existentes (`bg-light`, `p-0` para tabela embutida, `h-100` para grid).

### 4.3 `<x-page-header>`

**Elimina:** 23 ocorrências de `<h1 class="h4 mb-0">` com estruturas de ação variadas ao lado.
Título, subtítulo opcional, slot de ações. Padroniza o alinhamento do botão de ação primária, que hoje varia por tela.

### 4.4 `<x-section-title>`

**Elimina:** os 6 tratamentos distintos catalogados em 1.2(b). Uma decisão, um componente.

### 4.5 `<x-data-table>`

**Elimina:** 6 combinações de classes de tabela + 10 `table-responsive` manuais.
Encapsula o wrapper responsivo, as classes padrão, slots de cabeçalho/corpo e integração com `<x-empty-state>`.

### 4.6 `<x-empty-state>`

**Elimina:** 5 blocos `text-center py-5` com estruturas divergentes.
Ícone, mensagem, ação opcional. Usado por todas as listagens admin e pelo storefront sem produtos.

### 4.7 `<x-btn>`

**Elimina:** a decisão ad-hoc entre 26 `btn-primary`, 26 `btn-outline-secondary`, 11 `btn-outline-primary`, 7 `btn-outline-danger`, 37 `btn-sm`.
Variantes semânticas (`primary`, `secondary`, `danger`, `link`), tamanho, estado de loading integrado ao `wire:loading` (hoje resolvido de formas diferentes via `components/loader` e `components/inline-loader`), e slot de ícone.

Resolve também o `btn btn-outline-` truncado/dinâmico encontrado em 4 pontos.

### 4.8 `<x-confirm-action>`

**Elimina:** 5 `wire:confirm` com textos e padrões divergentes, sobre 8 ações destrutivas (`delete`/`toggleActive` em Products, Categories, Customers, Showcases).

Deve nascer alinhado à autorização de `E1.6`: se o usuário não tem permissão, o componente não renderiza (`@can`).

### 4.9 `<x-status-badge>`

**Elimina:** badges com mapeamento de cor espalhado, incluindo a lógica em `payment-settings.blade.php:270-310`.
Recebe o enum de `E2.6` e resolve rótulo + variante em um lugar.

---

## 5. Layout admin (E4.3)

`resources/views/layouts/tenant-admin.blade.php` — **697 linhas**:

| Trecho | Linhas | Problema |
|---|---|---|
| Menu desktop | 95–249 | ~150 linhas duplicadas do menu mobile |
| Menu mobile (offcanvas) | 305–463 | cópia quase literal |
| JS inline | 498–680 | ~180 linhas (tema, toasts, tooltips, store status) |
| CSS inline | 14–88 | ~75 linhas reescrevendo comportamento do Bootstrap |

### Ações

1. **Estrutura de navegação única em PHP** (array/classe com seções, rótulos, rotas, ícones e permissões), renderizada por `<x-admin-nav>` em dois modos (sidebar / offcanvas).
   **Prova:** adicionar um item de menu requer alterar **um** lugar, e o teste verifica que ele aparece nos dois menus.
2. **JS para módulo Vite** (`resources/js/admin/`): tema dark/light, toasts, tooltips, toggle de status da loja.
3. **Remover o CSS que reescreve o Bootstrap**; o que sobrar vira Sass com variáveis.
4. Integrar `@can` na navegação (depende de `E1.6`).

**Meta:** layout abaixo de 150 linhas.

---

## 6. Stack de assets (E4.4)

### 6.1 Tailwind está morto — remover

`package.json:10-20` declara `tailwindcss` e `@tailwindcss/vite`, mas:
- `resources/css/app.css:1` contém apenas `@import "bootstrap/dist/css/bootstrap.min.css"`
- Nenhuma diretiva `@tailwind` ou `@import "tailwindcss"` em nenhum asset
- Todas as classes das views são Bootstrap 5.3 (`btn`, `card`, `form-control`, `d-flex`, `data-bs-*`)

**Decisão: Bootstrap 5.3 é o framework, oficialmente.** Remover Tailwind e o plugin do Vite.

Motivos para não migrar para Tailwind nesta sprint: o tema white label por tenant (`partials/tenant-theme.blade.php`) sobrescreve variáveis CSS do Bootstrap, e o dark/light usa `data-bs-theme`. Migrar significaria reescrever o sistema de temas — que é uma feature que funciona. Fora de escopo (`02-sprint.md` §2.2).

### 6.2 Consolidar CDNs no Vite

| Recurso | Local atual | Ação |
|---|---|---|
| Font Awesome | `layouts/tenant-admin.blade.php:10`, `layouts/app.blade.php:10` | npm + Vite |
| Splide | `layouts/app.blade.php:11,45` | npm + Vite |
| SortableJS | 3 views, **2 estratégias diferentes** de carregamento | npm + Vite, uma estratégia |
| `public/js/carousel.js` | arquivo estático solto | mover para o bundle |
| Google Fonts | `partials/tenant-theme.blade.php:28-30` | **manter externo** (dinâmico por tenant) |
| SDK Mercado Pago | `livewire/tenant/checkout.blade.php:145` | **manter externo** (exigência do gateway) |
| gtag.js | `partials/tenant-meta.blade.php:43` | manter, com validação de `E5.2` |

**Prova:** grep de `<script src="https://` retorna apenas o SDK do Mercado Pago e o gtag.

### 6.3 Eliminar os 49 `style=` inline

Casos legítimos (valor dinâmico por tenant, ex.: cor vinda de `settings`) devem passar por CSS custom properties no `tenant-theme`, com o escape de `E5.1` — não por interpolação direta em atributo `style`.

### 6.4 Remover o tenant hardcoded

`routes/web.php:37-39` — `Route::get('/', fn () => redirect('/caroldoces'))`. Substituir por landing page, lista de lojas ou 404 conforme decisão de produto.

---

## 7. Ordem de execução

```
E4.1  Escala (tipografia, espaçamento, cor)
        │
        ├─ decisões registradas antes de escrever componente
        ▼
E4.2  Componentes, na ordem de retorno:
        1. <x-form-field>      (corrige defeito funcional)
        2. <x-card>
        3. <x-page-header> + <x-section-title>
        4. <x-data-table> + <x-empty-state>
        5. <x-btn> + <x-confirm-action>
        6. <x-status-badge>    (depende de E2.6 — enums)
        │
        ▼
      Migração das views, um módulo por vez:
        Produtos → Categorias → Clientes → Pedidos → Vitrines
          → Configurações do tenant → Storefront → Checkout
        │
        ▼
E4.3  Layout admin (<x-admin-nav>, JS para Vite)
E4.4  Limpeza de assets
        │
        ▼
E4.5  Playwright: E2E do checkout + snapshots congelados
```

**Por que migrar módulo por módulo:** os CRUDs admin (Produtos, Categorias, Clientes, Vitrines) têm estrutura quase idêntica. O primeiro módulo valida a API dos componentes; os seguintes são mecânicos. Se a API estiver errada, o custo do erro fica contido em um módulo.

> **E4.5 — FEITO (2026-10-19).** Playwright 1.64.0 (exato), `npm run e2e` (de `src/`) = `bin/e2e.sh`: servidor isolado `e2e-app` (porta 8081) com o banco `wl_e2e` no `db_test`, seed determinístico (`E2eSeeder`, lojas `loja-e2e` e `loja-b-e2e`), testes no container oficial `mcr.microsoft.com/playwright:v1.64.0-jammy` (snapshots idênticos entre Mac, Linux e CI). **Local apenas: `src/e2e/` está no `.gitignore` por decisão do dono do projeto, e o job `e2e` foi removido do CI.** **Congelado o design system:** 69 snapshots em `src/e2e/__screenshots__/` (storefront e admin, desktop + mobile), tolerância apertada (`threshold 0.02`, `maxDiffPixelRatio 0.0002`); a mutação "`<x-card>` sem `shadow-sm`" falha 54 de 67 testes visuais. **E2E:** checkout do visitante (dinheiro e PIX manual, com verificação no banco), estoque, validação (`x-form-field` no navegador), login/logout do admin, CRUD de produto com upload, cancelamento de pedido, isolamento entre lojas, SortableJS, tema sem flash, abrir/fechar a loja, offcanvas, Splide, popup e segredos do MP. Como rodar, política de atualização de snapshots e a lista de bugs que o navegador revelou (testes `test.fail` com `BUG:`): ver "Testes E2E" no `README.md`.

**Checkout por último:** é a view mais complexa (388 linhas, com o JS do Mercado Pago em `:144-382`), depende de `E3.1` (`CartService`) e de `E5.4` (`innerHTML`), e é a que o Playwright vai cobrir em E2E.

### Dependências externas ao design system

| Componente | Depende de |
|---|---|
| `<x-status-badge>` | `E2.6` (enums `OrderStatus`/`PaymentStatus`) |
| `<x-confirm-action>`, `<x-admin-nav>` | `E1.6` (policies, `@can`) |
| Componentes de checkout/carrinho | `E3.1` (`CartService`) |

---

## 8. Critérios de conclusão

- [ ] Escala tipográfica, de espaçamento e semântica de cor documentadas e decididas
- [ ] Hierarquia de headings sem saltos; ≤ 4 combinações de classes de heading no grep
- [ ] Um único tratamento para título de seção (hoje 6)
- [ ] **Todo campo de formulário com `@error` também recebe `is-invalid`** — proporção 84:4 corrigida para 1:1
- [ ] ≥ 8 componentes Blade de layout/formulário em uso
- [ ] `card-body` e `card shadow-sm` escritos à mão: ~0
- [ ] Uma única combinação de classes de tabela
- [ ] `style=` inline: ≤ 5 (de 49)
- [ ] `layouts/tenant-admin.blade.php` abaixo de 150 linhas
- [ ] Adicionar item de menu requer alterar um lugar (com teste)
- [ ] Tailwind removido do `package.json` e do Vite
- [ ] Apenas Mercado Pago, Google Fonts e gtag carregados externamente
- [ ] `redirect('/caroldoces')` removido
- [x] E2E do checkout verde (local; `src/e2e/` fora do git e do CI por decisão do dono do projeto)
- [ ] Snapshots de visual regression congelados após a migração
- [ ] Nenhuma regressão visual não-intencional (comparação com screenshots pré-refatoração)

---

## 9. Decisões de UI — FECHADAS

Registradas em **`04-decisions.md`** (D8), que é a fonte canônica. Resumo:

| # | Questão | Decisão |
|---|---|---|
| 9.1 | Título de seção leva `border-bottom`? | **Não.** Só 4 de ~17 usam; separação por espaçamento é mais limpa |
| 9.2 | `text-muted` em título é o quê? | **Subtítulo** — vira nível `h3` próprio na escala |
| 9.3 | Cor dos status | Mapeamento fechado abaixo |
| 9.4 | Estado de loading | **Padronizar em `<x-btn>`**; `<x-loader>` fica para página inteira; `<x-inline-loader>` é absorvido |
| 9.5 | O que é `/` | **Landing da plataforma** (D7) — remove `redirect('/caroldoces')` |
| 9.6 | Componentes compartilhados? | **Sim** — resolvido pelo código |

### 9.6 — por que compartilhar é seguro

`partials/tenant-theme.blade.php` é incluído nos **dois** layouts (`layouts/app.blade.php:12` e `layouts/tenant-admin.blade.php:11`) e sobrescreve as variáveis do próprio Bootstrap:

```css
--bs-primary: var(--tenant-primary);
--bs-link-color: var(--tenant-primary);
--bs-focus-ring-color: color-mix(in srgb, var(--tenant-primary) 35%, transparent);
```

Logo `btn-primary` já herda a cor do tenant automaticamente, nos dois contextos. O tema atua na camada de **variável CSS**, não na de classe — componentes compartilhados funcionam sem adaptação.

Isso elimina o risco que esta seção levantava originalmente. É design bem feito e deve ser preservado.

### 9.3 — semântica de cor dos status

Consequência direta de 9.6: como `--bs-primary` é sobrescrito pelo tenant, **status nunca pode usar `primary`** — a cor mudaria de loja para loja e perderia o significado semântico.

| `OrderStatus` | Variante | | `PaymentStatus` | Variante |
|---|---|---|---|---|
| `pending` | `warning` | | `unpaid` | `warning` |
| `confirmed` | `info` | | `paid` | `success` |
| `done` | `success` | | `refunded` | `secondary` |
| `cancelled` | `secondary` | | | |

`danger` fica reservado para ações destrutivas e erros — não para estado de pedido. Um pedido cancelado é desfecho normal do negócio, não erro do sistema.

O mapeamento vive no enum (accessor) ou em `<x-status-badge>`, nunca no Blade. Hoje está em `payment-settings.blade.php:270-310`.
