<?php

namespace Tests\Feature\Components;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.2 — `<x-btn>`: variantes semânticas, link, loading integrado ao `wire:loading`.
 */
class BtnTest extends TestCase
{
    use RendersComponents;

    /** @return array<string, array{string, string, list<string>}> */
    public static function variants(): array
    {
        return [
            'primary (padrão)' => ['<x-btn>Ok</x-btn>', 'button', ['btn', 'btn-primary']],
            'secondary' => ['<x-btn variant="secondary">Ok</x-btn>', 'button', ['btn', 'btn-secondary']],
            'danger' => ['<x-btn variant="danger">Ok</x-btn>', 'button', ['btn', 'btn-danger']],
            'link' => ['<x-btn variant="link">Ok</x-btn>', 'button', ['btn', 'btn-link']],
            'outline secondary' => ['<x-btn variant="secondary" outline>Ok</x-btn>', 'button', ['btn', 'btn-outline-secondary']],
            'outline primary sm' => ['<x-btn outline size="sm">Ok</x-btn>', 'button', ['btn', 'btn-outline-primary', 'btn-sm']],
            'outline danger sm' => ['<x-btn variant="danger" outline size="sm">Ok</x-btn>', 'button', ['btn', 'btn-outline-danger', 'btn-sm']],
            'lg' => ['<x-btn size="lg">Ok</x-btn>', 'button', ['btn', 'btn-primary', 'btn-lg']],
            'link ignora outline' => ['<x-btn variant="link" outline>Ok</x-btn>', 'button', ['btn', 'btn-link']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('variants')]
    public function test_variants_render_the_expected_classes(string $tpl, string $tag, array $expected): void
    {
        $el = $this->first($this->render($tpl), "/html/body/{$tag}");

        $this->assertEqualsCanonicalizing($expected, $this->classes($el));
    }

    public function test_button_defaults_to_type_button_and_accepts_submit(): void
    {
        $this->assertSame('button', $this->first($this->render('<x-btn>X</x-btn>'), '//button')->getAttribute('type'));
        $this->assertSame('submit', $this->first($this->render('<x-btn type="submit">X</x-btn>'), '//button')->getAttribute('type'));
    }

    public function test_href_renders_an_anchor_without_button_type(): void
    {
        $html = $this->render('<x-btn href="/produtos/novo" variant="secondary" outline>Voltar</x-btn>');

        $a = $this->first($html, '/html/body/a');
        $this->assertSame('/produtos/novo', $a->getAttribute('href'));
        $this->assertEqualsCanonicalizing(['btn', 'btn-outline-secondary'], $this->classes($a));
        $this->assertSame(0, $this->countNodes($html, '//button'));
        $this->assertSame('Voltar', trim($a->textContent));
    }

    public function test_livewire_attributes_pass_through_intact(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-btn variant="danger" outline size="sm" wire:click="delete('abc')" wire:confirm="Tem certeza?" data-x="1">Excluir</x-btn>
            BLADE);

        $b = $this->first($html, '//button');
        $this->assertSame("delete('abc')", $b->getAttribute('wire:click'));
        $this->assertSame('Tem certeza?', $b->getAttribute('wire:confirm'));
        $this->assertSame('1', $b->getAttribute('data-x'));
    }

    public function test_no_loading_markup_without_the_loading_prop(): void
    {
        $html = $this->render('<x-btn wire:click="toggle">X</x-btn>');

        $this->assertStringNotContainsString('wire:loading', $html);
        $this->assertStringNotContainsString('spinner', $html);
    }

    public function test_loading_binds_spinner_and_disabled_to_the_same_target(): void
    {
        $html = $this->render('<x-btn type="submit" loading="save">Salvar</x-btn>');

        $button = $this->first($html, '//button');
        $this->assertSame('save', $button->getAttribute('wire:target'));
        $this->assertTrue($button->hasAttribute('wire:loading.attr'));
        $this->assertSame('disabled', $button->getAttribute('wire:loading.attr'));
        // sem `disabled` estático: só o Livewire o aplica durante a ação
        $this->assertFalse($button->hasAttribute('disabled'));

        $spinner = $this->first($html, '//button/span[contains(@class,"spinner-border")]');
        $this->assertContains('spinner-border-sm', $this->classes($spinner));
        $this->assertSame('save', $spinner->getAttribute('wire:target'));
        $this->assertTrue($spinner->hasAttribute('wire:loading'));
        $this->assertSame('true', $spinner->getAttribute('aria-hidden'));
        $this->assertSame('Salvar', trim($button->textContent));
    }

    public function test_loading_target_is_escaped(): void
    {
        $html = $this->render('<x-btn :loading="$t">X</x-btn>', ['t' => 'delete("a")']);

        $this->assertSame(2, substr_count($html, 'wire:target="delete(&quot;a&quot;)"'));
    }

    public function test_loading_is_ignored_on_links(): void
    {
        $html = $this->render('<x-btn href="/x" loading="save">X</x-btn>');

        $this->assertStringNotContainsString('wire:loading', $html);
        $this->assertStringNotContainsString('spinner', $html);
    }

    public function test_icon_slot_is_rendered_before_the_text(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-btn href="/n">
                <x-slot:icon><i class="fa-solid fa-plus"></i></x-slot:icon>
                Novo
            </x-btn>
            BLADE);

        $this->assertSame(1, $this->countNodes($html, '//a/span[@class="me-1"]/i[contains(@class,"fa-plus")]'));
        $this->assertLessThan(strpos($html, 'Novo'), strpos($html, 'fa-plus'));
    }

    public function test_inside_a_btn_group_the_btn_stays_a_direct_child(): void
    {
        $html = $this->render('<div class="btn-group"><x-btn size="sm" outline href="/e">Editar</x-btn><x-btn size="sm" variant="secondary" outline>Ativar</x-btn></div>');

        $this->assertSame(2, $this->countNodes($html, '//div[@class="btn-group"]/*[contains(@class,"btn")]'));
    }

    public function test_no_fixed_color_class_is_emitted(): void
    {
        $html = $this->render('<x-btn>Ok</x-btn><x-btn variant="secondary" outline>Ok</x-btn>');

        $this->assertDoesNotMatchRegularExpression('/\b(bg|text-bg)-primary\b|style=/', $html);
    }

    public function test_inside_a_livewire_render_no_morph_marker_lands_inside_a_tag(): void
    {
        $html = $this->renderInsideLivewire(<<<'BLADE'
            <x-btn type="submit" loading="save" wire:click="x">Salvar</x-btn>
            <x-btn href="/a" size="sm">
                <x-slot:icon><i class="fa-solid fa-plus"></i></x-slot:icon>
                Novo
            </x-btn>
            BLADE);

        $this->assertStringContainsString('<!--[if BLOCK]>', $html);
        $this->assertNoMarkerInsideTags($html);
        $this->assertSame('save', $this->first($html, '//button')->getAttribute('wire:target'));
        $this->assertSame('disabled', $this->first($html, '//button')->getAttribute('wire:loading.attr'));
        $this->assertSame(1, $this->countNodes($html, '//button/span[contains(@class,"spinner-border")]'));
    }
}
