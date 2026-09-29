<?php

namespace Tests\Feature\Auth\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Sonda: expõe o que `Auth::guard('customer')` enxerga dentro do componente.
 */
class CustomerProbeComponent extends Component
{
    public Tenant $tenant;

    public ?string $seenCustomerId = null;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->seenCustomerId = Auth::guard('customer')->user()?->getAuthIdentifier();
    }

    public function probe(): void
    {
        $this->seenCustomerId = Auth::guard('customer')->user()?->getAuthIdentifier();
    }

    public function render(): string
    {
        return '<div>customer:{{ $seenCustomerId }}</div>';
    }
}
