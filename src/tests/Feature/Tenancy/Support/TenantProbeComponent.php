<?php

namespace Tests\Feature\Tenancy\Support;

use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Livewire\Component;

/**
 * Sonda usada por {@see \Tests\Feature\Tenancy\LivewirePersistentMiddlewareTest}.
 *
 * É o único componente Livewire do projeto que consulta um model escopado
 * SEM `->forTenant()` — ou seja, confiando exclusivamente no global scope
 * {@see \App\Models\Scopes\TenantScope}. É exatamente a forma que o item E1.2
 * quer generalizar, então serve de canário: se o `TenantContext` não estiver
 * populado durante o `POST /livewire/update`, a sonda enxerga os dois tenants.
 */
class TenantProbeComponent extends Component
{
    public Tenant $tenant;

    public bool $contextHasTenant = false;

    public ?string $contextTenantId = null;

    public int $visibleProducts = 0;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function probe(): void
    {
        $context = app(TenantContext::class);

        $this->contextHasTenant = $context->hasTenant();
        $this->contextTenantId = $context->id();

        // Sem `->forTenant()`: só o global scope pode isolar aqui.
        $this->visibleProducts = Product::query()->count();
    }

    public function render(): string
    {
        return <<<'HTML'
        <div>probe:{{ $visibleProducts }}</div>
        HTML;
    }
}
