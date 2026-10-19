<?php

namespace Tests\Unit\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guarda da stack de assets (E4.4): Bootstrap + Vite, sem CDN, sem `style=` solto.
 *
 * Varre o código-fonte (não precisa de app, banco nem do build do Vite).
 */
class AssetStackTest extends TestCase
{
    /**
     * Únicos scripts externos permitidos, por arquivo e host.
     * Razões técnicas: o SDK do gateway precisa ser servido pelo Mercado Pago e o
     * gtag.js pelo Google.
     */
    private const EXTERNAL_SCRIPT_ALLOWLIST = [
        'livewire/tenant/checkout.blade.php' => ['sdk.mercadopago.com'],
        'partials/tenant-meta.blade.php' => ['www.googletagmanager.com'],
    ];

    /**
     * `style=` inline remanescentes, por view (limite exato, não "até").
     * Todos dependem de valor dinâmico por requisição:
     * - checkout: `display` alternado pelo JS do SDK do Mercado Pago (inline vence classe);
     * - customization: preview ao vivo do tema, cores/fonte vindas do formulário.
     * Qualquer view fora desta lista deve ter ZERO `style=`.
     */
    private const INLINE_STYLE_ALLOWLIST = [
        'livewire/tenant/checkout.blade.php' => 2,
        'livewire/admin/tenant/customization.blade.php' => 1,
    ];

    private const INLINE_STYLE_TOTAL_MAX = 3;

    public function test_no_external_script_outside_the_allowlist(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $content) {
            // <script src="https://..."> ou <script src="//...">
            preg_match_all('#<script\b[^>]*\bsrc\s*=\s*["\']?(?:https?:)?//([^/"\'\s>]+)#i', $content, $tags);
            // injeção dinâmica: script.src = 'https://...'
            preg_match_all('#\.src\s*=\s*["\'](?:https?:)?//([^/"\']+)#i', $content, $dynamic);

            foreach (array_merge($tags[1], $dynamic[1]) as $host) {
                $allowed = self::EXTERNAL_SCRIPT_ALLOWLIST[$relative] ?? [];
                if (! in_array(strtolower($host), $allowed, true)) {
                    $offenders[] = "{$relative} -> {$host}";
                }
            }
        }

        $this->assertSame([], $offenders, "Scripts externos fora da allowlist (use o bundle do Vite):\n".implode("\n", $offenders));
    }

    public function test_no_cdn_or_icon_kit_reference_in_views(): void
    {
        $offenders = [];

        foreach ($this->views() as $relative => $content) {
            if (preg_match('#cdn\.|kit\.fontawesome\.com|cdnjs|unpkg\.com#i', $content, $m)) {
                $offenders[] = "{$relative} ({$m[0]})";
            }
        }

        $this->assertSame([], $offenders, "Referências a CDN nas views:\n".implode("\n", $offenders));
    }

    public function test_inline_style_attributes_stay_within_the_limit(): void
    {
        $counts = [];

        foreach ($this->views() as $relative => $content) {
            $n = preg_match_all('/\sstyle\s*=\s*["\']/i', $content);
            if ($n > 0) {
                $counts[$relative] = $n;
            }
        }

        $this->assertLessThanOrEqual(self::INLINE_STYLE_TOTAL_MAX, array_sum($counts), 'Total de style= inline acima do limite.');

        foreach ($counts as $relative => $n) {
            $limit = self::INLINE_STYLE_ALLOWLIST[$relative] ?? 0;
            $this->assertLessThanOrEqual(
                $limit,
                $n,
                "{$relative} tem {$n} style= inline (limite {$limit}). Use classe Bootstrap ou CSS em resources/css/app.css.",
            );
        }
    }

    public function test_layouts_load_the_right_vite_entry(): void
    {
        $views = $this->views();

        $this->assertStringContainsString('resources/js/app.js', $views['layouts/app.blade.php']);
        $this->assertStringNotContainsString('resources/js/admin.js', $views['layouts/app.blade.php']);

        foreach (['layouts/tenant-admin.blade.php', 'admin/auth/login.blade.php'] as $admin) {
            $this->assertStringContainsString('resources/js/admin.js', $views[$admin], $admin);
            $this->assertStringNotContainsString('resources/js/app.js', $views[$admin], $admin);
        }

        $this->assertStringNotContainsString("asset('js/", $views['layouts/app.blade.php']);
    }

    public function test_entries_do_not_cross_load_storefront_and_admin_libraries(): void
    {
        $app = $this->read('resources/js/app.js').$this->read('resources/js/carousel.js');
        $admin = $this->read('resources/js/admin.js');

        $this->assertStringContainsString('@splidejs/splide', $app);
        $this->assertStringNotContainsString('sortablejs', $app);
        $this->assertStringContainsString('sortablejs', $admin);
        $this->assertStringNotContainsString('splide', $admin);

        $config = $this->read('vite.config.js');
        foreach (['resources/css/app.css', 'resources/js/app.js', 'resources/js/admin.js'] as $entry) {
            $this->assertStringContainsString($entry, $config);
        }

        $this->assertFileDoesNotExist($this->root().'/public/js/carousel.js');
    }

    public function test_tailwind_is_gone_and_bootstrap_is_the_framework(): void
    {
        $package = json_decode($this->read('package.json'), true, flags: JSON_THROW_ON_ERROR);
        $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        $this->assertArrayNotHasKey('tailwindcss', $deps);
        $this->assertArrayNotHasKey('@tailwindcss/vite', $deps);
        $this->assertArrayHasKey('bootstrap', $deps);
        $this->assertStringNotContainsString('tailwind', strtolower($this->read('vite.config.js')));
    }

    public function test_no_tenant_slug_is_hardcoded_in_application_code(): void
    {
        $offenders = [];

        foreach (['app', 'routes', 'resources'] as $dir) {
            foreach ($this->files($this->root().'/'.$dir) as $path => $content) {
                // a menção ao nome da classe do seeder (comentário) não é hardcode de slug
                $content = str_ireplace('CarolDocesDemoSeeder', '', $content);
                if (stripos($content, 'caroldoces') !== false) {
                    $offenders[] = substr($path, strlen($this->root()) + 1);
                }
            }
        }

        $this->assertSame([], $offenders, "Slug de tenant hardcoded:\n".implode("\n", $offenders));
    }

    /** @return array<string, string> caminho relativo a resources/views => conteúdo */
    private function views(): array
    {
        $base = $this->root().'/resources/views';
        $views = [];

        foreach ($this->files($base) as $path => $content) {
            if (str_ends_with($path, '.blade.php')) {
                $views[substr($path, strlen($base) + 1)] = $content;
            }
        }

        $this->assertNotEmpty($views);

        return $views;
    }

    /** @return iterable<string, string> */
    private function files(string $dir): iterable
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile()) {
                yield $file->getPathname() => file_get_contents($file->getPathname());
            }
        }
    }

    private function read(string $relative): string
    {
        return file_get_contents($this->root().'/'.$relative);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
