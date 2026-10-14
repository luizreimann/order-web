<?php

namespace Tests\Feature\Security;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Tenancy\Support\InteractsWithLivewireEndpoint;
use Tests\TestCase;

/**
 * E5.2 (UI-008) — `google_analytics_id` entra em `src` de <script> e em JS inline.
 * Validado na ESCRITA (SeoSettings) e na LEITURA (renderização não emite nada se inválido).
 */
class GoogleAnalyticsIdTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private const COMPONENT = 'admin.tenant.seo-settings';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function invalidIds(): array
    {
        return [
            'fecha o script e injeta' => ["G-ABCD1234');alert(document.cookie);//"],
            'tag script' => ['G-ABC</script><script>alert(1)</script>'],
            'aspas' => ['G-ABCD"onerror="alert(1)'],
            'querystring extra no src' => ['G-ABCD&callback=evil'],
            'minúsculas' => ['g-abcd1234'],
            'sem prefixo' => ['ABCD1234'],
            'curto demais' => ['G-AB'],
            'longo demais' => ['G-'.str_repeat('A', 21)],
            'quebra de linha final' => ["G-ABCD1234\n"],
            'UA legado (propriedade descontinuada)' => ['UA-12345678-1'],
        ];
    }

    private function tenantWith(?string $gaId): Tenant
    {
        return Tenant::factory()->create([
            'slug' => 'tenant-a',
            'settings' => ['seo' => ['google_analytics_id' => $gaId]],
        ]);
    }

    private function storefront(): string
    {
        return (string) $this->get('/tenant-a')->assertOk()->getContent();
    }

    private function actAsAdmin(Tenant $tenant): void
    {
        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_id_stored_in_database_emits_no_gtag_script(string $payload): void
    {
        $this->tenantWith($payload);

        $html = $this->storefront();

        $this->assertStringNotContainsString('googletagmanager.com', $html);
        $this->assertStringNotContainsString("gtag('config'", $html);
        $this->assertStringNotContainsString('gtag(', $html);
        $this->assertStringNotContainsString('dataLayer', $html);
        $this->assertStringNotContainsString('alert(', $html);
        $this->assertStringNotContainsString('callback=evil', $html);
    }

    public function test_no_id_emits_nothing(): void
    {
        $this->tenantWith(null);

        $this->assertStringNotContainsString('googletagmanager.com', $this->storefront());
    }

    public function test_valid_id_is_emitted_in_src_and_as_escaped_js_literal(): void
    {
        $this->tenantWith('G-AB12CD34EF');

        $html = $this->storefront();

        $this->assertStringContainsString('src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"', $html);
        // no JS, literal gerado por Js::from (escapa aspas/barras/<>), não interpolação crua
        $this->assertStringContainsString("gtag('config', 'G-AB12CD34EF');", $html);
        $this->assertSame(1, substr_count($html, "gtag('config'"));
    }

    // ------------------------------------------------------------------ escrita

    private function seoSnapshot(Tenant $tenant): string
    {
        $this->actAsAdmin($tenant);

        return $this->snapshotFor((string) $this->get('/tenant-a/admin/seo')->assertOk()->getContent(), self::COMPONENT);
    }

    #[DataProvider('invalidIds')]
    public function test_saving_an_invalid_id_is_rejected_and_not_persisted(string $payload): void
    {
        $tenant = $this->tenantWith(null);
        $snapshot = $this->seoSnapshot($tenant);

        $response = $this->callLivewire($snapshot, 'save', [], ['google_analytics_id' => $payload])->assertOk();

        $this->assertStringContainsString('google_analytics_id', (string) $response->getContent(), 'erro de validação esperado');
        $this->assertNull(data_get($tenant->fresh()->settings, 'seo.google_analytics_id'));
    }

    public function test_saving_a_valid_id_persists_and_empty_clears_it(): void
    {
        $tenant = $this->tenantWith(null);
        $snapshot = $this->seoSnapshot($tenant);

        $response = $this->callLivewire($snapshot, 'save', [], ['google_analytics_id' => 'G-ABCD1234'])->assertOk();
        $this->assertSame('G-ABCD1234', data_get($tenant->fresh()->settings, 'seo.google_analytics_id'));

        $this->callLivewire((string) $response->json('components.0.snapshot'), 'save', [], ['google_analytics_id' => ''])->assertOk();
        $this->assertNull(data_get($tenant->fresh()->settings, 'seo.google_analytics_id'));
    }
}
