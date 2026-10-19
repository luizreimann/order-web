<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (módulo Pedidos) — o formulário de status do pedido, pelo endpoint real
 * (`POST /livewire/update`): `updateStatus` inválido marca selects/input e mostra o feedback.
 * Também prova que os badges de status vêm dos enums (rótulo e variante).
 */
class OrderShowValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create(['slug' => 'tenant-a', 'is_active' => true]);
        $this->order = Order::factory()->create(['tenant_id' => $this->tenant->id, 'customer_name' => 'Ana']);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /** @param array<string, mixed> $updates */
    private function update(array $updates): TestResponse
    {
        $page = $this->get("/tenant-a/admin/pedidos/{$this->order->id}")->assertOk();

        return $this->callLivewire($this->snapshotFor($page->getContent(), 'admin.orders.show'), 'updateStatus', [], $updates);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidInputs(): array
    {
        return [
            'status inexistente' => [['status' => 'bogus'], 'status', 'select'],
            'pagamento inexistente' => [['payment_status' => 'nope'], 'payment_status', 'select'],
            'método longo demais' => [['payment_method' => str_repeat('x', 51)], 'payment_method', 'input'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('invalidInputs')]
    public function test_invalid_update_marks_the_field_and_shows_the_message(array $updates, string $id, string $tag): void
    {
        $html = $this->html($this->update($updates));

        $control = $this->first($html, "//{$tag}[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "campo [{$id}] sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'));
        $this->assertSame(1, $this->countNodes($html, "//label[@for='{$id}']"));

        $feedback = $this->first($html, "//{$tag}[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent));
    }

    public function test_only_the_invalid_field_is_marked(): void
    {
        $html = $this->html($this->update(['status' => 'bogus']));

        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_every_invalid_field_is_marked_at_once(): void
    {
        $html = $this->html($this->update(['status' => 'bogus', 'payment_status' => 'nope', 'payment_method' => str_repeat('x', 51)]));

        $this->assertSame(3, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(3, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_valid_update_has_no_invalid_markup_and_persists(): void
    {
        $html = $this->html($this->update(['status' => 'confirmed', 'payment_status' => 'paid']));

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
        $order = Order::query()->findOrFail($this->order->id);
        $this->assertSame(OrderStatus::CONFIRMED, $order->status);
        $this->assertSame(PaymentStatus::PAID, $order->payment_status);
    }

    public function test_invalid_update_does_not_persist_anything(): void
    {
        $this->update(['status' => 'bogus']);

        $this->assertSame(OrderStatus::PENDING, Order::query()->findOrFail($this->order->id)->status);
    }

    public function test_status_selects_list_every_enum_case_with_its_label(): void
    {
        $html = $this->get("/tenant-a/admin/pedidos/{$this->order->id}")->assertOk()->getContent();

        foreach (OrderStatus::cases() as $case) {
            $this->assertSame($case->label(), trim($this->first($html, "//select[@id='status']/option[@value='{$case->value}']")->textContent));
        }
        foreach (PaymentStatus::cases() as $case) {
            $this->assertSame($case->label(), trim($this->first($html, "//select[@id='payment_status']/option[@value='{$case->value}']")->textContent));
        }
    }

    public function test_index_badges_come_from_the_enums(): void
    {
        Order::factory()->create(['tenant_id' => $this->tenant->id, 'status' => OrderStatus::DONE, 'payment_status' => PaymentStatus::REFUNDED]);

        $html = $this->get('/tenant-a/admin/pedidos')->assertOk()->getContent();

        foreach ([
            [OrderStatus::PENDING, PaymentStatus::UNPAID],
            [OrderStatus::DONE, PaymentStatus::REFUNDED],
        ] as [$status, $payment]) {
            foreach ([$status, $payment] as $enum) {
                $this->assertGreaterThan(
                    0,
                    $this->countNodes($html, "//span[contains(@class,'badge')][contains(@class,'text-bg-{$enum->variant()}')][normalize-space()='{$enum->label()}']"),
                    $enum->label(),
                );
            }
        }
    }
}
