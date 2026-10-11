<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E3.2b (P1) — o checkout é PÚBLICO: quem souber o telefone de um cliente não pode
 * reescrever os dados dele.
 *
 * DECISÃO DE PRODUTO (variante conservadora, reversível): ao encontrar um cliente
 * existente pelo telefone, o checkout de convidado só PREENCHE campos vazios; nunca
 * sobrescreve nome/e-mail já preenchidos. O pedido guarda o snapshot informado
 * (`customer_name`/`customer_phone`), então o dado do pedido não se perde.
 */
class GuestCheckoutCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 1000]);
    }

    private function guestCheckout(array $customerData)
    {
        return app(OrderService::class)->createFromCart(
            $this->tenant,
            $customerData,
            [['product_id' => $this->product->id, 'qty' => 1]],
            'cash_on_delivery'
        );
    }

    public function test_guest_checkout_does_not_overwrite_name_or_email_of_an_existing_customer(): void
    {
        $victim = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Vítima Real',
            'email' => 'vitima@example.com',
            'phone' => '11999999999',
        ]);

        $order = $this->guestCheckout([
            'name' => 'Atacante',
            'email' => 'atacante@example.com',
            'phone' => '(11) 99999-9999',
        ]);

        $fresh = Customer::withoutTenantScope()->findOrFail($victim->id);
        $this->assertSame('Vítima Real', $fresh->name);
        $this->assertSame('vitima@example.com', $fresh->email);
        $this->assertSame('11999999999', $fresh->phone);

        // o pedido é do cliente existente, mas guarda o que foi informado
        $this->assertSame($victim->id, $order->customer_id);
        $this->assertSame('Atacante', $order->customer_name);
        $this->assertSame('(11) 99999-9999', $order->customer_phone);
        $this->assertSame(1, Customer::withoutTenantScope()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_guest_checkout_fills_only_the_empty_fields_of_an_existing_customer(): void
    {
        // Cliente criado só pelo login: nome e e-mail vazios.
        $c = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => '',
            'email' => null,
            'phone' => '11999999999',
        ]);

        $this->guestCheckout(['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => '11999999999']);

        $fresh = Customer::withoutTenantScope()->findOrFail($c->id);
        $this->assertSame('Maria', $fresh->name);
        $this->assertSame('maria@example.com', $fresh->email);
    }

    public function test_guest_checkout_fills_a_single_empty_field_and_keeps_the_other(): void
    {
        $c = Customer::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Nome Existente',
            'email' => '',
            'phone' => '11999999999',
        ]);

        $this->guestCheckout(['name' => 'Outro Nome', 'email' => 'novo@example.com', 'phone' => '11999999999']);

        $fresh = Customer::withoutTenantScope()->findOrFail($c->id);
        $this->assertSame('Nome Existente', $fresh->name);
        $this->assertSame('novo@example.com', $fresh->email);
    }

    public function test_new_customer_still_gets_the_informed_data(): void
    {
        $order = $this->guestCheckout(['name' => 'Nova', 'email' => 'nova@example.com', 'phone' => '(21) 98888-7777']);

        $customer = Customer::withoutTenantScope()->findOrFail($order->customer_id);
        $this->assertSame('Nova', $customer->name);
        $this->assertSame('nova@example.com', $customer->email);
        $this->assertSame('21988887777', $customer->phone);
    }
}
