<?php

namespace Tests\Feature\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marca da plataforma (quitanda.app) e títulos de aba do storefront.
 *
 * O layout emitia DOIS <title>: o do próprio layout (config('app.name'), que
 * vinha do .env como "Laravel") e o do partial de SEO. O navegador usa o
 * primeiro, então a aba mostrava "Laravel".
 */
class BrandingAndTitlesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->tenant = Tenant::factory()->create(['slug' => 'carol', 'name' => 'Carol Doces', 'is_active' => true]);
    }

    private function titles(string $html): array
    {
        preg_match_all('#<title>(.*?)</title>#s', $html, $m);

        return array_map(fn ($t) => html_entity_decode(trim($t)), $m[1]);
    }

    public function test_each_storefront_page_has_exactly_one_title(): void
    {
        foreach (['/carol', '/carol/carrinho', '/carol/checkout'] as $url) {
            $this->assertCount(1, $this->titles($this->get($url)->getContent()), "{$url} deve ter um único <title>");
        }
    }

    public function test_checkout_title_is_page_dash_tenant(): void
    {
        $this->assertSame(['Checkout - Carol Doces'], $this->titles($this->get('/carol/checkout')->getContent()));
    }

    public function test_cart_title_is_page_dash_tenant(): void
    {
        $this->assertSame(['Carrinho - Carol Doces'], $this->titles($this->get('/carol/carrinho')->getContent()));
    }

    public function test_product_and_category_titles_use_their_own_names(): void
    {
        $product = Product::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bolo de Cenoura', 'slug' => 'bolo-de-cenoura', 'is_active' => true]);
        $category = Category::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Bolos', 'slug' => 'bolos', 'is_active' => true]);

        $this->assertSame(['Bolo de Cenoura - Carol Doces'], $this->titles($this->get('/carol/p/'.$product->slug)->getContent()));
        $this->assertSame(['Bolos - Carol Doces'], $this->titles($this->get('/carol/categoria/'.$category->slug)->getContent()));
    }

    public function test_home_keeps_the_tenant_seo_title_and_never_says_laravel(): void
    {
        $titles = $this->titles($this->get('/carol')->getContent());

        $this->assertSame(['Carol Doces'], $titles);
        $this->assertStringNotContainsString('Laravel', implode(' ', $titles));
    }

    public function test_title_is_escaped(): void
    {
        $this->tenant->update(['name' => 'Doces <script>alert(1)</script>']);

        $html = $this->get('/carol/checkout')->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_the_platform_name_is_quitanda_app(): void
    {
        $this->assertSame('quitanda.app', config('app.name'));
        $this->assertSame('https://quitanda.app', config('app.platform_url'));
    }

    public function test_footer_ends_with_the_made_with_love_line_linking_to_the_platform(): void
    {
        $html = $this->get('/carol')->getContent();

        $this->assertMatchesRegularExpression(
            '#Feito com ♡ e\s*<a href="https://quitanda\.app"[^>]*>quitanda\.app</a>#u',
            $html
        );

        // É a última linha do rodapé, depois do copyright.
        $this->assertGreaterThan(
            strpos($html, 'Todos os direitos reservados'),
            strpos($html, 'Feito com ♡ e')
        );
    }

    public function test_footer_link_is_safe_for_external_navigation(): void
    {
        $html = $this->get('/carol')->getContent();

        $this->assertMatchesRegularExpression('#<a href="https://quitanda\.app"[^>]*rel="[^"]*noopener[^"]*"#', $html);
    }

    public function test_landing_uses_the_platform_name(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertSame(['quitanda.app — Plataforma para lojas de encomendas'], $this->titles($html));
        $this->assertStringNotContainsString('Order Web', $html);
    }
}
