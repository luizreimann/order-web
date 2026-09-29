<?php

namespace App\Livewire\Tenant\Account;

use App\Livewire\Tenant\Concerns\AuthenticatesTenantCustomer;
use App\Models\Customer;
use App\Models\Tenant;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    use AuthenticatesTenantCustomer;

    public Tenant $tenant;

    public Customer $customer;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // A exigência de login é do middleware `tenant.customer.auth`; se
        // chegou aqui sem cliente, é 403 (nunca deixa `$customer` sem valor).
        $this->customer = $this->authenticatedCustomerOrAbort();
    }

    /**
     * Não há ação de escrita aqui, mas há LEITURA de dados do cliente a partir
     * do model do snapshot — que sobrevive à revogação da sessão. Revalidar no
     * render fecha também o vazamento de leitura num `POST /livewire/update`
     * replayado.
     *
     * No caminho de página o comportamento não muda: quando não há cliente
     * autenticado, `mount()` redireciona e o Livewire pula o render
     * (`SupportRedirects/HandlesRedirects.php:15-17`).
     */
    public function render()
    {
        $customer = $this->authenticatedCustomerOrAbort();

        $recentOrders = $customer->orders()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('livewire.tenant.account.index', [
            'recentOrders' => $recentOrders,
        ]);
    }
}
