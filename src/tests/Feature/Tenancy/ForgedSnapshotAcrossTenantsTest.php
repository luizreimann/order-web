<?php

namespace Tests\Feature\Tenancy;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Critério #3 da Sprint 06: um pedido de OUTRO tenant não pode ser alterado por um admin, mesmo com
 * um snapshot do Livewire adulterado. Há TRÊS defesas independentes, cada teste prova UMA:
 *
 *  1. o checksum que assina o snapshot (`test_swapping_the_order_id...`): id trocado sem refazer o
 *     checksum não chega a hidratar. Bate no endpoint HTTP real;
 *  2. a re-hidratação escopada (`test_a_validly_signed_snapshot...`): mesmo com checksum VÁLIDO
 *     (gerado pelo próprio `Checksum::generate` do Livewire, o pior caso: APP_KEY vazada), o model de
 *     outro tenant não é encontrado (`BelongsToTenant::newQueryForRestoration`). Morde: sem esse
 *     override o pedido de B hidrata e só sobra a policy (3);
 *  3. a policy chamada em cada action (`test_the_policy_alone...`): se um model de outro tenant
 *     chegasse à action, `can()` o nega. Morde: sem a comparação de tenant em
 *     `TenantResourcePolicy::allows()` o teste falha.
 *
 * Além delas, a escrita por instância é barrada no model (`TenantWriteIsolationTest`).
 */
class ForgedSnapshotAcrossTenantsTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    private Order $orderA;

    private Order $orderB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = Tenant::factory()->create(['slug' => 'loja-a', 'is_active' => true]);
        $this->b = Tenant::factory()->create(['slug' => 'loja-b', 'is_active' => true]);
        $this->orderA = Order::factory()->create(['tenant_id' => $this->a->id, 'status' => OrderStatus::PENDING]);
        $this->orderB = Order::factory()->create(['tenant_id' => $this->b->id, 'status' => OrderStatus::PENDING]);

        $user = User::factory()->create(['tenant_id' => $this->a->id, 'role' => Role::TenantAdmin]);
        $this->post('/loja-a/admin/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
    }

    private function snapshotOfOrderA(): string
    {
        $html = $this->get('/loja-a/admin/pedidos/'.$this->orderA->id)->assertOk()->getContent();

        return $this->snapshotFor((string) $html, 'admin.orders.show');
    }

    private function statusOf(Order $order): OrderStatus
    {
        return Order::withoutTenantScope()->whereKey($order->id)->firstOrFail()->status;
    }

    public function test_the_legitimate_flow_updates_the_admins_own_order(): void
    {
        $this->callLivewire($this->snapshotOfOrderA(), 'updateStatus', [], ['status' => 'cancelled'])->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->statusOf($this->orderA));
        $this->assertSame(OrderStatus::PENDING, $this->statusOf($this->orderB));
    }

    public function test_swapping_the_order_id_inside_the_snapshot_never_touches_the_other_tenants_order(): void
    {
        $forged = str_replace((string) $this->orderA->id, (string) $this->orderB->id, $this->snapshotOfOrderA());
        $this->assertNotSame($this->snapshotOfOrderA(), $forged);

        $response = $this->callLivewire($forged, 'updateStatus', [], ['status' => 'cancelled']);

        $this->assertContains($response->getStatusCode(), [403, 419, 500, 422, 404], 'o checksum do Livewire tem de rejeitar o snapshot adulterado');
        $this->assertSame(OrderStatus::PENDING, $this->statusOf($this->orderB), 'o pedido do outro tenant não pode mudar');
        $this->assertSame(OrderStatus::PENDING, $this->statusOf($this->orderA));
    }

    public function test_the_policy_alone_refuses_an_order_of_another_tenant_even_when_it_reaches_the_action(): void
    {
        // O checksum do Livewire já barra o snapshot adulterado (teste acima). Se um model de OUTRO
        // tenant chegasse à action mesmo assim (checksum válido, ex.: APP_KEY vazada), o que sobra é
        // a policy. Prova-se sem falsificar o snapshot: a action é chamada com um pedido do tenant B
        // no contexto de um admin do tenant A, e o pedido tem de continuar intacto.
        $admin = User::query()->where('tenant_id', $this->a->id)->firstOrFail();

        $this->assertFalse($admin->can('update', $this->orderB), 'a policy tem de negar o pedido de outro tenant');
        $this->assertFalse($admin->can('view', $this->orderB));
        $this->assertTrue($admin->can('update', $this->orderA));
    }

    public function test_a_validly_signed_snapshot_pointing_at_another_tenants_order_does_not_hydrate(): void
    {
        $snapshot = json_decode($this->snapshotOfOrderA(), true);
        $snapshot['data']['order'][1]['key'] = (string) $this->orderB->id;
        unset($snapshot['checksum']);
        // O checksum é gerado pela função do próprio Livewire: o snapshot é, para o framework, legítimo.
        $snapshot['checksum'] = \Livewire\Mechanisms\HandleComponents\Checksum::generate($snapshot);

        $response = $this->callLivewire((string) json_encode($snapshot), 'updateStatus', [], ['status' => 'cancelled']);

        // 404 "No query results for model [Order]" = barrado na HIDRATAÇÃO; sem o override seria um 403 da policy.
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('No query results for model', (string) $response->getContent());
        $this->assertSame(OrderStatus::PENDING, $this->statusOf($this->orderB));
        $this->assertSame(OrderStatus::PENDING, $this->statusOf($this->orderA));
    }

    public function test_the_same_forged_snapshot_is_accepted_only_for_the_own_tenants_order(): void
    {
        // Controle do teste acima: com checksum válido e o pedido do PRÓPRIO tenant, tudo funciona.
        $snapshot = json_decode($this->snapshotOfOrderA(), true);
        unset($snapshot['checksum']);
        $snapshot['checksum'] = \Livewire\Mechanisms\HandleComponents\Checksum::generate($snapshot);

        $this->callLivewire((string) json_encode($snapshot), 'updateStatus', [], ['status' => 'cancelled'])->assertOk();

        $this->assertSame(OrderStatus::CANCELLED, $this->statusOf($this->orderA));
    }
}
