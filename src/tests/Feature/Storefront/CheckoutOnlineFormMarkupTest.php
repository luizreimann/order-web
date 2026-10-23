<?php

namespace Tests\Feature\Storefront;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * Marcação de erro do formulário da esquerda no checkout ONLINE (`payOnline`), no padrão de
 * `StorefrontFormValidationMarkupTest`: `is-invalid` + `aria-invalid` + `invalid-feedback`
 * irmão posterior do campo, pelo endpoint HTTP real (`POST /livewire/update`).
 *
 * O e-mail é obrigatório SÓ nos métodos online (o manual mantém o comportamento dele).
 */
class CheckoutOnlineFormMarkupTest extends TestCase
{
    use InteractsWithLivewireEndpoint, InteractsWithMercadoPago, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = $this->mpTenant('loja');
    }

    private function html(TestResponse $response): string
    {
        $response->assertOk();

        return (string) $response->json('components.0.effects.html');
    }

    /** @param array<string, mixed> $updates */
    private function payOnline(array $updates): string
    {
        $product = $this->product($this->tenant, 1000);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 1]]);

        $page = $this->get('/loja/checkout')->assertOk();

        return $this->html($this->callLivewire($this->snapshotFor($page->getContent(), 'tenant.checkout'), 'payOnline', [], $updates));
    }

    public function test_an_empty_email_marks_the_field_with_the_pix_message(): void
    {
        $html = $this->payOnline(['customer_name' => 'Maria', 'customer_phone' => '', 'customer_email' => '']);

        $control = $this->first($html, "//*[@id='customerEmailInput']");
        $this->assertContains('is-invalid', $this->classes($control));
        $this->assertSame('true', $control->getAttribute('aria-invalid'));

        $feedback = $this->first($html, "//*[@id='customerEmailInput']/following-sibling::div[contains(@class,'invalid-feedback')]");
        $this->assertSame('Informe um e-mail válido para receber o comprovante do Pix.', trim($feedback->textContent));

        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'), 'só o e-mail está inválido');
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
        $this->assertSame(1, $this->countNodes($html, "//label[@for='customerEmailInput']"));
    }

    public function test_an_invalid_email_is_marked_the_same_way(): void
    {
        $html = $this->payOnline(['customer_name' => 'Maria', 'customer_phone' => '', 'customer_email' => 'nao-e-email']);

        $this->assertContains('is-invalid', $this->classes($this->first($html, "//*[@id='customerEmailInput']")));
        $this->assertSame(1, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
    }

    public function test_every_invalid_field_of_the_left_form_is_marked_at_once(): void
    {
        $html = $this->payOnline(['customer_name' => '', 'customer_phone' => 'abc', 'customer_email' => 'x']);

        foreach (['customerNameInput', 'customerPhoneInput', 'customerEmailInput'] as $id) {
            $control = $this->first($html, "//*[@id='{$id}']");
            $this->assertContains('is-invalid', $this->classes($control), "#{$id} sem is-invalid");
            $this->assertSame('true', $control->getAttribute('aria-invalid'));
            $this->first($html, "//*[@id='{$id}']/following-sibling::div[contains(@class,'invalid-feedback')]");
        }
    }

    public function test_a_valid_form_shows_no_error_at_all(): void
    {
        $html = $this->payOnline(['customer_name' => 'Maria', 'customer_phone' => '(11) 99999-9999', 'customer_email' => 'maria@example.com']);

        $this->assertSame(0, $this->countNodes($html, '//*[contains(@class,"is-invalid")]'));
        $this->assertSame(0, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }
}
