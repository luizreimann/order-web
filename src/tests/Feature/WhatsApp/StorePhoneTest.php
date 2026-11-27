<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\Role;
use App\Livewire\Admin\Customers\Form;
use App\Livewire\Admin\Tenant\ContactSettings;
use App\Livewire\Tenant\Checkout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use App\Support\TenantContext;
use App\Support\TenantHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Telefone gravado e publicado: o servidor valida, grava o canônico e reabre com máscara.
 */
class StorePhoneTest extends TestCase
{
    use InteractsWithLivewireEndpoint, InteractsWithMercadoPago, RefreshDatabase;

    private const MESSAGE = 'Informe um telefone válido com DDD (ex.: (11) 99999-9999).';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function rejectedPhones(): array
    {
        return [
            'ddd com zero' => ['(01) 99999-9999'],
            'texto livre' => ['recepção'],
            'outro país' => ['+1 202 555 0143'],
            'valor cru' => ['123'],
        ];
    }

    public function test_an_empty_optional_contact_phone_is_stored_empty_and_siblings_survive(): void
    {
        $tenant = $this->contactTenant();
        $this->adminContext($tenant);

        Livewire::test(ContactSettings::class, ['tenant' => $tenant])
            ->set('phone', '')
            ->set('whatsapp', '')
            ->set('email', 'novo@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('phone', null)
            ->assertSet('whatsapp', null);

        $settings = $tenant->fresh()->settings;
        $this->assertNull($settings['contact']['phone']);
        $this->assertNull($settings['contact']['whatsapp']);
        $this->assertSame('novo@example.com', $settings['contact']['email']);
        $this->assertSame('Oi', $settings['contact']['whatsapp_message']);
        $this->assertTrue($settings['contact']['show_whatsapp_button']);
        $this->assertSame('fica', $settings['payment']['marker']);
        $this->assertSame('connected', $settings['whatsapp']['status']);
        $this->assertSame('11888887777', $settings['whatsapp']['number']);
    }

    #[DataProvider('rejectedPhones')]
    public function test_an_invalid_contact_phone_does_not_save_the_form(string $bad): void
    {
        $tenant = $this->contactTenant();

        foreach (['phone', 'whatsapp'] as $field) {
            $fresh = $tenant->fresh();
            $this->adminContext($fresh);

            Livewire::test(ContactSettings::class, ['tenant' => $fresh])
                ->set($field, $bad)
                ->set('email', 'novo@example.com')
                ->call('save')
                ->assertHasErrors([$field])
                ->assertSee(self::MESSAGE);

            $settings = $tenant->fresh()->settings;
            $this->assertSame('1133334444', $settings['contact']['phone'], $field);
            $this->assertSame('5511999990000', $settings['contact']['whatsapp'], $field);
            $this->assertSame('antes@example.com', $settings['contact']['email'], $field);
            $this->assertSame('Oi', $settings['contact']['whatsapp_message'], $field);
            $this->assertTrue($settings['contact']['show_whatsapp_button'], $field);
            $this->assertSame('fica', $settings['payment']['marker'], $field);
            $this->assertSame('11888887777', $settings['whatsapp']['number'], $field);
        }
    }

    public function test_contact_whatsapp_stores_the_national_canonical_and_reopens_with_the_mask(): void
    {
        $tenant = $this->contactTenant();

        foreach (['(11) 99999-9999', '11999999999', '+55 11 99999-9999', '5511999999999'] as $spelling) {
            $fresh = $tenant->fresh();
            $this->adminContext($fresh);

            Livewire::test(ContactSettings::class, ['tenant' => $fresh])
                ->set('phone', '(11) 3333-4444')
                ->set('whatsapp', $spelling)
                ->call('save')
                ->assertHasNoErrors()
                ->assertSet('phone', '(11) 3333-4444')
                ->assertSet('whatsapp', '(11) 99999-9999');

            $contact = $tenant->fresh()->settings['contact'];
            $this->assertSame('1133334444', $contact['phone'], $spelling);
            $this->assertSame('11999999999', $contact['whatsapp'], $spelling);
        }

        $saved = $tenant->fresh();
        $this->assertSame('fica', $saved->settings['payment']['marker']);
        $this->assertSame('11888887777', $saved->settings['whatsapp']['number']);
        $this->assertSame('https://wa.me/5511999999999?text=Oi', TenantHelper::getWhatsAppUrl($saved));

        $this->adminContext($saved);

        Livewire::test(ContactSettings::class, ['tenant' => $saved])
            ->assertSet('phone', '(11) 3333-4444')
            ->assertSet('whatsapp', '(11) 99999-9999');
    }

    public function test_reopening_contact_keeps_old_text_that_is_not_a_phone(): void
    {
        $tenant = $this->contactTenant();
        $settings = $tenant->settings;
        $settings['contact']['phone'] = 'recepção';
        $tenant->update(['settings' => $settings]);

        $fresh = $tenant->fresh();
        $this->adminContext($fresh);

        Livewire::test(ContactSettings::class, ['tenant' => $fresh])
            ->assertSet('phone', 'recepção')
            ->assertSet('whatsapp', '(11) 99999-0000');
    }

    public function test_whatsapp_url_prefixes_55_on_the_national_number_and_keeps_the_legacy(): void
    {
        $legacy = $this->tenantWithWhatsapp('5511999990000', 'Olá!');
        $national = $this->tenantWithWhatsapp('11999990000', 'Olá!');

        $this->assertSame('https://wa.me/5511999990000?text=Ol%C3%A1%21', TenantHelper::getWhatsAppUrl($legacy));
        $this->assertSame(TenantHelper::getWhatsAppUrl($legacy), TenantHelper::getWhatsAppUrl($national));
        $this->assertNull(TenantHelper::getWhatsAppUrl($this->tenantWithWhatsapp(null)));

        $withSessionNumber = $this->tenantWithWhatsapp('11999990000');
        $settings = $withSessionNumber->settings;
        $settings['whatsapp'] = ['status' => 'connected', 'number' => '11888887777'];
        $withSessionNumber->settings = $settings;

        $this->assertSame('https://wa.me/5511999990000', TenantHelper::getWhatsAppUrl($withSessionNumber));
    }

    public function test_the_published_phone_shows_the_area_code_and_old_text_stays_visible(): void
    {
        Tenant::factory()->create([
            'slug' => 'loja-mascara',
            'is_active' => true,
            'settings' => [
                'contact' => [
                    'phone' => '1133334444',
                    'whatsapp' => '5511999990000',
                    'whatsapp_message' => 'Olá!',
                    'show_whatsapp_button' => true,
                ],
            ],
        ]);

        $html = $this->get('/loja-mascara')->assertOk()->getContent();
        $this->assertStringContainsString('(11) 3333-4444', $html);
        $this->assertStringContainsString('(11) 99999-0000', $html);
        $this->assertStringContainsString('tel:1133334444', $html);
        $this->assertStringContainsString('https://wa.me/5511999990000?text=Ol%C3%A1%21', $html);
        $this->assertStringNotContainsString('5511999990000<', $html);

        Tenant::factory()->create([
            'slug' => 'loja-texto',
            'is_active' => true,
            'settings' => [
                'contact' => [
                    'phone' => 'recepção',
                    'whatsapp' => '5511999990000',
                    'show_whatsapp_button' => true,
                ],
            ],
        ]);

        $old = $this->get('/loja-texto')->assertOk()->getContent();
        $this->assertStringContainsString('recepção', $old);
        $this->assertStringContainsString('https://wa.me/5511999990000', $old);
        $this->assertStringContainsString('tel:recepção', $old);
    }

    #[DataProvider('rejectedPhones')]
    public function test_manual_checkout_with_an_invalid_phone_does_not_create_an_order(string $bad): void
    {
        $tenant = $this->manualTenant();

        Livewire::test(Checkout::class, ['tenant' => $tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_phone', $bad)
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'cash_on_delivery')
            ->call('placeOrderManual')
            ->assertHasErrors(['customer_phone'])
            ->assertSee(self::MESSAGE);

        $this->assertSame(0, Order::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    public function test_manual_checkout_with_an_empty_phone_creates_the_order(): void
    {
        $tenant = $this->manualTenant();

        Livewire::test(Checkout::class, ['tenant' => $tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_phone', '')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'cash_on_delivery')
            ->call('placeOrderManual')
            ->assertHasNoErrors();

        $this->assertSame(1, Order::withoutTenantScope()->where('tenant_id', $tenant->id)->count());
    }

    #[DataProvider('rejectedPhones')]
    public function test_pay_online_with_an_invalid_phone_does_not_create_an_order(string $bad): void
    {
        Http::fake();
        $tenant = $this->mpTenant('loja-online-'.substr(md5($bad), 0, 8));
        $product = $this->product($tenant, 1000);
        app(CartService::class)->add($tenant, $product->id, 1);

        Livewire::test(Checkout::class, ['tenant' => $tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_phone', $bad)
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'mp_pix')
            ->call('payOnline')
            ->assertHasErrors(['customer_phone'])
            ->assertNotDispatched('checkout-mp-pay')
            ->assertSee(self::MESSAGE);

        $this->assertSame(0, Order::withoutTenantScope()->count());
        Http::assertNothingSent();
    }

    public function test_pay_online_with_an_empty_phone_does_not_create_an_order_yet(): void
    {
        Http::fake();
        $tenant = $this->mpTenant('loja-online-vazio');
        $product = $this->product($tenant, 1000);
        app(CartService::class)->add($tenant, $product->id, 1);

        Livewire::test(Checkout::class, ['tenant' => $tenant])
            ->set('customer_name', 'Maria')
            ->set('customer_phone', '')
            ->set('customer_email', 'maria@example.com')
            ->set('payment_method', 'mp_pix')
            ->call('payOnline')
            ->assertHasNoErrors()
            ->assertDispatched('checkout-mp-pay');

        $this->assertSame(0, Order::withoutTenantScope()->count());
        Http::assertNothingSent();
    }

    public function test_checkout_reopens_a_stored_phone_with_the_mask(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'loja-abre', 'is_active' => true]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'price_cents' => 1000,
            'name' => 'Bolo',
        ]);
        $auth = app(CustomerAuthService::class);
        $token = $auth->generateSessionToken();
        Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'phone' => '11999998888',
            'session_token' => $token,
            'is_active' => true,
        ]);

        $html = $this->withSession([
            CartService::sessionKey($tenant) => [['product_id' => $product->id, 'qty' => 1]],
        ])
            ->withCredentials()
            ->withCookie($auth->getCookieName($tenant), $token)
            ->get('/loja-abre/checkout')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="customerPhoneInput"', (string) $html);
        $this->assertSame('(11) 99999-8888', $this->componentState((string) $html, 'tenant.checkout')['customer_phone']);
    }

    public function test_profile_stays_required_and_stores_the_canonical_mask_on_reopen(): void
    {
        [$tenant, $customer, $auth, $token] = $this->customerSession();

        $html = $this->withCredentials()
            ->withCookie($auth->getCookieName($tenant), $token)
            ->get('/loja-perfil/conta/perfil')
            ->assertOk()
            ->getContent();

        $this->assertSame('(11) 99999-8888', $this->componentState((string) $html, 'tenant.account.profile')['phone']);

        $snapshot = $this->snapshotFor((string) $html, 'tenant.account.profile');

        $empty = $this->callLivewire($snapshot, 'save', [], [
            'name' => 'Outro',
            'email' => 'outro@example.com',
            'phone' => '',
        ]);
        $this->assertStringContainsString('phone', (string) $empty->json('components.0.effects.html'));
        $this->assertSame('Maria', $customer->fresh()->name);
        $this->assertSame('11999998888', $customer->fresh()->phone);

        $bad = $this->callLivewire($snapshot, 'save', [], [
            'name' => 'Outro',
            'email' => 'outro@example.com',
            'phone' => 'recepção',
        ]);
        $this->assertStringContainsString(self::MESSAGE, (string) $bad->json('components.0.effects.html'));
        $this->assertSame('11999998888', $customer->fresh()->phone);

        $ok = $this->callLivewire($snapshot, 'save', [], [
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'phone' => '(11) 98888-7777',
        ])->assertOk();

        $this->assertSame('11988887777', $customer->fresh()->phone);
        $this->assertSame('(11) 98888-7777', $this->unwrap($this->returnedData($ok))['phone']);
    }

    public function test_the_admin_customer_form_reopens_with_the_mask_and_stores_the_canonical(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');

        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'phone' => '11977776666',
        ]);

        $this->adminContext($tenant);

        Livewire::test(Form::class, ['tenant' => $tenant, 'customerId' => $customer->id])
            ->assertSet('phone', '(11) 97777-6666')
            ->set('phone', '(11) 97777-6666')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('11977776666', $customer->fresh()->phone);

        $this->adminContext($tenant);

        Livewire::test(Form::class, ['tenant' => $tenant])
            ->set('name', 'Sem')
            ->set('phone', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Customer::withoutTenantScope()->where('name', 'Sem')->firstOrFail()->phone);

        $this->adminContext($tenant);

        Livewire::test(Form::class, ['tenant' => $tenant, 'customerId' => $customer->id])
            ->set('name', 'Trocada')
            ->set('email', 'trocada@example.com')
            ->set('phone', '123')
            ->call('save')
            ->assertHasErrors(['phone'])
            ->assertSee(self::MESSAGE);

        $fresh = $customer->fresh();
        $this->assertSame('Ana', $fresh->name);
        $this->assertSame('ana@example.com', $fresh->email);
        $this->assertSame('11977776666', $fresh->phone);
    }

