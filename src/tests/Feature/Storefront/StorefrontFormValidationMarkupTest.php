<?php

namespace Tests\Feature\Storefront;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\CustomerAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E4.2 (storefront) — `is-invalid` + `invalid-feedback` nos formulários públicos do tenant:
 * perfil do cliente, checkout e popup de login.
 *
 * Tudo pelo endpoint HTTP real (`POST /livewire/update`). O checkout mostrava o erro como
 * `text-danger small` solto, sem marcar o campo; o perfil e o popup já tinham `is-invalid`,
 * mas sem `aria-invalid` e sem `for` no label do checkout.
 *
 * Os ids do checkout (`customerNameInput` etc.) são lidos pelo JS do Mercado Pago
 * (`getElementById`); por isso o teste os usa como âncora.
 */
class StorefrontFormValidationMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = Tenant::factory()->create(['slug' => 'loja', 'is_active' => true, 'settings' => [
            'payment' => ['methods' => [['id' => 'cash_on_delivery', 'enabled' => true, 'label' => 'Dinheiro']]],
        ]]);
    }

    private function loginAsCustomer(): void
    {
        $auth = app(CustomerAuthService::class);
        $token = $auth->generateSessionToken();
        Customer::factory()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Maria', 'email' => 'maria@example.com',
            'phone' => '11999998888', 'session_token' => $token,
        ]);

        $this->withCredentials()->withCookie($auth->getCookieName($this->tenant), $token);
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @param array<string, mixed> $updates */
    private function saveProfile(array $updates): string
    {
        $this->loginAsCustomer();
        $page = $this->get('/loja/conta/perfil')->assertOk();

        return $this->html($this->callLivewire($this->snapshotFor($page->getContent(), 'tenant.account.profile'), 'save', [], $updates));
    }

    /** @param array<string, mixed> $updates */
    private function placeOrder(array $updates): string
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'price_cents' => 1000]);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 1]]);

        $page = $this->get('/loja/checkout')->assertOk();

        return $this->html($this->callLivewire($this->snapshotFor($page->getContent(), 'tenant.checkout'), 'placeOrderManual', [], $updates));
    }

    private function assertMarked(string $html, string $id): void
    {
        $control = $this->first($html, "//*[@id='{$id}']");
        $this->assertContains('is-invalid', $this->classes($control), "#{$id} sem is-invalid");
        $this->assertSame('true', $control->getAttribute('aria-invalid'), "#{$id} sem aria-invalid");

        // O CSS do Bootstrap só exibe o feedback se for irmão POSTERIOR do `.is-invalid`.
        $feedback = $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertNotSame('', trim($feedback->textContent), "#{$id} sem mensagem");
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function profileInputs(): array
    {
        return [
            'nome vazio' => [['name' => ''], 'name'],
            'e-mail inválido' => [['email' => 'nao-e-email'], 'email'],
            'telefone inválido' => [['phone' => 'abc'], 'phone'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('profileInputs')]
    public function test_profile_marks_the_invalid_field_and_only_it(array $updates, string $id): void
    {
        $html = $this->saveProfile($updates);

        $this->assertMarked($html, $id);
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame($id, $this->first($html, "//label[@for='{$id}']")->getAttribute('for'));
    }

    public function test_profile_has_no_duplicated_ids(): void
    {
        $this->loginAsCustomer();
        $html = $this->get('/loja/conta/perfil')->assertOk()->getContent();

        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $dups = array_keys(array_filter(array_count_values($m[1]), fn (int $n) => $n > 1));

        $this->assertSame([], $dups, 'ids duplicados no perfil: '.implode(', ', $dups));
        $this->assertSame(1, preg_match_all('/\sid="name"/', $html), 'id="name" gerado pelo x-form-field deve aparecer 1 vez');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function checkoutInputs(): array
    {
        $valid = ['customer_name' => 'Maria', 'customer_phone' => '(11) 99999-9999', 'customer_email' => 'maria@example.com'];

        return [
            'nome vazio' => [array_merge($valid, ['customer_name' => '']), 'customerNameInput'],
            'telefone inválido' => [array_merge($valid, ['customer_phone' => 'abc']), 'customerPhoneInput'],
            'e-mail inválido' => [array_merge($valid, ['customer_email' => 'nao-e-email']), 'customerEmailInput'],
        ];
    }

    /** @param array<string, mixed> $updates */
    #[DataProvider('checkoutInputs')]
    public function test_checkout_marks_the_invalid_field_and_only_it(array $updates, string $id): void
    {
        $html = $this->placeOrder($updates);

        $this->assertMarked($html, $id);
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame(1, $this->countNodes($html, "//label[@for='{$id}']"));
    }

    public function test_checkout_marks_every_invalid_field_at_once_and_drops_the_loose_text_danger(): void
    {
        $html = $this->placeOrder(['customer_name' => '', 'customer_phone' => 'abc', 'customer_email' => 'x']);

        foreach (['customerNameInput', 'customerPhoneInput', 'customerEmailInput'] as $id) {
            $this->assertMarked($html, $id);
        }

        // Antes: `<div class="text-danger small mt-1">` solto, sem marcar o campo.
        $this->assertSame(0, $this->countNodes($html, '//div[contains(@class,"text-danger")]'));
    }

    public function test_login_popup_marks_the_phone_field(): void
    {
        $page = $this->get('/loja')->assertOk();
        $html = $this->html($this->callLivewire($this->snapshotFor($page->getContent(), 'tenant.auth.login-popup'), 'login', [], ['phone' => 'abc']));

        $this->assertMarked($html, 'phone');
        $this->assertSame(1, $this->countNodes($html, "//label[@for='phone']"));
    }
}
