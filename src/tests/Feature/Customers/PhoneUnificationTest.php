<?php

namespace Tests\Feature\Customers;

use App\Enums\Role;
use App\Livewire\Tenant\Auth\LoginPopup;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAuthService;
use App\Services\OrderService;
use Database\Seeders\CarolDocesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E3.2 (ARC-009) — um único telefone canônico por pessoa, em TODOS os caminhos de
 * entrada (checkout, login, admin, perfil). A prova é o resultado observável: o
 * mesmo `Customer`, e a unique parcial `(tenant_id, phone)` nunca violada por
 * diferença de formato.
 */
class PhoneUnificationTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    /** Três grafias do MESMO número. */
    private const FORMATS = ['(11) 99999-9999', '11999999999', '+55 11 99999-9999'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true]);
    }

    private function customers(?Tenant $tenant = null)
    {
        return Customer::withoutTenantScope()->where('tenant_id', ($tenant ?? $this->tenant)->id);
    }

    private function checkout(string $phone, ?Tenant $tenant = null)
    {
        $tenant ??= $this->tenant;
        $product = Product::query()->forTenant($tenant->id)->first()
            ?? Product::factory()->create(['tenant_id' => $tenant->id, 'price_cents' => 1000]);

        return app(OrderService::class)->createFromCart(
            $tenant,
            ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => $phone],
            [['product_id' => $product->id, 'qty' => 1]],
            'cash_on_delivery'
        );
    }

    // ------------------------------------------------------------------ checkout

    public function test_checkout_resolves_every_format_to_the_same_customer(): void
    {
        $ids = [];
        foreach (self::FORMATS as $format) {
            $ids[] = $this->checkout($format)->customer_id;
        }

        $this->assertCount(1, array_unique($ids), 'três grafias do mesmo número devem ser UM cliente');
        $this->assertSame(1, $this->customers()->count());
        $this->assertSame('11999999999', $this->customers()->firstOrFail()->phone);
    }

    public function test_order_keeps_the_phone_exactly_as_the_buyer_typed_it(): void
    {
        $order = $this->checkout('(11) 99999-9999');

        $this->assertSame('(11) 99999-9999', $order->customer_phone);
    }

    public function test_checkout_without_phone_still_creates_a_customer_with_null_phone(): void
    {
        $this->checkout('');
        $this->checkout('');

        // sem telefone não há como identificar: dois clientes, ambos com phone NULL
        $this->assertSame(2, $this->customers()->whereNull('phone')->count());
    }

    // ------------------------------------------------------------------ login

    public function test_login_resolves_every_format_to_the_same_customer(): void
    {
        $service = app(CustomerAuthService::class);

        $ids = [];
        foreach (self::FORMATS as $format) {
            $ids[] = $service->login($this->tenant, $format)['customer']->id;
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, $this->customers()->count());
    }

    public function test_checkout_then_login_then_checkout_share_one_customer(): void
    {
        $fromCheckout = $this->checkout('(11) 99999-9999')->customer_id;
        $fromLogin = app(CustomerAuthService::class)->login($this->tenant, '+55 11 99999-9999')['customer']->id;
        $fromSecondCheckout = $this->checkout('11999999999')->customer_id;

        $this->assertSame($fromCheckout, $fromLogin);
        $this->assertSame($fromCheckout, $fromSecondCheckout);
        $this->assertSame(1, $this->customers()->count());
    }

    public function test_login_popup_accepts_the_international_format(): void
    {
        Livewire::test(LoginPopup::class, ['tenant' => $this->tenant])
            ->set('phone', '+55 11 99999-9999')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertSame('11999999999', $this->customers()->firstOrFail()->phone);
    }

    public function test_login_popup_rejects_letters_and_too_short_numbers(): void
    {
        foreach (['abc', '12345', 'abcdefghijk'] as $bad) {
            Livewire::test(LoginPopup::class, ['tenant' => $this->tenant])
                ->set('phone', $bad)
                ->call('login')
                ->assertHasErrors(['phone']);
        }

        $this->assertSame(0, $this->customers()->count());
    }

    // ------------------------------------------------------------------ banco

    public function test_database_unique_is_never_violated_by_a_different_format(): void
    {
        // Mesmo gravando "cru" pelo model, o formato canônico é o que chega ao banco.
        Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'A', 'phone' => '(11) 99999-9999']);

        $this->assertSame('11999999999', $this->customers()->firstOrFail()->phone);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::transaction(fn () => Customer::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'phone' => '11999999999']));
    }

    public function test_same_phone_in_two_tenants_is_two_customers(): void
    {
        $other = Tenant::factory()->create(['slug' => 'outra', 'is_active' => true]);

        $a = $this->checkout('(11) 99999-9999')->customer_id;
        $b = $this->checkout('(11) 99999-9999', $other)->customer_id;

        $this->assertNotSame($a, $b);
    }

    public function test_concurrent_creation_of_the_same_phone_resolves_to_the_existing_customer(): void
    {
        // Simula a corrida: logo DEPOIS do SELECT que não achou o cliente (e antes do INSERT),
        // outra requisição cria o mesmo número. O INSERT roda num savepoint; a linha
        // concorrente entra fora dele, como se viesse de outra conexão já commitada.
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if ($injected || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'from "customers"')) {
                return;
            }
            $injected = true;
            DB::table('customers')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'tenant_id' => $this->tenant->id,
                'name' => 'Concorrente',
                'phone' => '11999999999',
                'is_active' => true,
                'meta' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $order = $this->checkout('(11) 99999-9999');

        $this->assertSame(1, $this->customers()->count());
        $this->assertSame('Concorrente', $this->customers()->firstOrFail()->name);
        $this->assertSame($this->customers()->firstOrFail()->id, $order->customer_id);
    }

    public function test_deleted_customer_phone_can_be_registered_again_in_any_format(): void
    {
        $old = $this->checkout('11999999999')->customer_id;
        Customer::withoutTenantScope()->findOrFail($old)->delete();

        $new = $this->checkout('(11) 99999-9999')->customer_id;

        $this->assertNotSame($old, $new);
    }

    public function test_demo_seeder_phones_are_already_canonical_and_valid(): void
    {
        Tenant::factory()->create(['slug' => 'caroldoces', 'is_active' => true]);
        $this->seed(CarolDocesDemoSeeder::class);

        $phones = Customer::withoutTenantScope()->whereNotNull('phone')->pluck('phone');

        $this->assertNotEmpty($phones);
        foreach ($phones as $phone) {
            $this->assertSame(\App\Support\Phone::normalize($phone), $phone);
            $this->assertTrue(\App\Support\Phone::isValid($phone), $phone);
        }
    }

    // ------------------------------------------------------------------ admin (endpoint real)

    private function adminCustomerFormSnapshot(string $path = '/loja/admin/clientes/novo'): string
    {
        $admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => Role::TenantAdmin]);
        $this->actingAs($admin, 'tenant_admin');

        $html = $this->get($path)->assertOk()->getContent();

        return $this->snapshotFor((string) $html, 'admin.customers.form');
    }

    public function test_admin_form_stores_the_canonical_phone(): void
    {
        $snapshot = $this->adminCustomerFormSnapshot();

        $this->callLivewire($snapshot, 'save', [], ['name' => 'Joana', 'phone' => '(11) 99999-9999'])->assertOk();

        $this->assertSame('11999999999', $this->customers()->where('name', 'Joana')->firstOrFail()->phone);
    }

    public function test_admin_form_rejects_a_duplicate_phone_written_in_another_format(): void
    {
        $existing = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Existente', 'phone' => '11999999999']);

        foreach (['(11) 99999-9999', '+55 11 99999-9999'] as $format) {
            $snapshot = $this->adminCustomerFormSnapshot();

            $response = $this->callLivewire($snapshot, 'save', [], ['name' => 'Duplicada', 'phone' => $format]);

            $this->assertNotNull($response->json('components.0.snapshot'), 'validação, não 500');
            $this->assertSame(0, $this->customers()->where('name', 'Duplicada')->count(), $format);
        }

        $this->assertSame($existing->id, $this->customers()->where('phone', '11999999999')->firstOrFail()->id);
    }

    public function test_admin_form_editing_a_customer_keeps_its_own_phone_valid(): void
    {
        $c = Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Antes', 'phone' => '11999999999']);

        $snapshot = $this->adminCustomerFormSnapshot("/loja/admin/clientes/{$c->id}/editar");

        $this->callLivewire($snapshot, 'save', [], ['name' => 'Depois', 'phone' => '(11) 99999-9999'])->assertOk();

        $fresh = Customer::withoutTenantScope()->findOrFail($c->id);
        $this->assertSame('Depois', $fresh->name);
        $this->assertSame('11999999999', $fresh->phone);
    }

    public function test_admin_form_allows_empty_phone(): void
    {
        $snapshot = $this->adminCustomerFormSnapshot();

        $this->callLivewire($snapshot, 'save', [], ['name' => 'Sem Telefone', 'phone' => ''])->assertOk();

        $this->assertNull($this->customers()->where('name', 'Sem Telefone')->firstOrFail()->phone);
    }

    // ------------------------------------------------------------------ perfil (endpoint real)

    public function test_profile_rejects_a_phone_that_belongs_to_another_customer_in_any_format(): void
    {
        $service = app(CustomerAuthService::class);
        $token = $service->generateSessionToken();

        $me = Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Eu', 'email' => 'eu@example.com',
            'phone' => '11888887777', 'session_token' => $token, 'is_active' => true,
        ]);
        Customer::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Outra', 'phone' => '11999999999']);

        $html = $this->withCredentials()
            ->withCookie($service->getCookieName($this->tenant), $token)
            ->get('/loja/conta/perfil')->assertOk()->getContent();
        $snapshot = $this->snapshotFor((string) $html, 'tenant.account.profile');

        $response = $this->callLivewire($snapshot, 'save', [], [
            'name' => 'Eu', 'email' => 'eu@example.com', 'phone' => '(11) 99999-9999',
        ]);

        $this->assertNotNull($response->json('components.0.snapshot'), 'validação, não 500');
        $this->assertSame('11888887777', Customer::withoutTenantScope()->findOrFail($me->id)->phone);
    }
}
