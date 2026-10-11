<?php

namespace App\Livewire\Admin\Products;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class Form extends Component
{
    use AuthorizesOnHydrate;
    use WithFileUploads;

    public Tenant $tenant;

    public ?Product $productModel = null;

    public string $name = '';

    public ?string $sku = null;

    public ?string $description = null;

    // valores em reais como string no form
    public string $price = '0,00';

    public ?string $compare_at = null;

    public array $category_ids = [];

    public bool $is_active = true;

    public bool $is_featured = false;

    public int $sort_order = 0;

    public ?int $min_days_notice = null;

    public bool $requires_scheduling = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $image = null;

    public array $meta = [];

    public function mount(Tenant $tenant, ?string $productId = null): void
    {
        $this->tenant = $tenant;

        if ($productId) {
            $this->productModel = Product::query()
                ->forTenant($tenant->id)
                ->where('id', $productId)
                ->firstOrFail();

            $this->fillFromModel();
        } else {
            $this->meta = [];
        }

        $this->authorizeView();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'sku' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string'],

            'price' => ['required', 'string'],
            'compare_at' => ['nullable', 'string'],

            'category_ids' => ['array'],
            'category_ids.*' => [
                'uuid',
                \Illuminate\Validation\Rule::exists('categories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->id)),
            ],

            'is_active' => ['boolean'],
            'is_featured' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:100000'],

            'min_days_notice' => ['nullable', 'integer', 'min:0', 'max:365'],
            'requires_scheduling' => ['boolean'],

            'meta' => ['array'],
            'image' => ['nullable', 'image', 'max:3072'], // max 3MB
        ];
    }

    public function save(): void
    {
        $this->authorizeView();

        $this->validate();

        $data = [
            'tenant_id' => $this->tenant->id,
            'name' => $this->name,
            'sku' => $this->sku ?: null,
            'description' => $this->description ?: null,

            // usa mutators setPriceAttribute / setCompareAtAttribute do model
            'price' => $this->price,
            'compare_at' => $this->compare_at,

            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'sort_order' => $this->sort_order,

            'min_days_notice' => $this->min_days_notice,
            'requires_scheduling' => $this->requires_scheduling,

            'meta' => $this->meta ?: [],
        ];

        if ($this->productModel) {
            $this->productModel->fill($data)->save();
        } else {
            $this->productModel = Product::create($data);
        }

        $this->productModel->categories()->sync($this->category_ids);

        if ($this->image) {
            $tenantId = $this->tenant->id;
            $productId = $this->productModel->id;

            $oldPath = data_get($this->productModel->meta, 'image_path');
            if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $ext = $this->image->getClientOriginalExtension() ?: 'jpg';
            $path = "tenants/{$tenantId}/products/{$productId}/main.{$ext}";

            $this->image->storePubliclyAs(
                "tenants/{$tenantId}/products/{$productId}",
                "main.{$ext}",
                'public'
            );

            $meta = $this->productModel->meta ?? [];
            data_set($meta, 'image_path', $path);
            $this->productModel->meta = $meta;
            $this->productModel->save();
        }

        session()->flash('status', 'Produto salvo com sucesso!');
        $this->redirectRoute('tenant.admin.products.index', ['tenant' => $this->tenant->slug]);
    }

    private function fillFromModel(): void
    {
        $p = $this->productModel;

        $this->name = $p->name;
        $this->sku = $p->sku;
        $this->description = $p->description;

        $this->price = number_format($p->price, 2, ',', '.');
        $this->compare_at = $p->compare_at !== null ? number_format($p->compare_at, 2, ',', '.') : null;

        $this->category_ids = $this->productModel
            ? $this->productModel->categories()->pluck('categories.id')->all()
            : [];

        $this->is_active = (bool) $p->is_active;
        $this->is_featured = (bool) $p->is_featured;
        $this->sort_order = (int) $p->sort_order;

        $this->min_days_notice = $p->min_days_notice;
        $this->requires_scheduling = (bool) $p->requires_scheduling;

        $this->meta = $p->meta ?? [];
    }

    protected function authorizeView(): void
    {
        if ($this->productModel) {
            $this->authorize('update', $this->productModel);
        } else {
            $this->authorize('create', [Product::class, $this->tenant]);
        }
    }

    public function render()
    {
        $categories = Category::query()
            ->forTenant($this->tenant->id)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('livewire.admin.products.form', [
            'categories' => $categories,
        ])->layout('layouts.tenant-admin');
    }

    public function removeImage(): void
    {
        $this->authorizeView();

        if (! $this->productModel) {
            return;
        }

        $path = data_get($this->productModel->meta, 'image_path');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $meta = $this->productModel->meta ?? [];
        unset($meta['image_path']);

        $this->productModel->meta = $meta;
        $this->productModel->save();

        $this->image = null;

        session()->flash('status', 'Imagem removida.');
        $this->dispatch('$refresh');
    }

    public function getImageUrlProperty(): ?string
    {
        if ($this->image) {
            return $this->image->temporaryUrl();
        }

        if ($this->productModel) {
            $path = data_get($this->productModel->meta, 'image_path');
            if ($path) {
                return Storage::url($path);
            }
        }

        return null;
    }
}
