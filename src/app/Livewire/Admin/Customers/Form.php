<?php

namespace App\Livewire\Admin\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public Tenant $tenant;
    public ?Customer $customerModel = null;

    public string $name = '';
    public ?string $email = null;
    public ?string $phone = null;
    public bool $is_active = true;

    // Endereço principal (is_default=true)
    public bool $has_address = false;
    public ?string $address_label = 'Principal';
    public ?string $address_line1 = null;
    public ?string $address_line2 = null;
    public ?string $address_city = null;
    public ?string $address_state = null;
    public ?string $address_postal_code = null;

    public function mount(Tenant $tenant, ?string $customerId = null): void
    {
        $this->tenant = $tenant;

        if ($customerId) {
            $this->customerModel = Customer::query()
                ->forTenant($tenant->id)
                ->where('id', $customerId)
                ->firstOrFail();

            $this->fillFromModel();
        }
    }

    protected function rules(): array
    {
        $id = $this->customerModel?->id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],

            // phone é opcional, mas se preencher precisa ser único por tenant
            'phone' => [
                'nullable',
                'string',
                'max:40',
                Rule::unique('customers', 'phone')
                    ->where(fn($q) => $q->where('tenant_id', $this->tenant->id))
                    ->ignore($id),
            ],

            'is_active' => ['boolean'],

            'has_address' => ['boolean'],
            'address_label' => ['nullable', 'string', 'max:80'],
            'address_line1' => [Rule::requiredIf(fn() => $this->has_address), 'nullable', 'string', 'max:190'],
            'address_line2' => ['nullable', 'string', 'max:190'],
            'address_city' => ['nullable', 'string', 'max:120'],
            'address_state' => ['nullable', 'string', 'max:80'],
            'address_postal_code' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $data = [
            'tenant_id' => $this->tenant->id,
            'name' => $this->name,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'is_active' => $this->is_active,
        ];

        if ($this->customerModel) {
            $this->customerModel->fill($data)->save();
        } else {
            $this->customerModel = Customer::create($data);
        }

        $this->syncDefaultAddress();

        session()->flash('status', 'Cliente salvo com sucesso!');
        $this->redirectRoute('tenant.admin.customers.index', ['tenant' => $this->tenant->slug], navigate: true);
    }

    private function syncDefaultAddress(): void
    {
        if (!$this->customerModel) return;

        $existing = CustomerAddress::query()
            ->forTenant($this->tenant->id)
            ->where('customer_id', $this->customerModel->id)
            ->where('is_default', true)
            ->first();

        if (!$this->has_address) {
            if ($existing) $existing->delete();
            return;
        }

        $addrData = [
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customerModel->id,
            'label' => $this->address_label ?: 'Principal',
            'is_default' => true,
            'line1' => $this->address_line1,
            'line2' => $this->address_line2 ?: null,
            'city' => $this->address_city ?: null,
            'state' => $this->address_state ?: null,
            'postal_code' => $this->address_postal_code ?: null,
        ];

        if ($existing) {
            $existing->fill($addrData)->save();
        } else {
            CustomerAddress::create($addrData);
        }
    }

    private function fillFromModel(): void
    {
        $c = $this->customerModel;

        $this->name = $c->name;
        $this->email = $c->email;
        $this->phone = $c->phone;
        $this->is_active = (bool) $c->is_active;

        $addr = CustomerAddress::query()
            ->forTenant($this->tenant->id)
            ->where('customer_id', $c->id)
            ->where('is_default', true)
            ->first();

        if ($addr) {
            $this->has_address = true;
            $this->address_label = $addr->label ?: 'Principal';
            $this->address_line1 = $addr->line1;
            $this->address_line2 = $addr->line2;
            $this->address_city = $addr->city;
            $this->address_state = $addr->state;
            $this->address_postal_code = $addr->postal_code;
        }
    }

    public function render()
    {
        return view('livewire.admin.customers.form')
            ->layout('layouts.tenant-admin');
    }
}