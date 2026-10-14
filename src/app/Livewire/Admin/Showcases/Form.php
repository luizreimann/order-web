<?php

namespace App\Livewire\Admin\Showcases;

use App\Exceptions\ShowcaseLimitReachedException;
use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Category;
use App\Models\Product;
use App\Models\Showcase;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public ?Showcase $showcaseModel = null;

    public string $name = '';

    public ?string $slug = null;

    public string $type = Showcase::TYPE_ALL;

    public string $display_mode = 'list';

    public ?string $category_id = null;

    public array $selectedProducts = [];

    public int $max_items = 8;

    public bool $is_active = true;

    public function mount(Tenant $tenant, ?string $showcaseId = null): void
    {
        $this->tenant = $tenant;

        if ($showcaseId) {
            $this->showcaseModel = Showcase::query()
                ->forTenant($tenant->id)
                ->where('id', $showcaseId)
                ->firstOrFail();

            $this->fillFromModel();
        }

        $this->authorizeView();
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
        $showcaseId = $this->showcaseModel?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'min:2',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Só registros ativos contam (unique parcial, E2.5).
                Rule::unique('showcases', 'slug')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->id)->whereNull('deleted_at'))
                    ->ignore($showcaseId),
            ],
            'type' => ['required', 'string', Rule::in(array_keys(Showcase::getTypes()))],
            'display_mode' => ['required', 'string', Rule::in(['list', 'carousel'])],
            'category_id' => [
                Rule::requiredIf($this->type === Showcase::TYPE_CATEGORY),
                'nullable',
                'uuid',
                Rule::exists('categories', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->id)->whereNull('deleted_at')),
            ],
            'selectedProducts' => ['array'],
            'selectedProducts.*' => [
                'uuid',
                Rule::exists('products', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $this->tenant->id)->whereNull('deleted_at')),
            ],
            'max_items' => ['integer', 'min:1', 'max:50'],
            'is_active' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->authorizeView();

        $this->validate();

        $data = [
            'name' => $this->name,
            'slug' => $this->slug ? Str::slug($this->slug) : null,
            'type' => $this->type,
            'display_mode' => $this->display_mode,
            'category_id' => $this->type === Showcase::TYPE_CATEGORY ? $this->category_id : null,
            'max_items' => $this->max_items,
            'is_active' => $this->is_active,
        ];

        if ($this->showcaseModel) {
            $this->showcaseModel->fill($data + ['tenant_id' => $this->tenant->id])->save();
        } else {
            // Contagem + INSERT sob lock do tenant (VAL-004): sem corrida no limite.
            try {
                $this->showcaseModel = Showcase::createWithinLimit($this->tenant->id, $data);
            } catch (ShowcaseLimitReachedException $e) {
                session()->flash('error', $e->getMessage());

                return;
            }
        }

        if ($this->type === Showcase::TYPE_CUSTOM) {
            $this->showcaseModel->syncProducts($this->selectedProducts);
        } else {
            $this->showcaseModel->products()->detach();
        }

        session()->flash('status', 'Vitrine salva com sucesso!');
        $this->redirectRoute('tenant.admin.showcases.index', ['tenant' => $this->tenant->slug], navigate: true);
    }

    private function fillFromModel(): void
    {
        $s = $this->showcaseModel;

        $this->name = $s->name;
        $this->slug = $s->slug;
        $this->type = $s->type;
        $this->display_mode = $s->display_mode ?? 'list';
        $this->category_id = $s->category_id;
        $this->max_items = (int) $s->max_items;
        $this->is_active = (bool) $s->is_active;

        if ($s->type === Showcase::TYPE_CUSTOM) {
            $this->selectedProducts = $s->products()->pluck('products.id')->toArray();
        }
    }

    protected function authorizeView(): void
    {
        if ($this->showcaseModel) {
            $this->authorize('update', $this->showcaseModel);
        } else {
            $this->authorize('create', [Showcase::class, $this->tenant]);
        }
    }

    public function render()
    {
        $categories = Category::query()
            ->forTenant($this->tenant->id)
            ->active()
            ->orderBy('name')
            ->get();

        $products = Product::query()
            ->forTenant($this->tenant->id)
            ->active()
            ->orderBy('name')
            ->get();

        return view('livewire.admin.showcases.form', [
            'categories' => $categories,
            'products' => $products,
            'types' => Showcase::getTypes(),
        ])->layout('layouts.tenant-admin');
    }
}
