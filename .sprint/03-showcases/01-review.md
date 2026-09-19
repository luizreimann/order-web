# Review: Funcionalidade de Vitrines (Showcases)

## 1. Contexto

A página inicial (`/{tenant}`) atualmente exibe **todos os produtos ativos** em uma listagem simples. A funcionalidade de **vitrines** permitirá organizar produtos em grupos temáticos para melhorar a experiência do cliente e dar mais controle ao administrador.

---

## 2. Tipos de Vitrines

### 2.1 Vitrines Automáticas (sistema)
Geradas automaticamente com base em regras:

| Tipo | Descrição | Lógica |
|------|-----------|--------|
| `all` | Todos os Produtos | Todos produtos ativos, ordenados por `sort_order` (vitrine padrão) |
| `featured` | Destaques | Produtos com `is_featured = true` |
| `best_sellers` | Mais Vendidos | Produtos com maior quantidade vendida (baseado em `order_items`) |
| `recent` | Novidades | Produtos mais recentes (`created_at` DESC) |

### 2.2 Vitrines Manuais (admin)
Criadas pelo administrador:

| Tipo | Descrição |
|------|-----------|
| `category` | Baseada em uma categoria específica |
| `custom` | Seleção manual de produtos |

---

## 3. Análise do Estado Atual

### 3.1 Models Existentes
- **Product** (`@/src/app/Models/Product.php`)
  - Já possui `is_featured` (boolean) ✅
  - Já possui `is_active` e scopes `forTenant()`, `active()` ✅
  - Relação M:N com `Category` via pivot `category_product` ✅

- **Category** (`@/src/app/Models/Category.php`)
  - Campos: `name`, `slug`, `is_active`, `sort_order`, `meta` ✅
  - Relação com produtos ✅

- **OrderItem** (`@/src/app/Models/OrderItem.php`)
  - Possui `product_id` (nullable) - pode ser usado para calcular "mais vendidos" ✅

### 3.2 Página Home Atual
- **Componente**: `App\Livewire\Tenant\Home`
- **View**: `resources/views/livewire/tenant/home.blade.php`
- Exibe todos produtos ativos ordenados por `sort_order` e `name`
- Layout simples em grid de cards

### 3.3 Tenant
- Possui `settings` (jsonb) - pode armazenar configuração de vitrines ✅

---

## 4. Proposta de Modelagem

### 4.1 Nova Tabela: `showcases`

```php
Schema::create('showcases', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
    
    $table->string('name');                    // "Destaques", "Mais Vendidos", "Bolos"
    $table->string('slug')->nullable();        // para URLs futuras
    $table->string('type');                    // featured|best_sellers|recent|category|custom
    
    // Para tipo 'category'
    $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
    
    $table->integer('max_items')->default(8); // limite de produtos exibidos
    $table->integer('sort_order')->default(0);
    $table->boolean('is_active')->default(true);
    
    $table->json('meta')->nullable();          // configurações extras
    $table->timestamps();
    
    $table->unique(['tenant_id', 'slug']);
});
```

### 4.2 Nova Tabela Pivot: `showcase_product` (para tipo `custom`)

```php
Schema::create('showcase_product', function (Blueprint $table) {
    $table->foreignUuid('showcase_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
    $table->integer('sort_order')->default(0);
    
    $table->primary(['showcase_id', 'product_id']);
});
```

### 4.3 Model: `Showcase`

```php
class Showcase extends Model
{
    use HasUuids;

    const TYPE_FEATURED = 'featured';
    const TYPE_BEST_SELLERS = 'best_sellers';
    const TYPE_RECENT = 'recent';
    const TYPE_CATEGORY = 'category';
    const TYPE_CUSTOM = 'custom';

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'type', 
        'category_id', 'max_items', 'sort_order', 
        'is_active', 'meta'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_items' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    // Scopes
    public function scopeForTenant($query, string $tenantId);
    public function scopeActive($query);

    // Relationships
    public function tenant();
    public function category();
    public function products(); // para tipo custom

    // Método principal: retorna produtos da vitrine
    public function getProducts(): Collection;
}
```

---

## 5. Lógica de Busca de Produtos por Tipo

```php
public function getProducts(): Collection
{
    $query = Product::query()
        ->forTenant($this->tenant_id)
        ->active();

    return match ($this->type) {
        self::TYPE_FEATURED => $query
            ->where('is_featured', true)
            ->orderBy('sort_order')
            ->limit($this->max_items)
            ->get(),

        self::TYPE_BEST_SELLERS => $query
            ->withCount(['orderItems as total_sold' => fn($q) => 
                $q->whereHas('order', fn($o) => 
                    $o->whereNotIn('status', ['cancelled'])
                )
            ])
            ->orderByDesc('total_sold')
            ->limit($this->max_items)
            ->get(),

        self::TYPE_RECENT => $query
            ->orderByDesc('created_at')
            ->limit($this->max_items)
            ->get(),

        self::TYPE_CATEGORY => $query
            ->whereHas('categories', fn($q) => 
                $q->where('categories.id', $this->category_id)
            )
            ->orderBy('sort_order')
            ->limit($this->max_items)
            ->get(),

        self::TYPE_CUSTOM => $this->products()
            ->where('is_active', true)
            ->orderByPivot('sort_order')
            ->limit($this->max_items)
            ->get(),

        default => collect(),
    };
}
```

