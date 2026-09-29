<?php

namespace App\Livewire\Tenant\Concerns;

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Revalidação da sessão do cliente DENTRO da ação Livewire.
 *
 * ## Por que isso é necessário
 *
 * `mount()` só roda no primeiro render. Toda interação posterior é um
 * `POST /livewire/update` que:
 *
 * - re-hidrata `public Customer $customer` a partir do snapshot **sem global
 *   scopes** (`Features/SupportModels/ModelSynth.php:65` e
 *   `Features/SupportLegacyModels/EloquentModelSynth.php:207` chamam
 *   `Model::newQueryForRestoration()`, que é
 *   `newQueryWithoutScopes()->whereKey()` — `Model.php:1669-1672`);
 * - escreve com `Model::save()` de instância, que usa `newModelQuery()`
 *   (`Model.php:1219` e `1608`) — também sem global scopes;
 * - NÃO re-executa o middleware da rota original, exceto o que estiver na
 *   allowlist de `Livewire::addPersistentMiddleware()`.
 *
 * E mesmo com `TenantCustomerAuth` na allowlist a informação não chega: o
 * middleware persistente roda sobre uma requisição DUPLICADA
 * (`Mechanisms/PersistentMiddleware/PersistentMiddleware.php:101-129`,
 * `request()->duplicate()`), então o `authenticated_customer` que ele injeta
 * via `$request->merge()` não existe no request que o componente enxerga.
 * Além disso `TenantCustomerAuth` nunca aborta (por contrato; endurecê-lo é o
 * item E1.5).
 *
 * Portanto a única barreira efetiva num payload de update replayado é o
 * checksum HMAC do snapshot — que NÃO expira e NÃO acompanha logout,
 * expiração de cookie nem `is_active = false`.
 *
 * ## Abordagem
 *
 * Em vez de confiar no model do snapshot, a ação **re-resolve o cliente no
 * servidor** a partir do cookie de sessão (`CustomerAuthService::
 * validateSessionToken()`, que já filtra por tenant e por `is_active`) e exige
 * que ele seja o mesmo do snapshot. Duas garantias de uma vez:
 *
 * - revogação (logout / cookie expirado / cliente desativado) passa a barrar a
 *   escrita, porque a validação acontece no momento da escrita;
 * - a escrita é feita sobre a instância vinda do banco NESTA requisição, nunca
 *   sobre a do snapshot — um cliente autenticado não consegue escrever no
 *   perfil de outro reenviando o snapshot alheio.
 */
trait AuthenticatesTenantCustomer
{
    /**
     * Cliente autenticado NESTA requisição, ou `null`.
     *
     * Ordem de resolução:
     * 1. o que `TenantCustomerAuth` anotou no request (caminho de página, GET);
     * 2. re-resolução pelo cookie (caminho `POST /livewire/update`, onde o item
     *    1 nunca está presente — ver docblock da trait).
     */
    protected function resolveAuthenticatedCustomer(): ?Customer
    {
        /** @var Request $request */
        $request = request();

        $fromRequest = $request->attributes->get('customer') ?? $request->get('authenticated_customer');

        if ($fromRequest instanceof Customer) {
            return $fromRequest;
        }

        $tenant = $this->authenticatedCustomerTenant();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $authService = app(CustomerAuthService::class);
        $sessionToken = $request->cookie($authService->getCookieName($tenant));

        if (! is_string($sessionToken) || $sessionToken === '') {
            return null;
        }

        return $authService->validateSessionToken($tenant, $sessionToken);
    }

    /**
     * Tenant usado para validar o cookie.
     *
     * Prioriza o {@see TenantContext} — populado por `ResolveTenantFromPath`, a
     * partir do PATH da requisição — e só cai no `$this->tenant` do snapshot
     * como último recurso.
     */
    private function authenticatedCustomerTenant(): ?Tenant
    {
        $tenant = TenantContext::resolve()?->get();

        if ($tenant instanceof Tenant) {
            return $tenant;
        }

        // `isset()` e não `??`: a propriedade é tipada e pode estar
        // não-inicializada (mount abortado por redirect, por exemplo).
        return isset($this->tenant) ? $this->tenant : null;
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

        $tenant = $this->authenticatedCustomerTenant();

        if ($tenant !== null && $customer->tenant_id !== $tenant->id) {
            abort(403, 'Sessão de cliente de outro tenant.');
        }

        if (isset($this->customer) && ! $customer->is($this->customer)) {
            abort(403, 'A sessão autenticada não corresponde ao cliente do componente.');
        }

        return $customer;
    }
}
