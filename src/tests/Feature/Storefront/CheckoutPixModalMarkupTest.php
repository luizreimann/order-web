<?php

namespace Tests\Feature\Storefront;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\Feature\Payments\Support\InteractsWithMercadoPago;
use Tests\TestCase;

/**
 * O resultado do Pix é um MODAL Bootstrap estático no HTML do checkout (o JS só o preenche):
 * contrato de marcação, acessibilidade e o que NÃO pode existir (style inline, "30 minutos").
 *
 * O que só um navegador prova (foco preso/devolvido, fundo preto semitransparente, tema do tenant,
 * celular) NÃO é provado aqui: está no relatório e no E2E local.
 */
class CheckoutPixModalMarkupTest extends TestCase
{
    use InteractsWithMercadoPago, RefreshDatabase, RendersComponents;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->tenant = $this->mpTenant('loja');
    }

    private function checkoutHtml(): string
    {
        $product = $this->product($this->tenant, 1000);
        session()->put('cart.'.$this->tenant->id, [['product_id' => $product->id, 'qty' => 1]]);

        return $this->get('/loja/checkout')->assertOk()->getContent();
    }

    public function test_the_modal_exists_in_the_dom_with_the_required_attributes(): void
    {
        $html = $this->checkoutHtml();

        $this->assertSame(1, preg_match_all('/\sid="pixModal"/', $html));

        $modal = $this->first($html, "//div[@id='pixModal']");
        $this->assertSame(['modal', 'fade'], array_values(array_intersect($this->classes($modal), ['modal', 'fade'])));
        $this->assertSame('dialog', $modal->getAttribute('role'));
        $this->assertSame('true', $modal->getAttribute('aria-modal'));
        $this->assertSame('pixModalTitle', $modal->getAttribute('aria-labelledby'));
        $this->assertSame('-1', $modal->getAttribute('tabindex'));
        // Um clique fora ou o ESC não podem descartar o QR.
        $this->assertSame('static', $modal->getAttribute('data-bs-backdrop'));
        $this->assertSame('false', $modal->getAttribute('data-bs-keyboard'));

        $dialog = $this->first($html, "//div[@id='pixModal']/div[contains(@class,'modal-dialog')]");
        foreach (['modal-dialog-centered', 'modal-dialog-scrollable'] as $class) {
            $this->assertContains($class, $this->classes($dialog));
        }

        // O título rotulado existe e é um cabeçalho.
        $title = $this->first($html, "//div[@id='pixModal']//h2[@id='pixModalTitle']");
        $this->assertContains('modal-title', $this->classes($title));
        $this->assertNotSame('', trim($title->textContent));
    }

    public function test_it_has_an_explicit_close_button_that_only_dismisses(): void
    {
        $html = $this->checkoutHtml();

        $close = $this->first($html, "//div[@id='pixModal']//div[contains(@class,'modal-header')]/button[@id='pixModalClose']");
        $this->assertSame('button', $close->getAttribute('type'));
        $this->assertContains('btn-close', $this->classes($close));
        $this->assertSame('modal', $close->getAttribute('data-bs-dismiss'));
        $this->assertSame('Fechar', $close->getAttribute('aria-label'));

        // Nada no markup cancela pedido: o único botão estático do modal é o de fechar.
        $this->assertSame(1, $this->countNodes($html, "//div[@id='pixModal']//button"));
    }

    public function test_it_has_the_body_the_footer_and_a_polite_live_region_for_the_script(): void
    {
        $html = $this->checkoutHtml();

        $this->assertSame(1, $this->countNodes($html, "//div[@id='pixModal']//div[@id='pixModalBody' and contains(@class,'modal-body')]"));
        $this->assertSame(1, $this->countNodes($html, "//div[@id='pixModal']//div[@id='pixModalFooter' and contains(@class,'modal-footer')]"));

        $live = $this->first($html, "//div[@id='pixModal']//*[@id='pixModalAnnounce']");
        $this->assertSame('status', $live->getAttribute('role'));
        $this->assertSame('polite', $live->getAttribute('aria-live'));
        $this->assertContains('visually-hidden', $this->classes($live));
    }

    public function test_livewire_never_morphs_the_modal_away(): void
    {
        $html = $this->checkoutHtml();

        $this->assertSame(1, $this->countNodes($html, "//div[@*[name()='wire:ignore']]/div[@id='pixModal']"));
        $this->assertSame(1, $this->countNodes($html, "//div[@id='pixNotice' and @*[name()='wire:ignore']]"));
    }

    public function test_the_compact_notice_is_hidden_by_default_and_keeps_the_timer_out_of_the_live_region(): void
    {
        $html = $this->checkoutHtml();

        $notice = $this->first($html, "//div[@id='pixNotice']");
        $this->assertContains('d-none', $this->classes($notice));

        // O texto é a região de status; o contador (que muda a cada segundo) NÃO está dentro dela.
        $text = $this->first($html, "//*[@id='pixNoticeText']");
        $this->assertSame('status', $text->getAttribute('role'));
        $this->assertSame('Pix aguardando pagamento', trim($text->textContent));
        $this->assertSame(0, $this->countNodes($html, "//*[@id='pixNoticeText']//*[@data-pix-timer]"));

        $timer = $this->first($html, "//*[@id='pixNotice']//*[@data-pix-timer]");
        $this->assertSame('timer', $timer->getAttribute('role'));
        $this->assertSame('off', $timer->getAttribute('aria-live'));
        $this->assertSame(1, $this->countNodes($html, "//div[@id='pixNotice']//div[@id='pixNoticeActions']"));
    }

    public function test_the_modal_and_the_notice_have_no_inline_style_and_no_fixed_colors(): void
    {
        $html = $this->checkoutHtml();

        foreach (['pixModal', 'pixNotice'] as $id) {
            $node = $this->first($html, "//div[@id='{$id}']");
            $fragment = $node->ownerDocument->saveHTML($node);

            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $fragment, "#{$id} tem style inline");
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/i', $fragment, "#{$id} fixa uma cor");
            $this->assertDoesNotMatchRegularExpression('/\b(bg|text)-(white|black|light|dark|primary)\b/', $fragment, "#{$id} usa classe de cor fixa");
        }
    }

    public function test_the_page_never_mentions_the_thirty_minute_mercado_pago_deadline(): void
    {
        $html = $this->checkoutHtml();
        $source = (string) file_get_contents(resource_path('views/livewire/tenant/checkout.blade.php'));

        foreach ([$html, $source] as $text) {
            $this->assertDoesNotMatchRegularExpression('/30\s*minutos/iu', $text);
            $this->assertStringNotContainsString('PIX_EXPIRATION_SECONDS', $text);
        }

        // A janela do cliente (5 min) é a que vai para o script.
        $this->assertStringContainsString('const PIX_WINDOW_SECONDS = 300;', $html);
    }

    public function test_a_store_without_online_payment_gets_no_modal(): void
    {
        $manual = Tenant::factory()->create(['slug' => 'so-manual', 'is_active' => true, 'settings' => [
            'payment' => ['methods' => [['id' => 'cash_on_delivery', 'enabled' => true, 'label' => 'Dinheiro']]],
        ]]);
        $product = $this->product($manual, 1000);
        session()->put('cart.'.$manual->id, [['product_id' => $product->id, 'qty' => 1]]);

        $html = $this->get('/so-manual/checkout')->assertOk()->getContent();

        $this->assertStringNotContainsString('id="pixModal"', $html);
        $this->assertStringNotContainsString('id="pixNotice"', $html);
    }

    public function test_the_card_result_panel_is_still_there_for_everything_that_is_not_pix(): void
    {
        $html = $this->checkoutHtml();

        $this->assertSame(1, $this->countNodes($html, "//div[@id='mpResult' and @*[name()='wire:ignore']]//div[@id='mpResultBody']"));
    }

    public function test_the_script_receives_the_modal_class_from_the_storefront_bundle_and_never_a_global_bootstrap(): void
    {
        $source = (string) file_get_contents(resource_path('views/livewire/tenant/checkout.blade.php'));
        $app = (string) file_get_contents(resource_path('js/app.js'));

        // `bootstrap.bundle` como módulo não cria `window.bootstrap`: a classe vem de `window.StorefrontUI`.
        $this->assertStringContainsString('window.StorefrontUI = { Modal: bootstrap.Modal };', $app);
        $this->assertStringContainsString("import bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js';", $app);
        $this->assertStringContainsString('window.StorefrontUI', $source);
        $this->assertDoesNotMatchRegularExpression('/window\.bootstrap\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/\bnew\s+bootstrap\./', $source);
    }
}
