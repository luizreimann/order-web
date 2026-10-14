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
 * E5.1 (UI-007) — o tema do tenant NUNCA pode fechar a regra CSS.
 *
 * `{{ }}` escapa HTML, não contexto CSS. Os valores maliciosos aqui são gravados
 * DIRETO no banco (bypass da validação de `Customization`), provando que a VIEW não
 * confia na escrita: só valores da allow-list (ou o fallback) chegam ao CSS.
 */
class TenantThemeCssInjectionTest extends TestCase
{
    use InteractsWithLivewireEndpoint, RefreshDatabase;

    private const DEFAULT_FONT = 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

    private const INTER = 'Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif';

    /** @return array<string, array{string}> */
    public static function maliciousFonts(): array
    {
        return [
            'fecha a regra e injeta url()' => ['"a"; } body { background: url(https://evil.test/?x) } /*'],
            'fecha o <style>' => ['Inter</style><script>alert(1)</script>'],
            'expression()' => ['Inter, expression(alert(1))'],
            'primeiro token válido, resto injetado' => ['Inter; } body { display:none } /*'],
            'primeiro token conhecido, cauda arbitrária' => ['Inter, evil-font'],
            'quebra de linha + @import' => ["Inter;\n}\n@import url(https://evil.test/x.css);\n:root{"],
        ];
    }

    /** @return array<string, array{string}> */
    public static function maliciousColors(): array
    {
        return [
            'fecha a regra' => ['red; } body { display:none } /*'],
            'hex curto demais' => ['#12'],
            'hex inválido' => ['#ggg'],
            'url()' => ['url(https://evil.test/x)'],
            'hex + injeção' => ['#fff;} body{background:url(https://evil.test/)}'],
            'fecha o <style>' => ['#fff</style><script>alert(1)</script>'],
            'nome de cor' => ['red'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // não depende do manifest do Vite (build de assets não é o objeto deste teste)
        $this->withoutVite();
    }

    private function tenant(array $attributes = []): Tenant
    {
        return Tenant::factory()->create(array_merge(['slug' => 'tenant-a'], $attributes));
    }

    private function actAsAdmin(Tenant $tenant): void
    {
        $this->actingAs(User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => Role::TenantAdmin,
        ]), 'tenant_admin');
    }

    /** @return array<string, string> HTML por página que inclui `partials.tenant-theme` */
    private function themedPages(Tenant $tenant): array
    {
        $this->actAsAdmin($tenant);

        return [
            'layouts/app (loja)' => (string) $this->get('/tenant-a')->assertOk()->getContent(),
            'layouts/tenant-admin (personalização)' => (string) $this->get('/tenant-a/admin/personalizacao')->assertOk()->getContent(),
        ];
    }

    private function assertInertCss(string $html, string $where): void
    {
        $this->assertStringNotContainsString('evil', $html, "[{$where}] valor injetado vazou");
        $this->assertStringNotContainsString('expression(', $html, "[{$where}] expression() vazou");
        $this->assertStringNotContainsString('alert(1)', $html, "[{$where}] script injetado vazou");
        $this->assertSame(
            substr_count($html, '<style'),
            substr_count($html, '</style>'),
            "[{$where}] um valor fechou o <style> de forma anômala"
        );
    }

    private function declaration(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/'.preg_quote($name, '/').':\s*([^\n]*)/', $html, $m), "Declaração {$name} ausente");

        return trim($m[1]);
    }

    #[DataProvider('maliciousFonts')]
    public function test_malicious_font_family_in_database_never_reaches_css(string $payload): void
    {
        $tenant = $this->tenant(['settings' => ['theme' => ['font_family' => $payload]]]);

        foreach ($this->themedPages($tenant) as $where => $html) {
            $this->assertInertCss($html, $where);
            $this->assertSame(self::DEFAULT_FONT.';', $this->declaration($html, '--tenant-font-family'), $where);
            // nome de fonte da URL do Google Fonts também vem da allow-list: valor cru não carrega fonte
            $this->assertStringNotContainsString('fonts.googleapis.com/css2', $html, $where);
        }

        $login = (string) $this->get('/tenant-a/admin/login')->assertOk()->getContent();
        $this->assertInertCss($login, 'admin/auth/login');
        $this->assertSame(self::DEFAULT_FONT.';', $this->declaration($login, '--tenant-font-family'));
    }

