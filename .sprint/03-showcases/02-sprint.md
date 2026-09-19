# Sprint: Vitrines (Showcases)

## Objetivo

Implementar sistema de vitrines para organizar produtos na página inicial do storefront, permitindo ao administrador criar e gerenciar grupos de produtos com diferentes critérios.

---

## Escopo

### Incluído
- CRUD de vitrines no admin
- 6 tipos de vitrines: `all`, `featured`, `best_sellers`, `recent`, `category`, `custom`
- Reordenação via drag-and-drop
- Limite de 10 vitrines por tenant
- Vitrine padrão "Todos os produtos" para novos tenants
- Página de categoria no storefront
- Exibição em formato card (foto, nome, preço)

### Fora do Escopo
- Cache de vitrines (pode ser adicionado depois)
- Vitrines com filtros avançados
- Vitrines por tags/atributos

---

## Entregáveis

### E1. Backend (Migrations + Model)
- Migration `create_showcases_table`
- Migration `create_showcase_product_table`
- Model `Showcase` com:
  - Constantes de tipos
  - Scopes `forTenant()`, `active()`
  - Relacionamentos: `tenant`, `category`, `products`
  - Método `getProducts()` com lógica por tipo
- Relação `orderItems` em `Product` (para best_sellers)

### E2. Admin - CRUD de Vitrines
- Componente `ShowcaseIndex` com:
  - Lista de vitrines ordenadas
  - Drag-and-drop para reordenação (SortableJS)
  - Botões: criar, editar, excluir, ativar/desativar
  - Validação de limite (máx 10)
- Componente `ShowcaseForm` com:
  - Campo nome
  - Seletor de tipo
  - Seletor de categoria (condicional)
  - Seletor de produtos (condicional, para tipo custom)
  - Campo max_items
  - Toggle ativo/inativo
- Menu admin: adicionar "Vitrines" em Catálogo

### E3. Storefront - Home com Vitrines
- Modificar `Tenant\Home` para:
  - Carregar vitrines ativas ordenadas
  - Passar dados para view
- Partial `showcase-section.blade.php`:
  - Título da vitrine
  - Grid de cards (3 colunas em desktop)
  - Link "Ver mais" para vitrines de categoria

### E4. Storefront - Página de Categoria
- Componente `Tenant\CategoryShow`
- Rota `/{tenant}/categoria/{categorySlug}`
- View com:
  - Nome e descrição da categoria
  - Grid de produtos da categoria
  - Paginação (opcional)

### E5. Seed e Migração de Dados
- Seed para criar vitrine padrão "Todos os produtos" em tenants existentes
- Ajustar seed de demo para incluir vitrines de exemplo

---

## Tarefas por Entregável

### E1. Backend

| # | Tarefa | Estimativa |
|---|--------|------------|
| 1.1 | Criar migration `create_showcases_table` | 15min |
| 1.2 | Criar migration `create_showcase_product_table` | 10min |
| 1.3 | Criar model `Showcase` com constantes e fillable | 15min |
| 1.4 | Implementar scopes e relacionamentos | 15min |
| 1.5 | Implementar método `getProducts()` | 30min |
| 1.6 | Adicionar relação `orderItems` em `Product` | 10min |
| 1.7 | Rodar migrations e testar | 10min |

**Subtotal E1:** ~1h45

### E2. Admin - CRUD

| # | Tarefa | Estimativa |
|---|--------|------------|
| 2.1 | Criar componente `ShowcaseIndex` (listagem) | 30min |
| 2.2 | Criar view `showcase-index.blade.php` | 30min |
| 2.3 | Implementar drag-and-drop com SortableJS | 30min |
| 2.4 | Criar componente `ShowcaseForm` | 45min |
| 2.5 | Criar view `showcase-form.blade.php` | 45min |
| 2.6 | Implementar seletor de produtos (tipo custom) | 30min |
| 2.7 | Adicionar rotas no arquivo de rotas | 10min |
| 2.8 | Adicionar item no menu admin | 10min |
| 2.9 | Validação de limite (máx 10 vitrines) | 15min |

**Subtotal E2:** ~4h

### E3. Storefront - Home

| # | Tarefa | Estimativa |
|---|--------|------------|
| 3.1 | Modificar `Tenant\Home` para carregar vitrines | 20min |
| 3.2 | Criar partial `showcase-section.blade.php` | 30min |
| 3.3 | Atualizar `home.blade.php` para usar vitrines | 20min |
| 3.4 | Ajustar responsividade dos cards | 15min |

**Subtotal E3:** ~1h25

### E4. Storefront - Página de Categoria

