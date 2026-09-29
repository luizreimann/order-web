<?php

namespace App\Livewire\Tenant\Concerns;

use App\Models\Customer;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Autorização do cliente DENTRO da ação Livewire (defesa em profundidade).
 *
 * `POST /livewire/update` re-hidrata `public Customer $customer` do snapshot
 * SEM global scopes (`ModelSynth.php:65` → `newQueryForRestoration()`) e
 * `Model::save()` de instância também escreve sem scopes. O snapshot é
 * assinado com a APP_KEY e não acompanha logout, expiração ou
 * `is_active = false`. Logo a escrita NUNCA pode usar o model do snapshot.
 *
 * Desde E1.4/E1.5 a re-resolução é do guard `customer`
 * ({@see \App\Auth\CustomerSessionGuard}), que valida o cookie do request
 * real a cada requisição (tenant do path + `is_active`), e o middleware
 * persistente `tenant.customer.auth` já devolve 403 antes do componente. Esta
 * trait mantém as garantias que o guard sozinho não dá:
 *
 * - a escrita é feita sobre a instância vinda do banco NESTA requisição;
 * - o cliente autenticado precisa ser o dono do model do snapshot (cliente A
 *   não escreve em B reenviando o snapshot alheio).
 */
trait AuthenticatesTenantCustomer
{
    /**
     * Cliente autenticado NESTA requisição, ou `null`.
     */
    protected function resolveAuthenticatedCustomer(): ?Customer
    {
        $customer = Auth::guard('customer')->user();

        return $customer instanceof Customer ? $customer : null;
    }

    /**
     * Cliente autenticado, exigindo que ele seja o dono do model do snapshot.
     *
     * Devolve a instância re-resolvida no servidor: é NELA que as ações devem
     * escrever, nunca em `$this->customer` (que veio do snapshot, sem scopes).
     *
     * Aborta com 403 — e não redireciona — porque aqui não há navegação: é uma
     * interação de componente cuja autorização falhou.
     */
    protected function authenticatedCustomerOrAbort(): Customer
    {
        $customer = $this->resolveAuthenticatedCustomer();

        if ($customer === null) {
            abort(403, 'Sessão de cliente inválida ou expirada.');
        }

        $tenant = TenantContext::resolve()?->get();

        if ($tenant instanceof Tenant && $customer->tenant_id !== $tenant->id) {
            abort(403, 'Sessão de cliente de outro tenant.');
        }

        // `isset()`: a propriedade é tipada e pode estar não-inicializada (mount).
        if (isset($this->customer) && ! $customer->is($this->customer)) {
            abort(403, 'A sessão autenticada não corresponde ao cliente do componente.');
        }

        return $customer;
    }
}
