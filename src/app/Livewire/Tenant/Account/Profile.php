<?php

namespace App\Livewire\Tenant\Account;

use App\Livewire\Tenant\Concerns\AuthenticatesTenantCustomer;
use App\Models\Customer;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Profile extends Component
{
    use AuthenticatesTenantCustomer;

    public Tenant $tenant;

    public Customer $customer;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $customer = request()->get('authenticated_customer');

        if (! $customer) {
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

    /**
     * `mount()` valida a sessão, mas NÃO roda em `POST /livewire/update`. Sem a
     * revalidação abaixo, um payload de update capturado continuaria editando
     * este perfil depois de logout, expiração de cookie ou desativação do
     * cliente — o `Customer` vem do snapshot (hidratado sem global scopes) e
     * `save()` de instância também escreve sem scopes.
     *
     * Escrevemos na instância re-resolvida no servidor, nunca em
     * `$this->customer`. Ver {@see AuthenticatesTenantCustomer}.
     */
    public function save(): void
    {
        $customer = $this->authenticatedCustomerOrAbort();

        $this->validate();

        $customer->name = $this->name;
        $customer->email = $this->email;
        $customer->phone = $this->phone;
        $customer->save();

        $this->customer = $customer;

        session()->flash('status', 'Dados atualizados com sucesso!');
    }

    public function render()
    {
        return view('livewire.tenant.account.profile');
    }
}