    #[DataProvider('maliciousColors')]
    public function test_malformed_colors_in_database_fall_back_to_defaults(string $payload): void
    {
        $tenant = $this->tenant(['primary_color' => $payload, 'secondary_color' => $payload]);

        foreach ($this->themedPages($tenant) as $where => $html) {
            $this->assertInertCss($html, $where);
            $this->assertSame('#0d6efd;', $this->declaration($html, '--tenant-primary'), $where);
            $this->assertSame('#6c757d;', $this->declaration($html, '--tenant-secondary'), $where);
        }
    }

    public function test_allow_listed_values_are_rendered_and_google_font_loaded_from_allow_list(): void
    {
        $tenant = $this->tenant([
            'primary_color' => '#ABC',
            'secondary_color' => '#1e40af',
            'settings' => ['theme' => ['font_family' => self::INTER]],
        ]);

        foreach ($this->themedPages($tenant) as $where => $html) {
            $this->assertSame('#ABC;', $this->declaration($html, '--tenant-primary'), $where);
            $this->assertSame('#1e40af;', $this->declaration($html, '--tenant-secondary'), $where);
            $this->assertSame(self::INTER.';', $this->declaration($html, '--tenant-font-family'), $where);
            $this->assertStringContainsString(
                'href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&amp;display=swap"',
                $html,
                $where
            );
        }
    }

    public function test_default_theme_when_nothing_is_stored(): void
    {
        $tenant = $this->tenant(['primary_color' => null, 'secondary_color' => null]);

        foreach ($this->themedPages($tenant) as $where => $html) {
            $this->assertSame('#0d6efd;', $this->declaration($html, '--tenant-primary'), $where);
            $this->assertSame(self::DEFAULT_FONT.';', $this->declaration($html, '--tenant-font-family'), $where);
        }
    }

    /**
     * O preview da personalização interpola props públicas do Livewire — controladas
     * pelo cliente no `wire:model`. Exercita o endpoint real com valores adulterados.
     */
    public function test_customization_preview_neutralises_tampered_livewire_state(): void
    {
        $tenant = $this->tenant();
        $this->actAsAdmin($tenant);

        $page = (string) $this->get('/tenant-a/admin/personalizacao')->assertOk()->getContent();
        $snapshot = $this->snapshotFor($page, 'admin.tenant.customization');

        // sem `calls`: é o que o cliente envia ao sincronizar `wire:model` num re-render
        $response = $this->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [
                    'font_family' => '"a"; } body { background: url(https://evil.test/?x) } /*',
                    'primary_color' => 'red; } body{display:none} /*',
                    'secondary_color' => 'url(https://evil.test/)',
                ],
                'calls' => [],
            ]],
        ])->assertOk();

        $html = (string) $response->json('components.0.effects.html');
        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringContainsString('font-family: '.self::DEFAULT_FONT.';', $html);
        $this->assertStringContainsString('--tenant-primary: #0d6efd;', $html);
        $this->assertStringContainsString('--tenant-secondary: #6c757d;', $html);
    }

    /**
     * Escrita: a validação anterior era só `string|max:255` (o review dizia "opções
     * conhecidas", mas não era verdade). Agora é `Rule::in` da mesma allow-list.
     */
    public function test_saving_a_font_outside_the_allow_list_is_rejected_and_not_persisted(): void
    {
        $tenant = $this->tenant();
        $this->actAsAdmin($tenant);

        $snapshot = $this->snapshotFor(
            (string) $this->get('/tenant-a/admin/personalizacao')->assertOk()->getContent(),
            'admin.tenant.customization'
        );

        $payload = '"a"; } body { background: url(https://evil.test/?x) } /*';
        $response = $this->callLivewire($snapshot, 'save', [], ['font_family' => $payload])->assertOk();

        $this->assertStringContainsString('font_family', (string) $response->getContent(), 'erro de validação esperado');
        $this->assertNull(data_get($tenant->fresh()->settings, 'theme.font_family'));
    }

    public function test_font_validation_and_theme_loader_share_one_allow_list(): void
    {
        $this->assertTrue(class_exists(\App\Support\TenantTheme::class));

        foreach (\App\Support\TenantTheme::fontStacks() as $stack) {
            $this->assertSame($stack, \App\Support\TenantTheme::fontStack($stack));
        }
        $this->assertSame(self::DEFAULT_FONT, \App\Support\TenantTheme::fontStack('Comic Sans'));
    }
}
