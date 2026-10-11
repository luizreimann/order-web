<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GeneratesUniqueSlug;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'requires_scheduling' => 'boolean',
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

    public function setPriceAttribute($value): void
    {
        // aceita "19.90" / 19.9 / 19,90 (se vier string com vírgula)
        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['price_cents'] = (int) round(((float) $value) * 100);
    }

    public function setCompareAtAttribute($value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['compare_at_cents'] = null;

            return;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', $value);
        }

        $this->attributes['compare_at_cents'] = (int) round(((float) $value) * 100);
    }

    /**
     * Scoped queries (boas práticas pra multi-tenant).
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
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
