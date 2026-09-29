<?php

namespace App\Livewire\Admin\Categories;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public Tenant $tenant;

    public ?Category $categoryModel = null;

    public string $name = '';

    public ?string $slug = null;

    public ?string $description = null;

    public bool $is_active = true;

    public int $sort_order = 0;

    public array $products = [];

    public function mount(Tenant $tenant, ?string $categoryId = null): void
    {
        $this->tenant = $tenant;

        if ($categoryId) {
            $this->categoryModel = Category::query()
                ->forTenant($tenant->id)
                ->where('id', $categoryId)
                ->firstOrFail();

            $this->fillFromModel();
            $this->loadProducts();
        }
    }

    public function loadProducts(): void
    {
        if (! $this->categoryModel) {
            $this->products = [];

            return;
        }

        $this->products = $this->categoryModel->products()
            ->forTenant($this->tenant->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sort_order' => $p->sort_order,
                'is_active' => $p->is_active,
            ])
            ->toArray();
    }

    public function updateProductOrder(array $orderedIds): void
    {
        if (! $this->categoryModel) {
            return;
        }

        foreach ($orderedIds as $index => $productId) {
            \App\Models\Product::where('id', $productId)
                ->where('tenant_id', $this->tenant->id)
                ->update(['sort_order' => $index]);
        }

        $this->loadProducts();
        session()->flash('status', 'Ordem dos produtos atualizada!');
    }

    public function updatedSlug(): void
    {
        if ($this->slug === null) {
            return;
        }

        $s = trim(mb_strtolower($this->slug));
        $s = preg_replace('/\s+/', '-', $s) ?? $s;
        $this->slug = $s;
    }

    protected function rules(): array
    {
        $catId = $this->categoryModel?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],

            // slug opcional: se vazio, o model gera automaticamente
            'slug' => [
                'nullable',
                'string',
                'min:2',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->id))
                    ->ignore($catId),
            ],

            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:100000'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenant->id,
            'name' => $this->name,
            'slug' => $this->slug ? Str::slug($this->slug) : null,
            'description' => $this->description ?: null,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];

        if ($this->categoryModel) {
            $this->categoryModel->fill($data)->save();
        } else {
            $this->categoryModel = Category::create($data);
        }

        session()->flash('status', 'Categoria salva com sucesso!');
        $this->redirectRoute('tenant.admin.categories.index', ['tenant' => $this->tenant->slug], navigate: true);
    }

    private function fillFromModel(): void
    {
        $c = $this->categoryModel;

        $this->name = $c->name;
        $this->slug = $c->slug;
        $this->description = $c->description;
        $this->is_active = (bool) $c->is_active;
        $this->sort_order = (int) $c->sort_order;
    }

    public function render()
    {
        return view('livewire.admin.categories.form', [
            'categoryProducts' => $this->products,
        ])->layout('layouts.tenant-admin');
    }
}
