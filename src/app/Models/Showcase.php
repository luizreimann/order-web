<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesUniqueSlug;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Showcase extends Model implements TenantScoped
{
    use BelongsToTenant, GeneratesUniqueSlug, HasFactory, HasUuids, SoftDeletes;

    const TYPE_ALL = 'all';

    const TYPE_FEATURED = 'featured';

    const TYPE_BEST_SELLERS = 'best_sellers';

    const TYPE_RECENT = 'recent';

    const TYPE_CATEGORY = 'category';

    const TYPE_CUSTOM = 'custom';

    const MAX_SHOWCASES_PER_TENANT = 10;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'type',
        'display_mode',
        'category_id',
        'max_items',
        'sort_order',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'max_items' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public static function getTypes(): array
    {
        return [
            self::TYPE_ALL => 'Todos os Produtos',
            self::TYPE_FEATURED => 'Destaques',
            self::TYPE_BEST_SELLERS => 'Mais Vendidos',
            self::TYPE_RECENT => 'Novidades',
            self::TYPE_CATEGORY => 'Por Categoria',
            self::TYPE_CUSTOM => 'Personalizada',
        ];
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        // E2.2: pivô com `tenant_id` — ver BelongsToTenant::withTenantPivot().
        return $this->withTenantPivot(
            $this->belongsToMany(Product::class, 'showcase_product')
                ->withPivot('sort_order')
                ->orderByPivot('sort_order')
        );
    }

    public function getProducts(): Collection
    {
        $limit = $this->type === self::TYPE_ALL ? 100 : $this->max_items;

        $query = Product::query()
            ->forTenant($this->tenant_id)
            ->active();

        return match ($this->type) {
            self::TYPE_ALL => $query
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit($limit)
                ->get(),

            self::TYPE_FEATURED => $query
                ->where('is_featured', true)
                ->orderBy('sort_order')
                ->limit($limit)
                ->get(),

            self::TYPE_BEST_SELLERS => $query
                ->withCount(['orderItems as total_sold' => fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', '!=', OrderStatus::CANCELLED->value)
                ),
                ])
                ->orderByDesc('total_sold')
                ->limit($limit)
                ->get(),

            self::TYPE_RECENT => $query
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get(),

            self::TYPE_CATEGORY => $this->category_id
                ? $query
                    ->whereHas('categories', fn ($q) => $q->where('categories.id', $this->category_id)
                    )
                    ->orderBy('sort_order')
                    ->limit($limit)
                    ->get()
                : collect(),

            // Defesa em profundidade (E2.2): além da FK composta do pivô, o produto
            // tem de ser do mesmo tenant da vitrine. `Product` já exclui soft-deleted.
            self::TYPE_CUSTOM => $this->products()
                ->where('products.tenant_id', $this->tenant_id)
                ->where('products.is_active', true)
                ->limit($limit)
                ->get(),

            default => collect(),
        };
    }

    public function syncProducts(array $productIds): void
    {
        $syncData = [];
        foreach ($productIds as $index => $productId) {
            $syncData[$productId] = ['sort_order' => $index];
        }
        $this->products()->sync($syncData);
    }
}