---

## 6. Componentes Necessários

### 6.1 Admin

| Componente | Descrição |
|------------|-----------|
| `Admin\ShowcaseIndex` | Lista vitrines do tenant com reordenação |
| `Admin\ShowcaseForm` | Criar/editar vitrine (tipo, categoria, produtos) |

### 6.2 Storefront

| Componente | Descrição |
|------------|-----------|
| `Tenant\Home` | Modificar para carregar vitrines ativas |
| Partial `showcase-section.blade.php` | Renderiza uma vitrine (título + grid de produtos) |

---

## 7. Rotas

### Admin
```php
// Dentro do grupo admin
Route::get('/vitrines', ShowcaseIndex::class)->name('admin.showcases.index');
Route::get('/vitrines/criar', ShowcaseForm::class)->name('admin.showcases.create');
Route::get('/vitrines/{showcaseId}/editar', ShowcaseForm::class)->name('admin.showcases.edit');
```

### Menu Admin
Adicionar em "Catálogo":
- Produtos
- Categorias
- **Vitrines** (novo)

---

## 8. Fluxo de Uso (Admin)

1. Admin acessa **Vitrines** no menu
2. Vê lista de vitrines existentes (pode reordenar via drag-drop ou sort_order)
3. Clica em **Nova Vitrine**
4. Escolhe:
   - **Nome** (ex: "Bolos Especiais")
   - **Tipo**: Destaques | Mais Vendidos | Novidades | Por Categoria | Personalizada
   - Se "Por Categoria": seleciona categoria
   - Se "Personalizada": seleciona produtos manualmente
   - **Máximo de itens** (default: 8)
   - **Ativo**: sim/não
5. Salva

---

## 9. Fluxo de Uso (Storefront)

1. Cliente acessa `/{tenant}`
2. Sistema carrega vitrines ativas ordenadas por `sort_order`
3. Para cada vitrine:
   - Exibe título (nome da vitrine)
   - Exibe grid de produtos (até `max_items`)
   - Link "Ver mais" se for vitrine de categoria

---

## 10. Considerações de Performance

- **Cache**: Vitrines "mais vendidos" podem ser cacheadas (invalidar ao criar pedido)
- **Eager loading**: Carregar produtos com imagens em uma query
- **Limite**: `max_items` evita queries pesadas

---

## 11. Tarefas de Implementação

### Fase 1: Backend
- [ ] Migration `create_showcases_table`
- [ ] Migration `create_showcase_product_table`
- [ ] Model `Showcase` com relacionamentos e `getProducts()`
- [ ] Adicionar relação `orderItems` em `Product` (para best_sellers)

### Fase 2: Admin
- [ ] Componente `Admin\ShowcaseIndex`
- [ ] View `livewire/admin/showcase-index.blade.php`
- [ ] Componente `Admin\ShowcaseForm`
- [ ] View `livewire/admin/showcase-form.blade.php`
- [ ] Adicionar item no menu admin

### Fase 3: Storefront
- [ ] Modificar `Tenant\Home` para carregar vitrines
- [ ] Criar partial `showcase-section.blade.php`
- [ ] Atualizar `home.blade.php`

### Fase 4: Seed/Demo
- [ ] Seed de vitrines padrão (Destaques, Mais Vendidos)

---

## 12. Estrutura de Arquivos (Novos)

```
src/
├── app/
│   └── Models/
│       └── Showcase.php
│   └── Livewire/
│       └── Admin/
│           ├── ShowcaseIndex.php
│           └── ShowcaseForm.php
├── database/
│   └── migrations/
│       ├── xxxx_create_showcases_table.php
│       └── xxxx_create_showcase_product_table.php
└── resources/
    └── views/
        └── livewire/
            └── admin/
                ├── showcase-index.blade.php
                └── showcase-form.blade.php
            └── tenant/
                └── partials/
                    └── showcase-section.blade.php
```

---

## 13. Decisões Tomadas

| Pergunta | Decisão |
|----------|---------|
| **Vitrines padrão** | Sim, criar vitrine padrão "Todos os produtos" (`type: all`) para novos tenants |
| **Limite de vitrines** | Máximo de **10 vitrines** por tenant |
| **Link "Ver mais"** | Sim, implementar página de categoria no storefront |
| **Reordenação** | Drag-and-drop (JS) no admin |
| **Layout de exibição** | **Cards** (foto, nome, preço) - mesmo padrão atual da home |

### Novo Tipo de Vitrine

Adicionar tipo `all` (Todos os produtos):

| Tipo | Descrição | Lógica |
|------|-----------|--------|
| `all` | Todos os Produtos | Todos produtos ativos, ordenados por `sort_order` |

Este será o tipo padrão criado automaticamente para novos tenants.

---

## 14. Próximos Passos

Após aprovação desta review:
1. Criar migrations
2. Criar model `Showcase`
3. Implementar CRUD admin (com drag-and-drop)
4. Implementar página de categoria no storefront
5. Integrar vitrines na home do storefront
