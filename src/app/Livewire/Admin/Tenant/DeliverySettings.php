<?php

namespace App\Livewire\Admin\Tenant;

use App\Livewire\Admin\Concerns\AuthorizesOnHydrate;
use App\Models\Tenant;
use App\Services\FulfillmentService;
use App\Support\Money;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.tenant-admin')]
class DeliverySettings extends Component
{
    use AuthorizesOnHydrate;

    public Tenant $tenant;

    public ?float $minimum_order = 0;

    public ?float $default_fee = 0;

    public ?int $estimated_time_minutes = null;

    public ?float $free_delivery_above = 0;

    public bool $pickup_enabled = true;

    public ?float $pickup_fee = 0;

    public bool $delivery_enabled = false;

    /** @var list<array{id: string, name: string, fee: float, active: bool}> */
    public array $regions = [];

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $this->authorizeView();

        $delivery = data_get($tenant->settings, 'delivery', []);

        $this->minimum_order = data_get($delivery, 'minimum_order_cents', 0) / 100;
        $this->default_fee = data_get($delivery, 'default_fee_cents', 0) / 100;
        $this->estimated_time_minutes = data_get($delivery, 'estimated_time_minutes');
        $this->free_delivery_above = data_get($delivery, 'free_delivery_above_cents', 0) / 100;

        $fulfillment = app(FulfillmentService::class);
        $this->pickup_enabled = $fulfillment->pickupEnabled($tenant);
        $this->pickup_fee = $fulfillment->pickupFeeCents($tenant) / 100;
        $this->delivery_enabled = $fulfillment->deliveryEnabled($tenant);
        $this->regions = array_map(
            fn (array $r) => ['id' => $r['id'], 'name' => $r['name'], 'fee' => $r['fee_cents'] / 100, 'active' => $r['active']],
            $fulfillment->regions($tenant, onlyActive: false),
        );
    }

    public function addRegion(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        if (count($this->regions) >= FulfillmentService::MAX_REGIONS) {
            $this->addError('regions', 'Limite de '.FulfillmentService::MAX_REGIONS.' bairros.');

            return;
        }

        $this->regions[] = ['id' => (string) Str::uuid(), 'name' => '', 'fee' => 0.0, 'active' => true];
    }

    public function removeRegion(int $index): void
    {
        $this->authorize('manageSettings', $this->tenant);

        unset($this->regions[$index]);
        $this->regions = array_values($this->regions);
    }

    protected function rules(): array
    {
        return [
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'default_fee' => ['nullable', 'numeric', 'min:0'],
            'estimated_time_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'free_delivery_above' => ['nullable', 'numeric', 'min:0'],
            'pickup_enabled' => ['boolean'],
            'pickup_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'delivery_enabled' => ['boolean'],
            'regions' => ['array', 'max:'.FulfillmentService::MAX_REGIONS],
            'regions.*.id' => ['required', 'string', 'max:64'],
            'regions.*.name' => ['required', 'string', 'min:2', 'max:80'],
            'regions.*.fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'regions.*.active' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'regions.*.name.required' => 'Informe o nome do bairro.',
            'regions.*.name.min' => 'O nome do bairro precisa de ao menos 2 letras.',
            'regions.*.fee.required' => 'Informe a taxa do bairro (0 para grátis).',
        ];
    }

    public function save(): void
    {
        $this->authorize('manageSettings', $this->tenant);

        $this->validate();

        if (! $this->pickup_enabled && ! $this->delivery_enabled) {
            $this->addError('pickup_enabled', 'Deixe ao menos uma forma de recebimento ligada: retirada ou entrega.');

            return;
        }

        $names = array_map(fn (array $r) => mb_strtolower(trim($r['name'])), $this->regions);

        if (count($names) !== count(array_unique($names))) {
            $this->addError('regions', 'Há bairros com o mesmo nome.');

            return;
        }

        $settings = $this->tenant->settings ?? [];

        data_set($settings, 'pickup', [
            'enabled' => $this->pickup_enabled,
            'fee_cents' => Money::toCents($this->pickup_fee) ?? 0,
        ]);

        data_set($settings, 'delivery', [
            'enabled' => $this->delivery_enabled,
            'minimum_order_cents' => Money::toCents($this->minimum_order) ?? 0,
            'default_fee_cents' => Money::toCents($this->default_fee) ?? 0,
            'estimated_time_minutes' => $this->estimated_time_minutes,
            'free_delivery_above_cents' => Money::toCents($this->free_delivery_above) ?? 0,
            'regions' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'name' => trim($r['name']),
                'fee_cents' => Money::toCents($r['fee']) ?? 0,
                'active' => (bool) $r['active'],
            ], $this->regions),
        ]);

        $this->tenant->settings = $settings;
        $this->tenant->save();

        session()->flash('status', 'Configurações de entrega salvas com sucesso!');
    }

    protected function authorizeView(): void
    {
        $this->authorize('manageSettings', $this->tenant);
    }

    public function render()
    {
        return view('livewire.admin.tenant.delivery-settings');
    }
}
