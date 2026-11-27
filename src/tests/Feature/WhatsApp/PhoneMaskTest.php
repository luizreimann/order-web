<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Livewire\Admin\Customers\Form;
use App\Livewire\Admin\Customers\Index as CustomersIndex;
use App\Livewire\Admin\Orders\Index as OrdersIndex;
use App\Livewire\Admin\Orders\Show;
use App\Livewire\Admin\Tenant\ContactSettings;
use App\Livewire\Admin\Tenant\PaymentSettings;
use App\Livewire\Admin\Tenant\WhatsAppSettings;
use App\Livewire\Tenant\Auth\LoginPopup;
use App\Livewire\Tenant\Checkout;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CartService;
use App\Services\CustomerAuthService;
use App\Services\CustomerLoginCodeService;
use App\Services\ZaptriaClient;
use App\Support\TenantContext;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A máscara é o atributo no HTML. Quem digita é a prova no navegador.
 */
class PhoneMaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'services.zaptria.base_url' => 'https://app.zaptria.com.br/api/v1/partner',
            'services.zaptria.key' => 'zpt_live_test_key',
            'services.zaptria.secret' => 'zpt_whsec_test_signing_secret',
            'services.zaptria.owner_email' => '',
        ]);
        $this->app->forgetInstance(ZaptriaClient::class);
        $this->app->forgetInstance(CustomerLoginCodeService::class);
        Mail::fake();
    }

    public function test_the_login_popup_phone_is_masked_and_the_code_is_not(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'loja-popup', 'is_active' => true]);

        $phone = Livewire::test(LoginPopup::class, ['tenant' => $tenant])
            ->assertSet('show', true)
            ->assertSet('awaitingCode', false)
            ->html();

        $this->assertSame(1, $this->maskedControls($phone));
        $this->assertInputHasMask($phone, 'wire:model="phone"');
        $this->assertStringNotContainsString('name="code"', $phone);

        Http::fake(['*' => Http::response(['id' => '01jd'], 201)]);

        $code = Livewire::test(LoginPopup::class, ['tenant' => $tenant])
            ->set('phone', '(11) 99999-8888')
            ->call('login')
            ->assertSet('awaitingCode', true)
            ->html();

        $this->assertSame(0, $this->maskedControls($code));
        $this->assertInputLacksMask($code, 'name="code"');
    }

    public function test_checkout_keeps_customer_phone_input_and_masks_it(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'loja-checkout', 'is_active' => true]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'price_cents' => 1000,
            'name' => 'Bolo',
        ]);
        app(CartService::class)->add($tenant, $product->id, 1);

        $html = Livewire::test(Checkout::class, ['tenant' => $tenant])->html();

        $this->assertSame(1, $this->maskedControls($html));
        $this->assertMatchesRegularExpression(
            '/<input\b(?=[^>]*\bid="customerPhoneInput")(?=[^>]*\bdata-phone-mask\b)(?=[^>]*wire:model\.defer="customer_phone")[^>]*>/',
            $html,
        );
    }

    public function test_the_profile_phone_is_masked(): void
    {
        [$tenant, $auth, $token] = $this->customerSession('loja-perfil');

        $html = $this->withCredentials()
            ->withCookie($auth->getCookieName($tenant), $token)
            ->get('/loja-perfil/conta/perfil')
            ->assertOk()
            ->getContent();

        $this->assertInputHasMask((string) $html, 'wire:model="phone"');
        $this->assertStringNotContainsString('data-phone-mask', $this->fragment((string) $html, '//footer'));
        $this->assertStringNotContainsString('data-phone-mask', $this->fragment((string) $html, '//*[@id="contactModal"]'));
    }

    public function test_the_admin_customer_form_phone_is_masked(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $this->admin($tenant);

        $html = Livewire::test(Form::class, ['tenant' => $tenant])->html();

        $this->assertSame(1, $this->maskedControls($html));
        $this->assertInputHasMask($html, 'wire:model.defer="phone"');
        $this->assertInputLacksMask($html, 'wire:model.defer="email"');
    }

    public function test_contact_phone_and_whatsapp_are_masked_and_the_message_is_not(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $this->admin($tenant);

        $html = Livewire::test(ContactSettings::class, ['tenant' => $tenant])->html();

        $this->assertSame(2, $this->maskedControls($html));
        $this->assertInputHasMask($html, 'wire:model="phone"');
        $this->assertInputHasMask($html, 'wire:model="whatsapp"');
        $this->assertInputLacksMask($html, 'wire:model="whatsapp_message"');
    }

    public function test_pairing_phone_is_masked_and_the_qr_and_templates_are_not(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'loja-zap',
            'is_active' => true,
            'settings' => [
                'whatsapp' => [
                    'status' => 'qr_ready',
                    'number' => null,
                    'order_updates' => false,
                    'templates' => [
                        'confirmed' => 'texto confirmado',
                        'done' => 'texto concluido',
                        'cancelled' => 'texto cancelado',
                    ],
                ],
            ],
        ]);
        $this->admin($tenant);
        Http::fake([
            'https://app.zaptria.com.br/api/v1/partner/tenants/'.$tenant->id.'/whatsapp' => Http::sequence()
                ->push([
                    'status' => 'qr_ready',
                    'number' => null,
                    'qr' => 'qr-mask',
                ], 200),
        ]);

        $html = Livewire::test(WhatsAppSettings::class, ['tenant' => $tenant])->html();

        $this->assertSame(1, $this->maskedControls($html));
        $this->assertInputHasMask($html, 'wire:model="pairing_phone"');
        $this->assertStringContainsString('data-whatsapp-qr="qr-mask"', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/<div\b(?=[^>]*data-whatsapp-qr)(?=[^>]*\bdata-phone-mask\b)[^>]*>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression('/<textarea\b[^>]*\bdata-phone-mask\b/', $html);
        $this->assertInputLacksMask($html, 'wire:model="confirmed"');
        $this->assertInputLacksMask($html, 'wire:model="done"');
        $this->assertInputLacksMask($html, 'wire:model="cancelled"');
    }

    public function test_search_pix_key_and_read_only_phones_are_not_masked(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'loja-leitura',
            'name' => 'Loja Leitura',
            'is_active' => true,
            'settings' => [
                'contact' => [
                    'phone' => '1133334444',
                    'whatsapp' => '11999990000',
                    'email' => 'loja@example.com',
                    'show_whatsapp_button' => false,
                ],
            ],
        ]);
        $this->admin($tenant);

        Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'phone' => '11977776666',
        ]);
        $order = Order::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::UNPAID,
            'customer_name' => 'Ana',
            'customer_phone' => '11966665555',
        ]);

        $orders = Livewire::test(OrdersIndex::class, ['tenant' => $tenant])->html();
        $this->assertSame(0, $this->maskedControls($orders));
        $this->assertInputLacksMask($orders, 'wire:model.live="search"');
        $this->assertStringContainsString('11966665555', $orders);

        $customers = Livewire::test(CustomersIndex::class, ['tenant' => $tenant])->html();
        $this->assertSame(0, $this->maskedControls($customers));
        $this->assertInputLacksMask($customers, 'wire:model.live="search"');
        $this->assertStringContainsString('11977776666', $customers);

        $show = Livewire::test(Show::class, ['tenant' => $tenant, 'orderId' => $order->id])->html();
        $this->assertSame(0, $this->maskedControls($show));
        $this->assertStringContainsString('11966665555', $show);
        $this->assertInputLacksMask($show, 'wire:model.defer="whatsapp_message"');

        $payments = Livewire::test(PaymentSettings::class, ['tenant' => $tenant])->html();
        $this->assertSame(0, $this->maskedControls($payments));
        $this->assertInputLacksMask($payments, 'pix_key');

        $home = (string) $this->get('/loja-leitura')->assertOk()->getContent();
        $footer = $this->fragment($home, '//footer');
        $modal = $this->fragment($home, '//*[@id="contactModal"]');
        $this->assertStringContainsString('(11) 3333-4444', $footer);
        $this->assertStringContainsString('(11) 99999-0000', $modal);
        $this->assertStringNotContainsString('data-phone-mask', $footer);
        $this->assertStringNotContainsString('data-phone-mask', $modal);

        [$accountTenant, $auth, $token] = $this->customerSession('loja-conta');
        $account = (string) $this->withCredentials()
            ->withCookie($auth->getCookieName($accountTenant), $token)
            ->get('/loja-conta/conta')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('11999998888', $account);
        $this->assertSame(0, $this->maskedControls($account));
    }

    private function admin(Tenant $tenant): void
    {
        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
        app(TenantContext::class)->set($tenant);
        view()->share('currentTenant', $tenant);

        $user = auth('tenant_admin')->user();

        if ($user !== null) {
            view()->share('tenantAdmin', $user);
        }
    }

    /**
     * @return array{0: Tenant, 1: CustomerAuthService, 2: string}
     */
    private function customerSession(string $slug): array
    {
        $tenant = Tenant::factory()->create(['slug' => $slug, 'is_active' => true]);
        app(TenantContext::class)->set($tenant);
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

        return [$tenant, $auth, $token];
    }

    private function maskedControls(string $html): int
    {
        preg_match_all('/<(?:input|textarea)\b[^>]*\bdata-phone-mask\b[^>]*>/', $html, $matches);

        return count($matches[0]);
    }

    private function assertInputHasMask(string $html, string $needle): void
    {
        $this->assertMatchesRegularExpression(
            '/<(?:input|textarea)\b(?=[^>]*'.preg_quote($needle, '/').')(?=[^>]*\bdata-phone-mask\b)[^>]*>/',
            $html,
            $needle,
        );
    }

    private function assertInputLacksMask(string $html, string $needle): void
    {
        $this->assertMatchesRegularExpression(
            '/<(?:input|textarea)\b(?=[^>]*'.preg_quote($needle, '/').')[^>]*>/',
            $html,
            'campo ausente: '.$needle,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<(?:input|textarea)\b(?=[^>]*'.preg_quote($needle, '/').')(?=[^>]*\bdata-phone-mask\b)[^>]*>/',
            $html,
            $needle,
        );
    }

    private function fragment(string $html, string $query): string
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $node = (new DOMXPath($doc))->query($query)->item(0);
        $this->assertInstanceOf(DOMElement::class, $node, $query);

        return $doc->saveHTML($node) ?: '';
    }
}