| # | Tarefa | Estimativa |
|---|--------|------------|
| 4.1 | Criar componente `Tenant\CategoryShow` | 30min |
| 4.2 | Criar view `category-show.blade.php` | 30min |
| 4.3 | Adicionar rota | 10min |
| 4.4 | Adicionar link "Ver mais" nas vitrines de categoria | 15min |

**Subtotal E4:** ~1h25

### E5. Seed

| # | Tarefa | Estimativa |
|---|--------|------------|
| 5.1 | Criar seeder para vitrine padrão em tenants existentes | 20min |
| 5.2 | Ajustar DatabaseSeeder para criar vitrine em novos tenants | 15min |

**Subtotal E5:** ~35min

---

## Estimativa Total

| Entregável | Tempo |
|------------|-------|
| E1. Backend | 1h45 |
| E2. Admin CRUD | 4h |
| E3. Home com Vitrines | 1h25 |
| E4. Página de Categoria | 1h25 |
| E5. Seed | 35min |
| **Total** | **~9h** |

---

## Dependências Técnicas

### Bibliotecas JS (já disponíveis ou a adicionar)
- **SortableJS**: para drag-and-drop no admin
  - Opção 1: CDN no layout admin
  - Opção 2: npm install sortablejs

### Arquivos a Modificar
- `routes/web.php` - novas rotas
- `layouts/tenant-admin.blade.php` - menu
- `app/Models/Product.php` - relação orderItems
- `app/Livewire/Tenant/Home.php` - carregar vitrines
- `resources/views/livewire/tenant/home.blade.php` - usar vitrines

### Arquivos Novos
- `database/migrations/xxxx_create_showcases_table.php`
- `database/migrations/xxxx_create_showcase_product_table.php`
- `app/Models/Showcase.php`
- `app/Livewire/Admin/ShowcaseIndex.php`
- `app/Livewire/Admin/ShowcaseForm.php`
- `app/Livewire/Tenant/CategoryShow.php`
- `resources/views/livewire/admin/showcase-index.blade.php`
- `resources/views/livewire/admin/showcase-form.blade.php`
- `resources/views/livewire/tenant/category-show.blade.php`
- `resources/views/livewire/tenant/partials/showcase-section.blade.php`

---

## Ordem de Implementação

1. **E1** - Backend (base necessária para tudo)
2. **E2** - Admin CRUD (permite criar vitrines para testar)
3. **E5** - Seed (cria dados de teste)
4. **E3** - Home com Vitrines (exibe vitrines criadas)
5. **E4** - Página de Categoria (complementa "Ver mais")

---

## Critérios de Aceite

### Vitrines Admin
- [ ] Admin consegue criar vitrine de cada tipo
- [ ] Admin consegue reordenar vitrines via drag-and-drop
- [ ] Admin consegue ativar/desativar vitrine
- [ ] Sistema impede criar mais de 10 vitrines
- [ ] Vitrine de categoria mostra seletor de categorias
- [ ] Vitrine custom mostra seletor de produtos

### Storefront Home
- [ ] Home exibe vitrines ativas na ordem definida
- [ ] Cada vitrine mostra título e produtos em cards
- [ ] Vitrine de categoria tem link "Ver mais"
- [ ] Layout responsivo (3 colunas desktop, 1-2 mobile)

### Página de Categoria
- [ ] Rota `/{tenant}/categoria/{slug}` funciona
- [ ] Exibe nome, descrição e produtos da categoria
- [ ] Aplica tema do tenant

### Vitrine Padrão
- [ ] Novos tenants recebem vitrine "Todos os produtos" automaticamente
- [ ] Tenants existentes recebem vitrine via migration/seed

---

## Riscos e Mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Performance em "mais vendidos" | Média | Médio | Limitar max_items, considerar cache futuro |
| Conflito de drag-drop com Livewire | Baixa | Médio | Usar wire:ignore e eventos JS |
| Muitas queries na home | Média | Médio | Eager loading, limite de vitrines |

---

## Notas de Implementação

### Drag-and-Drop com Livewire
- Usar `wire:ignore` no container do SortableJS
- Disparar evento Livewire ao reordenar
- Método `updateOrder(array $ids)` no componente

### Tipo "best_sellers"
- Usar subquery para contar vendas
- Considerar apenas pedidos não cancelados
- Se produto nunca vendeu, fica no final

### Vitrine Padrão
- Tipo `all` não tem `max_items` (ou valor alto como 100)
- Criada automaticamente, mas pode ser editada/desativada
- Se desativada, home fica vazia (comportamento esperado)
