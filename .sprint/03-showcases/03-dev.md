# Relatório de Desenvolvimento: Vitrines (Showcases)

**Data:** 12/01/2026  
**Status:** ✅ Concluído

---

## Resumo

Implementação completa do sistema de vitrines para organizar produtos na página inicial do storefront. O administrador pode criar e gerenciar grupos de produtos com diferentes critérios (automáticos e manuais).

---

## Entregáveis Implementados

### E1. Backend ✅

| Arquivo | Descrição |
|---------|-----------|
| `database/migrations/2026_01_12_190000_create_showcases_table.php` | Tabela de vitrines |
| `database/migrations/2026_01_12_190001_create_showcase_product_table.php` | Pivot para vitrines customizadas |
| `app/Models/Showcase.php` | Model com tipos, scopes e `getProducts()` |
| `database/factories/ShowcaseFactory.php` | Factory para testes |
| `database/factories/CategoryFactory.php` | Factory para testes |

**Alterações em arquivos existentes:**
- `app/Models/Product.php` - Adicionada relação `orderItems()` para suportar "mais vendidos"
- `app/Models/Category.php` - Adicionado trait `HasFactory`

### E2. Admin CRUD ✅

| Arquivo | Descrição |
|---------|-----------|
| `app/Livewire/Admin/Showcases/Index.php` | Listagem com drag-and-drop |
| `app/Livewire/Admin/Showcases/Form.php` | Criar/editar vitrine |
| `resources/views/livewire/admin/showcases/index.blade.php` | View da listagem |
| `resources/views/livewire/admin/showcases/form.blade.php` | View do formulário |

**Alterações em arquivos existentes:**
- `routes/web.php` - Rotas admin de vitrines
- `resources/views/layouts/tenant-admin.blade.php` - Item "Vitrines" no menu (desktop e mobile)

### E3. Storefront - Home ✅

**Alterações em arquivos existentes:**
- `app/Livewire/Tenant/Home.php` - Carrega vitrines ao invés de produtos diretamente
- `resources/views/livewire/tenant/home.blade.php` - Exibe vitrines em seções

### E4. Storefront - Página de Categoria ✅

| Arquivo | Descrição |
|---------|-----------|
| `app/Livewire/Tenant/CategoryShow.php` | Componente da página de categoria |
| `resources/views/livewire/tenant/category-show.blade.php` | View da página |

**Alterações em arquivos existentes:**
- `routes/web.php` - Rota `/{tenant}/categoria/{categorySlug}`

### E5. Seed ✅

| Arquivo | Descrição |
|---------|-----------|
| `database/seeders/ShowcaseSeeder.php` | Cria vitrine padrão "Todos os Produtos" |

**Alterações em arquivos existentes:**
- `database/seeders/DatabaseSeeder.php` - Inclui `ShowcaseSeeder`

---

## Testes Unitários ✅

| Arquivo | Testes |
|---------|--------|
| `tests/Unit/Models/ShowcaseTest.php` | 14 testes |

### Resultados dos Testes

```
PASS  Tests\Unit\Models\ShowcaseTest
✓ showcase has correct types
✓ showcase generates slug automatically
✓ scope for tenant filters correctly
✓ scope active filters correctly
✓ get products type all returns all active products
✓ get products type featured returns only featured
✓ get products type recent returns newest first
✓ get products type category returns products from category
✓ get products type custom returns selected products
✓ get products respects max items
✓ sync products maintains order
✓ showcase belongs to tenant
✓ showcase belongs to category
✓ max showcases per tenant constant

Tests: 14 passed (24 assertions)
Duration: 0.39s
```

---

## Tipos de Vitrines Implementados

| Tipo | Constante | Descrição |
|------|-----------|-----------|
| Todos os Produtos | `TYPE_ALL` | Exibe todos produtos ativos (vitrine padrão) |
| Destaques | `TYPE_FEATURED` | Produtos com `is_featured = true` |
| Mais Vendidos | `TYPE_BEST_SELLERS` | Baseado em `order_items` (pedidos não cancelados) |
| Novidades | `TYPE_RECENT` | Ordenados por `created_at` DESC |
| Por Categoria | `TYPE_CATEGORY` | Produtos de uma categoria específica |
| Personalizada | `TYPE_CUSTOM` | Seleção manual via pivot |

---

## Funcionalidades

### Admin
- ✅ Criar vitrine de qualquer tipo
- ✅ Editar vitrine existente
- ✅ Excluir vitrine
- ✅ Ativar/desativar vitrine
- ✅ Reordenar vitrines via drag-and-drop (SortableJS)
- ✅ Limite de 10 vitrines por tenant
- ✅ Seletor de categoria (tipo `category`)
- ✅ Seletor de produtos (tipo `custom`)

### Storefront
- ✅ Home exibe vitrines ativas na ordem definida
- ✅ Cada vitrine mostra título e grid de produtos em cards
- ✅ Link "Ver mais" para vitrines de categoria
- ✅ Página de categoria com produtos filtrados
- ✅ Layout responsivo (3 colunas desktop, 1-2 mobile)

### Seed
- ✅ Vitrine padrão "Todos os Produtos" criada automaticamente

---

## Rotas Adicionadas

### Admin
```
GET  /{tenant}/admin/vitrines                    → tenant.admin.showcases.index
GET  /{tenant}/admin/vitrines/nova               → tenant.admin.showcases.create
GET  /{tenant}/admin/vitrines/{showcaseId}/editar → tenant.admin.showcases.edit
```

### Storefront
```
GET  /{tenant}/categoria/{categorySlug}          → tenant.category.show
```

---

## Dependências Externas

- **SortableJS** (CDN): `https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js`
  - Usado para drag-and-drop na listagem de vitrines

---

## Comandos Executados

```bash
# Migrations
docker compose exec app php artisan migrate

# Seed
docker compose exec app php artisan db:seed --class=ShowcaseSeeder

# Testes
docker compose exec app php artisan test --filter=ShowcaseTest
```

---

## Arquivos Criados (Resumo)

```
src/
├── app/
│   ├── Livewire/
│   │   ├── Admin/
│   │   │   └── Showcases/
│   │   │       ├── Index.php
│   │   │       └── Form.php
│   │   └── Tenant/
│   │       └── CategoryShow.php
│   └── Models/
│       └── Showcase.php
├── database/
│   ├── factories/
│   │   ├── CategoryFactory.php
│   │   └── ShowcaseFactory.php
│   ├── migrations/
│   │   ├── 2026_01_12_190000_create_showcases_table.php
│   │   └── 2026_01_12_190001_create_showcase_product_table.php
│   └── seeders/
│       └── ShowcaseSeeder.php
├── resources/
│   └── views/
│       └── livewire/
│           ├── admin/
│           │   └── showcases/
│           │       ├── index.blade.php
│           │       └── form.blade.php
│           └── tenant/
│               └── category-show.blade.php
└── tests/
    └── Unit/
        └── Models/
            └── ShowcaseTest.php
```

---

## Próximos Passos (Sugestões)

1. **Cache**: Implementar cache para vitrines "mais vendidos" (invalidar ao criar pedido)
2. **Paginação**: Adicionar paginação na página de categoria para muitos produtos
3. **Preview**: Adicionar preview de produtos no formulário de vitrine
4. **Ordenação de produtos**: Permitir ordenar produtos dentro de vitrines customizadas via drag-drop
