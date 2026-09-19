<?php

namespace App\Livewire\Tenant\Account;

use App\Models\Customer;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Profile extends Component
{
    public Tenant $tenant;
    public Customer $customer;

    public string $name = '';
    public string $email = '';
    public string $phone = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        
        $customer = request()->get('authenticated_customer');
        
        if (!$customer) {
            session()->flash('error', 'Você precisa fazer login para acessar esta página.');
            $this->redirect(route('tenant.home', ['tenant' => $tenant->slug]));
            return;
        }
        
        $this->customer = $customer;
        $this->name = $customer->name ?? '';
        $this->email = $customer->email ?? '';
        $this->phone = $customer->phone ?? '';
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:180'],
            'phone' => ['required', 'string', 'min:10', 'max:15'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $this->customer->name = $this->name;
        $this->customer->email = $this->email;
        $this->customer->phone = $this->phone;
        $this->customer->save();

        session()->flash('status', 'Dados atualizados com sucesso!');
    }

    public function render()
    {
        return view('livewire.tenant.account.profile');
    }
}
