<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesUniqueSlug;
use App\Models\Concerns\TenantScoped;
use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model implements TenantScoped
{
    use BelongsToTenant, GeneratesUniqueSlug, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'sku',
        'description',
        'price',
        'compare_at',
        'compare_at_cents',
        'is_active',
        'is_featured',
        'sort_order',
        'min_days_notice',
        'requires_scheduling',
        'track_inventory',
        'stock_quantity',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'requires_scheduling' => 'boolean',
        'track_inventory' => 'boolean',
        'stock_quantity' => 'integer',
        'meta' => 'array',
        'price_cents' => 'integer',
        'compare_at_cents' => 'integer',
        'sort_order' => 'integer',
        'min_days_notice' => 'integer',
    ];

    /**
     * Helpers de preço (pra não lidar com cents no resto do app).
     */
    public function getPriceAttribute(): float
    {
        return $this->price_cents / 100;
    }

    public function getCompareAtAttribute(): ?float
    {
        return $this->compare_at_cents !== null ? $this->compare_at_cents / 100 : null;
    }

    /**
     * Aceita "1.250,00" / "19,90" / "19.90" / 19.9 / 20 (ver {@see Money}). Valor inválido NÃO vira
     * R$ 0,00 em silêncio: lança, para que um caminho sem validação falhe alto em vez de gravar errado.
     */
    public function setPriceAttribute($value): void
    {
        $this->attributes['price_cents'] = self::centsOrFail('price', $value);
    }

    public function setCompareAtAttribute($value): void
    {
        $this->attributes['compare_at_cents'] = $value === null || $value === ''
            ? null
            : self::centsOrFail('compare_at', $value);
    }

    private static function centsOrFail(string $attribute, mixed $value): int
    {
        $cents = is_string($value) || is_int($value) || is_float($value) ? Money::toCents($value) : null;

        if ($cents === null) {
            throw new \InvalidArgumentException("Valor inválido para [{$attribute}]: ".var_export($value, true));
        }

        return $cents;
    }

    /**
     * URL pública da imagem (`meta.image_path`), ou null quando o produto não tem imagem.
     */
    public function imageUrl(): ?string
    {
        $path = data_get($this->meta, 'image_path');

        return $path ? Storage::url($path) : null;
    }

    /**
     * Quantidade vendável agora; null = sem controle de estoque (ilimitado / sob encomenda).
     * Leitura sem lock: serve à vitrine. A decisão real é do OrderService, com lock.
     */
    public function availableQuantity(): ?int
    {
        return $this->track_inventory ? max(0, (int) $this->stock_quantity) : null;
    }

    public function isOutOfStock(): bool
    {
        return $this->track_inventory && (int) $this->stock_quantity <= 0;
    }

    /**
     * Scoped queries (boas práticas pra multi-tenant).
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where($this->qualifyColumn('tenant_id'), $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Categorias do produto.
     *
     * O pivô carrega `tenant_id` (E2.2): em instância com tenant, o valor do
     * model é gravado em attach/sync e usado para filtrar o pivô. A FK composta do
     * banco rejeita categoria de outro tenant. Em eager loading (`with()`), o
     * model é uma instância vazia (sem tenant), então nada é adicionado ali.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->withTenantPivot($this->belongsToMany(
            Category::class,
            'category_product',
            'product_id',
            'category_id'
        ));
    }

    public function syncCategories(array $categoryIds): void
    {
        $this->categories()->sync($categoryIds);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
