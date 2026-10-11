<?php

namespace Tests\Feature\Database;

use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E2.6 — as telas mostram rótulo (`label()`) e cor (`variant()`) do enum, sem `@if`
 * por string e sem `primary`/`danger` em badge de status.
 */
class StatusViewsTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true]);
        $this->actingAs(
            User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]),
            'tenant_admin'
        );
    }

    public function test_admin_orders_index_renders_label_and_variant_of_every_status(): void
    {
        foreach (OrderStatus::cases() as $status) {
            Order::factory()->create(['tenant_id' => $this->tenant->id, 'status' => $status]);
        }
        foreach (PaymentStatus::cases() as $status) {
            Order::factory()->create(['tenant_id' => $this->tenant->id, 'payment_status' => $status]);
        }

        $html = $this->get('/loja/admin/pedidos')->assertOk()->getContent();

        foreach (OrderStatus::cases() as $status) {
            $this->assertStringContainsString(
                '<span class="badge text-bg-'.$status->variant().'">'.$status->label().'</span>',
                $html,
                $status->name
            );
        }
        foreach (PaymentStatus::cases() as $status) {
            $this->assertStringContainsString(
                '<span class="badge text-bg-'.$status->variant().'">'.$status->label().'</span>',
                $html,
                $status->name
            );
        }

        // Nunca o status cru em inglês.
        $this->assertStringNotContainsString('>pending<', $html);
        $this->assertStringNotContainsString('>confirmed<', $html);
    }

    public function test_admin_orders_index_ignores_invalid_status_filter(): void
    {
        Order::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->get('/loja/admin/pedidos?status=bogus')->assertOk();
    }

    public function test_payment_settings_renders_gateway_status_with_neutral_fallback_for_unknown(): void
    {
        $order = Order::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach (['approved', 'in_process', 'rejected', 'status_novo_do_mp'] as $raw) {
            Payment::factory()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'status' => $raw]);
        }

        // A lista fica na aba "Histórico": abre a página e troca de aba pelo endpoint do Livewire.
        $snapshot = $this->snapshotFor($this->get('/loja/admin/pagamentos')->assertOk()->getContent(), 'admin.tenant.payment-settings');
        $html = (string) $this->callLivewire($snapshot, 'setTab', ['history'])->assertOk()->json('components.0.effects.html');

        $this->assertStringContainsString('<span class="badge text-bg-'.GatewayPaymentStatus::APPROVED->variant().'">Aprovado</span>', $html);
        $this->assertStringContainsString('<span class="badge text-bg-'.GatewayPaymentStatus::IN_PROCESS->variant().'">Processando</span>', $html);
        $this->assertStringContainsString('<span class="badge text-bg-'.GatewayPaymentStatus::REJECTED->variant().'">Rejeitado</span>', $html);
        $this->assertStringContainsString('<span class="badge text-bg-secondary">status_novo_do_mp</span>', $html);
        $this->assertStringNotContainsString('badge text-bg-danger', $html);
    }
}
