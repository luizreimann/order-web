<?php

namespace Tests\Feature\Theme;

use App\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Architecture\ThemeClassesTest;

/**
 * O tema do tenant tem de dominar as cores que o Bootstrap fixa em azul no CSS COMPILADO.
 *
 * `--bs-primary` sozinho não basta: o Bootstrap 5.3 compõe `rgba(var(--bs-primary-rgb), a)` e traz azul
 * literal em foco (`#86b7fe`, `rgba(13,110,253,.25)`), botões desabilitados (`#0d6efd`), seta do accordion
 * e bolinha do switch (SVG com fill fixo). O que sobrar vaza o azul padrão para quem tem outra marca.
 *
 * Renderiza a partial com um tenant de cor DISTINTA do azul (rosa e verde: uma cor "fixa por coincidência"
 * não passa nas duas) e confere o CSS emitido, sem navegador.
 */
class TenantThemeRenderTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function brands(): array
    {
        return [
            'rosa' => ['#ec4899', '236, 72, 153'],
            'verde' => ['#16a34a', '22, 163, 74'],
            'hex curto' => ['#c4a', '204, 68, 170'],
        ];
    }

    private function css(string $primary, string $secondary = '#78716c'): string
    {
        $tenant = (new Tenant)->forceFill([
            'name' => 'Loja Tema',
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'settings' => [],
        ]);

        $html = view('partials.tenant-theme', ['currentTenant' => $tenant])->render();
        $this->assertSame(1, preg_match('#<style>(.*)</style>#s', $html, $m));

        // sem comentários: eles explicam o problema citando o azul/seletor proibido
        return preg_replace('#/\*.*?\*/#s', '', $m[1]);
    }

    /** Corpo da declaração do primeiro bloco cujo seletor contém `$selector` e que declara `$property`. */
    private function declared(string $css, string $selector, string $property): ?string
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', preg_replace('#/\*.*?\*/#s', '', $css), $blocks, PREG_SET_ORDER);

        foreach ($blocks as [, $sel, $body]) {
            if (! str_contains(preg_replace('/\s+/', ' ', trim($sel)), $selector)) {
                continue;
            }
            if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/', $body, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    #[DataProvider('brands')]
    public function test_the_rgb_triplets_follow_the_tenant_brand(string $hex, string $rgb): void
    {
        $css = $this->css($hex);

        $this->assertStringContainsString("--tenant-primary: {$hex};", $css);
        $this->assertStringContainsString("--tenant-primary-rgb: {$rgb};", $css);

        foreach (['--bs-primary-rgb', '--bs-link-color-rgb', '--bs-link-hover-color-rgb'] as $var) {
            $this->assertSame('var(--tenant-primary-rgb)', $this->declared($css, ':root', $var), $var);
        }
        // modo escuro: o Bootstrap redefine o link em azul claro; o tema do tenant precisa vencer
        $this->assertSame('var(--tenant-primary-rgb)', $this->declared($css, '[data-bs-theme="dark"]', '--bs-link-color-rgb'));
    }

    #[DataProvider('brands')]
    public function test_no_bootstrap_default_blue_is_emitted_for_a_non_blue_brand(string $hex): void
    {
        $this->assertDoesNotMatchRegularExpression(ThemeClassesTest::BLUE, $this->css($hex));
    }

    public function test_the_tenant_scope_also_applies_inside_a_nested_light_container(): void
    {
        // o Bootstrap redefine --bs-* em QUALQUER [data-bs-theme]; sem este seletor o preview da loja em
        // Personalização (data-bs-theme="light" dentro do admin escuro) voltaria ao azul padrão.
        $css = $this->css('#ec4899');

        $this->assertMatchesRegularExpression('/:root,\s*\[data-bs-theme="light"\]\s*\{[^}]*--bs-primary:\s*var\(--tenant-primary\)/s', $css);
    }

    public function test_focus_states_hardcoded_in_blue_by_bootstrap_use_the_tenant_focus_ring(): void
    {
        $css = $this->css('#ec4899');

        $ring = '0 0 0 .25rem var(--bs-focus-ring-color)';

        $this->assertSame($ring, $this->declared($css, '.nav-link:focus-visible', 'box-shadow'));
        $this->assertSame($ring, $this->declared($css, '.accordion', '--bs-accordion-btn-focus-box-shadow'));
        $this->assertSame($ring, $this->declared($css, '.pagination', '--bs-pagination-focus-box-shadow'));
        $this->assertSame($ring, $this->declared($css, '.btn-close', '--bs-btn-close-focus-shadow'));
        $this->assertSame($ring, $this->declared($css, '.form-control:focus', 'box-shadow'));
        $this->assertSame('var(--tenant-focus-border)', $this->declared($css, '.form-control:focus', 'border-color'));
        $this->assertSame('var(--tenant-focus-border)', $this->declared($css, '.form-check-input:focus', 'border-color'));
    }

    public function test_button_focus_and_disabled_states_use_the_tenant_color(): void
    {
        $css = $this->css('#ec4899');

        foreach (['.btn-primary', '.btn-outline-primary', '.btn-link'] as $selector) {
            $this->assertSame('var(--tenant-primary-rgb)', $this->declared($css, $selector, '--bs-btn-focus-shadow-rgb'), $selector);
        }
        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.btn-primary', '--bs-btn-disabled-bg'));
        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.btn-primary', '--bs-btn-disabled-border-color'));
        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.btn-outline-primary', '--bs-btn-disabled-color'));
        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.btn-outline-primary', '--bs-btn-disabled-border-color'));
    }

    public function test_checked_and_indeterminate_controls_and_the_switch_knob_use_the_tenant_color(): void
    {
        $css = $this->css('#ec4899');

        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.form-check-input:checked', 'background-color'));
        $this->assertStringContainsString('.form-check-input[type="checkbox"]:indeterminate', $css);
        // bolinha do switch com foco: o Bootstrap usa SVG com fill #86b7fe; aqui é gradiente na cor do tenant
        $this->assertStringContainsString('radial-gradient(circle closest-side, var(--tenant-focus-border)', (string) $this->declared($css, '.form-switch .form-check-input:focus:not(:checked)', '--bs-form-switch-bg'));
        $this->assertSame('var(--tenant-primary)', $this->declared($css, '.form-range::-webkit-slider-thumb', 'background-color'));
    }

    public function test_dark_mode_keeps_brand_text_legible(): void
    {
        $css = $this->css('#ec4899');

        $this->assertSame('var(--bs-link-color)', $this->declared($css, '[data-bs-theme="dark"] .btn-outline-primary', '--bs-btn-color'));
        $this->assertSame('var(--bs-secondary-text-emphasis)', $this->declared($css, '[data-bs-theme="dark"] .btn-outline-secondary', '--bs-btn-color'));
        $this->assertSame('var(--bs-link-color) !important', $this->declared($css, '[data-bs-theme="dark"] .text-primary', 'color'));
    }

    public function test_status_secondary_badges_stay_neutral_and_do_not_take_the_tenant_secondary(): void
    {
        // D8: "Cancelado/Fechado/Esgotado/Inativo" são `text-bg-secondary`; com a secundária do tenant eles
        // mudavam de cor por loja. A secundária continua valendo em botões/alertas, não nos badges de status.
        $css = $this->css('#ec4899', '#16a34a');

        $this->assertDoesNotMatchRegularExpression('/\.badge\.(?:bg|text-bg)-secondary[^{]*\{/', $css);
        $this->assertStringNotContainsString('--bs-secondary-rgb', $css);
    }

    /**
     * Completude contra o Bootstrap COMPILADO que o app usa: toda declaração com azul literal (fora das
     * definições de paleta em :root) precisa ter um override do tema (tenant-theme ou app.css) para o mesmo
     * componente e propriedade. Pega o upgrade do Bootstrap que traga um azul novo, e o override esquecido.
     */
    public function test_every_blue_literal_in_compiled_bootstrap_is_overridden_by_the_theme(): void
    {
        $bootstrap = dirname(__DIR__, 3).'/node_modules/bootstrap/dist/css/bootstrap.min.css';
        $this->assertFileExists($bootstrap, 'rode `npm ci` (o teste lê o CSS compilado do Bootstrap).');

        $theme = $this->css('#ec4899');
        $app = file_get_contents(dirname(__DIR__, 3).'/resources/css/app.css');

        // Componentes que o app NÃO usa (confirmado abaixo) e, portanto, não têm override.
        $unused = ['.table-primary', '.dropdown-menu-dark'];

        $source = preg_replace(['#/\*.*?\*/#s', '/@charset\s+"[^"]*";/'], '', file_get_contents($bootstrap));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $source, $rules, PREG_SET_ORDER);

        $missing = [];
        $checked = 0;

        foreach ($rules as [, $selector, $body]) {
            $selector = trim($selector);
            // definições de paleta/variáveis globais: o tema as reaponta (--bs-primary etc.), não são "uso"
            if (in_array($selector, [':root,[data-bs-theme=light]', '[data-bs-theme=dark]'], true)) {
                continue;
            }

            foreach (preg_split('/;(?![^(]*\))/', $body) as $declaration) {
                [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
                $property = trim($property);

                if ($property === '' || ! preg_match(ThemeClassesTest::BLUE, $value)) {
                    continue;
                }
                $checked++;

                $component = $this->baseComponent($selector);
                if (in_array($component, $unused, true)) {
                    continue;
                }

                // ícone do accordion: neutralizado por máscara + currentColor em app.css (não por variável)
                if (in_array($property, ['--bs-accordion-btn-icon', '--bs-accordion-btn-active-icon'], true)) {
                    if (! preg_match('/\.accordion-button::after[^{]*\{[^}]*background-image:\s*none[^}]*mask/s', $app)) {
                        $missing[] = "{$selector} {$property}";
                    }

                    continue;
                }

                if ($this->declared($theme, $component, $property) === null) {
                    $missing[] = "{$selector} {  {$property} }";
                }
            }
        }

        $this->assertGreaterThan(15, $checked, 'o parser não achou os azuis do Bootstrap: o teste ficou cego.');
        $this->assertSame([], $missing, "Azul fixo do Bootstrap sem override do tema:\n".implode("\n", $missing));

        $views = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 3).'/resources/views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $views .= file_get_contents($file->getPathname());
        }
        foreach ($unused as $class) {
            $this->assertStringNotContainsString(ltrim($class, '.'), $views, "{$class} passou a ser usado: crie o override no tema.");
        }
    }

    /** Primeiro seletor simples da regra: `.form-check-input:focus,.x` -> `.form-check-input`; `.form-switch .form-check-input:focus` -> `.form-check-input`. */
    private function baseComponent(string $selector): string
    {
        $first = trim(explode(',', $selector)[0]);
        $last = preg_split('/\s+/', $first);
        $last = end($last);

        preg_match('/^(?:\[data-bs-theme=\w+\]\s*)?(\.[\w-]+)/', $last, $m);

        return $m[1] ?? $last;
    }
}