    #[DataProvider('rejectedPhones')]
    public function test_process_payment_with_an_invalid_phone_does_not_create_an_order(string $bad): void
    {
        Http::fake();
        $tenant = $this->mpTenant('loja-pag-'.substr(md5($bad), 0, 8));
        $product = $this->product($tenant, 1000);

        $this->withSession($this->checkoutSession($tenant, $product))
            ->postJson('/'.$tenant->slug.'/process_payment', $this->processPaymentBody([
                'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => $bad],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('errors.customer_phone.0', self::MESSAGE);

        $this->assertSame(0, Order::withoutTenantScope()->count());
        Http::assertNothingSent();
    }

    public function test_process_payment_with_an_empty_phone_creates_the_order(): void
    {
        $tenant = $this->mpTenant('loja-pag-vazio');
        $product = $this->product($tenant, 1000);
        $this->fakeMpOrderCreation('ORDPHONE1');

        $this->withSession($this->checkoutSession($tenant, $product))
            ->postJson('/'.$tenant->slug.'/process_payment', $this->processPaymentBody([
                'customer' => ['name' => 'Maria', 'email' => 'maria@example.com', 'phone' => ''],
            ]))
            ->assertOk();

        $this->assertSame(1, Order::withoutTenantScope()->count());
    }

    private function contactTenant(): Tenant
    {
        $tenant = Tenant::factory()->create([
            'is_active' => true,
            'settings' => [
                'contact' => [
                    'phone' => '1133334444',
                    'whatsapp' => '5511999990000',
                    'email' => 'antes@example.com',
                    'whatsapp_message' => 'Oi',
                    'show_whatsapp_button' => true,
                ],
                'payment' => ['marker' => 'fica'],
                'whatsapp' => [
                    'status' => 'connected',
                    'number' => '11888887777',
                    'order_updates' => false,
                ],
            ],
        ]);

        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
        $this->adminContext($tenant);

        return $tenant;
    }

    private function adminContext(Tenant $tenant): void
    {
        app(TenantContext::class)->set($tenant);
        view()->share('currentTenant', $tenant);

        $admin = auth('tenant_admin')->user();

        if ($admin !== null) {
            view()->share('tenantAdmin', $admin);
        }
    }

    private function tenantWithWhatsapp(?string $number, string $message = ''): Tenant
    {
        $tenant = new Tenant;
        $tenant->settings = [
            'contact' => [
                'whatsapp' => $number,
                'whatsapp_message' => $message,
            ],
        ];

        return $tenant;
    }

    private function manualTenant(): Tenant
    {
        $tenant = Tenant::factory()->create([
            'is_active' => true,
            'settings' => [
                'payment' => [
                    'methods' => [
                        ['id' => 'cash_on_delivery', 'provider' => 'manual', 'label' => 'Dinheiro', 'enabled' => true],
                    ],
                ],
            ],
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'price_cents' => 1000,
            'name' => 'Bolo',
        ]);
        app(CartService::class)->add($tenant, $product->id, 1);

        return $tenant;
    }

    /**
     * @return array{0: Tenant, 1: Customer, 2: CustomerAuthService, 3: string}
     */
    private function customerSession(): array
    {
        $tenant = Tenant::factory()->create(['slug' => 'loja-perfil', 'is_active' => true]);
        $auth = app(CustomerAuthService::class);
        $token = $auth->generateSessionToken();
        $customer = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'phone' => '11999998888',
            'session_token' => $token,
            'is_active' => true,
        ]);

        return [$tenant, $customer, $auth, $token];
    }

    /** @return array<string, mixed> */
    private function componentState(string $html, string $name): array
    {
        $snapshot = json_decode($this->snapshotFor($html, $name), true);
        $data = is_array($snapshot) ? ($snapshot['data'] ?? []) : [];
        $state = $this->unwrap(is_array($data) ? $data : []);

        return is_array($state) ? $state : [];
    }
}
