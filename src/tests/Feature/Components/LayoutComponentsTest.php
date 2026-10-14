<?php

namespace Tests\Feature\Components;

use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.2 — `<x-card>`, `<x-page-header>`, `<x-section-title>`, `<x-empty-state>`,
 * `<x-data-table>` e a regra de espaçamento (componente sem margem própria).
 */
class LayoutComponentsTest extends TestCase
{
    use RendersComponents;

    public function test_card_default_has_card_shadow_sm_and_card_body(): void
    {
        $html = $this->render('<x-card>Conteúdo</x-card>');

        $this->assertSame('card shadow-sm', $this->first($html, '/html/body/div')->getAttribute('class'));
        $this->assertSame('Conteúdo', trim($this->first($html, '//div[@class="card-body"]')->textContent));
        $this->assertSame(0, $this->countNodes($html, '//*[contains(@class,"card-header") or contains(@class,"card-footer")]'));
    }

    public function test_card_header_and_footer_slots(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-card>
                <x-slot:header>CAB</x-slot:header>
                Corpo
                <x-slot:footer>ROD</x-slot:footer>
            </x-card>
            BLADE);

        $this->assertSame('CAB', trim($this->first($html, '//div[@class="card-header"]')->textContent));
        $this->assertSame('ROD', trim($this->first($html, '//div[@class="card-footer"]')->textContent));
        $this->assertSame('Corpo', trim($this->first($html, '//div[@class="card-body"]')->textContent));
    }

    public function test_card_variants_flush_muted_full(): void
    {
        $html = $this->render('<x-card flush muted full><div class="table-responsive">T</div></x-card>');

        $card = $this->first($html, '/html/body/div');
        $this->assertEqualsCanonicalizing(['card', 'shadow-sm', 'bg-light', 'h-100'], $this->classes($card));
        $this->assertSame(0, $this->countNodes($html, '//*[contains(@class,"card-body")]'));
        $this->assertSame(1, $this->countNodes($html, '/html/body/div/div[@class="table-responsive"]'));
    }

    public function test_card_passes_attributes_and_has_no_margin_of_its_own(): void
    {
        $html = $this->render('<x-card wire:key="k" id="c">X</x-card>');

        $card = $this->first($html, '/html/body/div');
        $this->assertSame('k', $card->getAttribute('wire:key'));
        $this->assertSame('c', $card->getAttribute('id'));
        $this->assertDoesNotMatchRegularExpression('/\b[mp]?[mtbsexy]-\d|\bm[tbsexy]?-(auto|\d)/', implode(' ', array_diff($this->classes($card), ['card', 'shadow-sm'])));
    }

    public function test_no_layout_component_emits_an_outer_margin_class(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-card>
                <x-slot:header>H</x-slot:header>
                B
            </x-card>
            <x-page-header title="T" subtitle="S">
                <x-slot:actions><x-btn>A</x-btn></x-slot:actions>
            </x-page-header>
            <x-section-title>S</x-section-title>
            <x-section-title sub>S</x-section-title>
            <x-data-table>
                <x-slot:head><th>A</th></x-slot:head>
                <tr><td>1</td></tr>
            </x-data-table>
            <x-data-table empty :columns="2">
                <x-slot:head><th>A</th><th>B</th></x-slot:head>
            </x-data-table>
            BLADE);

        // `mb-0`/`p-0` (zerar) e `me-1` (interno ao botão/ícone) são ok; margem positiva externa não.
        preg_match_all('/class="([^"]*)"/', $html, $m);
        foreach ($m[1] as $classAttr) {
            $this->assertDoesNotMatchRegularExpression('/(^|\s)m[tbxyse]?-[1-5](\s|$)/', $classAttr, "classe com margem: {$classAttr}");
        }
    }

    public function test_page_header_renders_h1_h4_subtitle_and_actions_on_the_right(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-page-header title="Produtos" subtitle="Gerencie o catálogo.">
                <x-slot:actions><x-btn href="/novo">Novo produto</x-btn></x-slot:actions>
            </x-page-header>
            BLADE);

        $root = $this->first($html, '/html/body/div');
        foreach (['d-flex', 'align-items-center', 'justify-content-between'] as $c) {
            $this->assertContains($c, $this->classes($root));
        }
        $h1 = $this->first($html, '//h1');
        $this->assertSame('h4 mb-0', $h1->getAttribute('class'));
        $this->assertSame('Produtos', trim($h1->textContent));
        $this->assertSame('Gerencie o catálogo.', trim($this->first($html, '//div[@class="text-muted small"]')->textContent));
        // ações: último filho do cabeçalho
        $this->assertSame(1, $this->countNodes($html, '/html/body/div/div[last()]//a[contains(@class,"btn-primary")]'));
        $this->assertSame(1, $this->countNodes($html, '//h1'));
    }

    public function test_page_header_without_subtitle_and_actions(): void
    {
        $html = $this->render('<x-page-header title="Só título" />');

        $this->assertSame(0, $this->countNodes($html, '//*[contains(@class,"text-muted")]'));
        $this->assertSame(1, $this->countNodes($html, '/html/body/div/div'));
    }

    public function test_section_title_is_h2_h6_fw_semibold_without_border(): void
    {
        $html = $this->render('<x-section-title>Dados</x-section-title>');

        $h2 = $this->first($html, '//h2');
        $this->assertEqualsCanonicalizing(['h6', 'fw-semibold', 'mb-0'], $this->classes($h2));
        $this->assertStringNotContainsString('border', $html);
        $this->assertSame('Dados', trim($h2->textContent));
    }

    public function test_section_subtitle_is_h3_h6_text_muted(): void
    {
        $html = $this->render('<x-section-title sub>Agendamento</x-section-title>');

        $this->assertEqualsCanonicalizing(['h6', 'text-muted', 'mb-0'], $this->classes($this->first($html, '//h3')));
        $this->assertSame(0, $this->countNodes($html, '//h2'));
    }

    public function test_empty_state_renders_message_icon_and_action(): void
    {
        $plain = $this->render('<x-empty-state message="Nada aqui." />');
        $this->assertEqualsCanonicalizing(['text-center', 'py-5', 'text-muted'], $this->classes($this->first($plain, '/html/body/div')));
        $this->assertStringContainsString('Nada aqui.', $plain);
        $this->assertSame(0, $this->countNodes($plain, '//i'));
        $this->assertSame(0, $this->countNodes($plain, '//*[contains(@class,"mt-3")]'));

        $full = $this->render('<x-empty-state icon="fa-solid fa-box-open" message="Sem produtos."><x-btn href="/n">Novo</x-btn></x-empty-state>');
        $this->assertSame('fa-solid fa-box-open', $this->first($full, '//i')->getAttribute('class'));
        $this->assertSame(1, $this->countNodes($full, '//div[@class="mt-3"]/a[contains(@class,"btn")]'));
    }

    public function test_data_table_uses_the_single_standard_table_markup(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-data-table>
                <x-slot:head><th>Nome</th><th class="text-end">Ações</th></x-slot:head>
                <tr><td>A</td><td>B</td></tr>
            </x-data-table>
            BLADE);

        $this->assertSame('table-responsive', $this->first($html, '/html/body/div')->getAttribute('class'));
        $this->assertEqualsCanonicalizing(['table', 'table-hover', 'align-middle', 'mb-0'], $this->classes($this->first($html, '//table')));
        $this->assertSame(2, $this->countNodes($html, '//thead/tr/th'));
        $this->assertSame(1, $this->countNodes($html, '//tbody/tr'));
        $this->assertSame(0, $this->countNodes($html, '//*[contains(@class,"text-center")]'));
    }

    public function test_data_table_shows_empty_state_with_colspan_and_keeps_header(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-data-table :empty="true" :columns="5" empty-message="Nenhum produto encontrado.">
                <x-slot:head><th>1</th><th>2</th><th>3</th><th>4</th><th>5</th></x-slot:head>
                <tr class="should-not-render"><td>x</td></tr>
            </x-data-table>
            BLADE);

        $this->assertSame(5, $this->countNodes($html, '//thead/tr/th'));
        $this->assertSame('5', $this->first($html, '//tbody/tr/td')->getAttribute('colspan'));
        $this->assertSame(1, $this->countNodes($html, '//tbody/tr/td/div[contains(@class,"text-center")]'));
        $this->assertStringContainsString('Nenhum produto encontrado.', $html);
        $this->assertStringNotContainsString('should-not-render', $html);
    }

    public function test_data_table_empty_action_slot_reaches_the_empty_state(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-data-table empty :columns="1" empty-message="Vazio">
                <x-slot:emptyAction><x-btn href="/n">Criar</x-btn>
                </x-slot:emptyAction>
            </x-data-table>
            BLADE);

        $this->assertSame(1, $this->countNodes($html, '//div[@class="mt-3"]/a[contains(@class,"btn-primary")]'));
    }
}
