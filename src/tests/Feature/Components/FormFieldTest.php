<?php

namespace Tests\Feature\Components;

use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.2 — `<x-form-field>`, `<x-form-select>`, `<x-form-check>`: corrige o defeito
 * "@error sem is-invalid" (84 vs 4) derivando o estado de `$errors` pelo nome.
 */
class FormFieldTest extends TestCase
{
    use RendersComponents;

    public function test_input_has_label_for_id_and_default_classes(): void
    {
        $html = $this->render('<x-form-field name="name" label="Nome" wire:model.defer="name" />');

        $label = $this->first($html, '//label');
        $input = $this->first($html, '//input');

        $this->assertSame('name', $label->getAttribute('for'));
        $this->assertSame('name', $input->getAttribute('id'));
        $this->assertSame('form-label', $label->getAttribute('class'));
        $this->assertSame('form-control', $input->getAttribute('class'));
        $this->assertSame('text', $input->getAttribute('type'));
        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
    }

    public function test_required_only_adds_the_marker_and_not_the_html_attribute(): void
    {
        $html = $this->render('<x-form-field name="name" label="Nome" required wire:model.defer="name" />');

        $this->assertSame('Nome *', trim($this->first($html, '//label')->textContent));
        $this->assertFalse($this->first($html, '//input')->hasAttribute('required'));
    }

    public function test_is_invalid_and_feedback_appear_automatically_when_there_is_an_error(): void
    {
        $html = $this->render(
            '<x-form-field name="name" label="Nome" wire:model.defer="name" />',
            errors: ['name' => 'O nome é obrigatório.'],
        );

        $input = $this->first($html, '//input');
        $this->assertContains('is-invalid', $this->classes($input));
        $this->assertContains('form-control', $this->classes($input));
        $this->assertSame('true', $input->getAttribute('aria-invalid'));

        $feedback = $this->first($html, '//div[contains(@class,"invalid-feedback")]');
        $this->assertSame('O nome é obrigatório.', trim($feedback->textContent));

        // O CSS do Bootstrap só mostra o feedback se for IRMÃO POSTERIOR do `.is-invalid`.
        $this->assertSame(1, $this->countNodes($html, '//input[contains(@class,"is-invalid")]/following-sibling::div[contains(@class,"invalid-feedback")]'));
    }

    public function test_an_error_for_another_field_does_not_mark_this_one(): void
    {
        $html = $this->render('<x-form-field name="sku" label="SKU" wire:model.defer="sku" />', errors: ['name' => 'x']);

        $this->assertStringNotContainsString('is-invalid', $html);
        $this->assertStringNotContainsString('invalid-feedback', $html);
    }

    public function test_dotted_names_get_a_safe_id_and_match_errors(): void
    {
        $html = $this->render(
            '<x-form-field name="images.0" label="Imagem" type="file" wire:model="images.0" />',
            errors: ['images.0' => 'Arquivo inválido.'],
        );

        $input = $this->first($html, '//input');
        $this->assertSame('images_0', $input->getAttribute('id'));
        $this->assertSame('images_0', $this->first($html, '//label')->getAttribute('for'));
        $this->assertContains('is-invalid', $this->classes($input));
        $this->assertSame('file', $input->getAttribute('type'));
        $this->assertStringContainsString('Arquivo inválido.', $html);
    }

    public function test_wildcard_errors_of_a_parent_name_mark_the_field(): void
    {
        $html = $this->render(
            '<x-form-field name="images" label="Imagens" type="file" multiple wire:model="images" />',
            errors: ['images.2' => 'A imagem 3 é inválida.'],
        );

        $this->assertContains('is-invalid', $this->classes($this->first($html, '//input')));
        $this->assertStringContainsString('A imagem 3 é inválida.', $html);
    }

    public function test_multiple_messages_are_all_rendered(): void
    {
        $html = $this->render('<x-form-field name="name" label="Nome" />', errors: ['name' => ['Muito curto.', 'Inválido.']]);

        $this->assertSame(2, $this->countNodes($html, '//div[contains(@class,"invalid-feedback")]'));
    }

    public function test_attributes_pass_through_to_the_control(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-form-field name="stock" label="Estoque" type="number" min="0" max="10" step="2"
                placeholder="0" disabled wire:model.live="stock" class="extra" />
            BLADE);

        $input = $this->first($html, '//input');
        $this->assertSame('number', $input->getAttribute('type'));
        $this->assertSame('0', $input->getAttribute('min'));
        $this->assertSame('10', $input->getAttribute('max'));
        $this->assertSame('2', $input->getAttribute('step'));
        $this->assertSame('0', $input->getAttribute('placeholder'));
        $this->assertTrue($input->hasAttribute('disabled'));
        $this->assertSame('stock', $input->getAttribute('wire:model.live'));
        $this->assertContains('extra', $this->classes($input));
        $this->assertContains('form-control', $this->classes($input));
    }

    public function test_wire_model_modifiers_are_preserved(): void
    {
        foreach (['wire:model', 'wire:model.defer', 'wire:model.live', 'wire:model.blur'] as $attr) {
            $html = $this->render("<x-form-field name=\"x\" label=\"X\" {$attr}=\"x\" />");

            $this->assertSame('x', $this->first($html, '//input')->getAttribute($attr), $attr);
        }
    }

    public function test_help_text_is_rendered_after_the_feedback(): void
    {
        $html = $this->render(
            '<x-form-field name="image" label="Imagem" type="file" help="PNG/JPG, até 3MB." />',
            errors: ['image' => 'Muito grande.'],
        );

        $this->assertSame(1, $this->countNodes($html, '//input/following-sibling::div[contains(@class,"form-text")]'));
        $this->assertLessThan(strpos($html, 'form-text'), strpos($html, 'invalid-feedback'));
        $this->assertStringContainsString('PNG/JPG, até 3MB.', $html);
    }

    public function test_after_slot_is_rendered_between_feedback_and_help(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-form-field name="image" label="Imagem" type="file" help="Ajuda">
                <x-slot:after><div class="preview">PREVIEW</div></x-slot:after>
            </x-form-field>
            BLADE, errors: ['image' => 'Erro']);

        $this->assertLessThan(strpos($html, 'preview'), strpos($html, 'invalid-feedback'));
        $this->assertLessThan(strpos($html, 'form-text'), strpos($html, 'preview'));
    }

    public function test_textarea_renders_with_rows_and_invalid_state(): void
    {
        $html = $this->render(
            '<x-form-field type="textarea" name="description" label="Descrição" rows="5" wire:model.defer="description" />',
            errors: ['description' => 'Erro'],
        );

        $ta = $this->first($html, '//textarea');
        $this->assertSame('5', $ta->getAttribute('rows'));
        $this->assertSame('description', $ta->getAttribute('wire:model.defer'));
        $this->assertContains('form-control', $this->classes($ta));
        $this->assertContains('is-invalid', $this->classes($ta));
        $this->assertSame(0, $this->countNodes($html, '//input'));
    }

    public function test_without_label_only_the_control_is_emitted_and_no_id_is_forced(): void
    {
        $html = $this->render('<x-form-field name="search" placeholder="Buscar" wire:model.live="search" />');

        $this->assertSame(0, $this->countNodes($html, '//label'));
        $this->assertFalse($this->first($html, '//input')->hasAttribute('id'));
    }

    public function test_explicit_id_overrides_the_derived_one(): void
    {
        $html = $this->render('<x-form-field name="name" id="custom" label="Nome" />');

        $this->assertSame('custom', $this->first($html, '//input')->getAttribute('id'));
        $this->assertSame('custom', $this->first($html, '//label')->getAttribute('for'));
    }

    public function test_named_emits_the_name_attribute_only_when_requested(): void
    {
        $this->assertFalse($this->first($this->render('<x-form-field name="email" label="E" />'), '//input')->hasAttribute('name'));
        $this->assertSame('email', $this->first($this->render('<x-form-field name="email" label="E" named />'), '//input')->getAttribute('name'));
    }

    public function test_renders_without_a_shared_errors_variable(): void
    {
        $this->withoutVite();
        view()->share('errors', null);

        $html = \Illuminate\Support\Facades\Blade::render('<x-form-field name="name" label="Nome" />');

        $this->assertStringContainsString('form-control', $html);
    }

    public function test_group_renders_slot_and_shows_feedback_with_d_block(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-form-field type="group" name="category_ids" label="Categorias">
                <div class="row g-2"><x-form-check name="category_ids" id="cat_1" value="1" label="Bolos" :validates="false" /></div>
            </x-form-field>
            BLADE, errors: ['category_ids.0' => 'Categoria inválida.']);

        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"invalid-feedback") and contains(@class,"d-block")]'));
        $this->assertStringContainsString('Categoria inválida.', $html);
        $this->assertSame(0, $this->countNodes($html, '//input[contains(@class,"is-invalid")]'));
        $this->assertFalse($this->first($html, '//label[contains(@class,"form-label")]')->hasAttribute('for'));
    }

    public function test_select_renders_options_with_form_select_and_invalid_state(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-form-select name="status" label="Status" wire:model.live="status">
                <option value="all">Todos</option>
                <option value="active">Ativos</option>
            </x-form-select>
            BLADE, errors: ['status' => 'Status inválido.']);

        $select = $this->first($html, '//select');
        $this->assertContains('form-select', $this->classes($select));
        $this->assertContains('is-invalid', $this->classes($select));
        $this->assertNotContains('form-control', $this->classes($select));
        $this->assertSame('status', $select->getAttribute('id'));
        $this->assertSame('status', $select->getAttribute('wire:model.live'));
        $this->assertSame(2, $this->countNodes($html, '//select/option'));
        $this->assertSame('status', $this->first($html, '//label')->getAttribute('for'));
        $this->assertStringContainsString('Status inválido.', $html);
    }

    public function test_select_without_error_has_no_invalid_markup(): void
    {
        $html = $this->render('<x-form-select name="sort" wire:model.live="sort"><option value="a">A</option></x-form-select>');

        $this->assertSame('form-select', $this->first($html, '//select')->getAttribute('class'));
        $this->assertStringNotContainsString('is-invalid', $html);
    }

    public function test_check_renders_form_check_with_label_for_and_attributes(): void
    {
        $html = $this->render('<x-form-check name="is_active" label="Ativo" wire:model.defer="is_active" />');

        $this->assertSame('form-check', $this->first($html, '//div')->getAttribute('class'));
        $input = $this->first($html, '//input');
        $this->assertSame('checkbox', $input->getAttribute('type'));
        $this->assertSame('form-check-input', $input->getAttribute('class'));
        $this->assertSame('is_active', $input->getAttribute('id'));
        $this->assertSame('is_active', $input->getAttribute('wire:model.defer'));
        $label = $this->first($html, '//label');
        $this->assertSame('form-check-label', $label->getAttribute('class'));
        $this->assertSame('is_active', $label->getAttribute('for'));
        $this->assertSame('Ativo', trim($label->textContent));
    }

    public function test_check_marks_invalid_and_places_feedback_inside_form_check(): void
    {
        $html = $this->render(
            '<x-form-check name="terms" label="Aceito" help="Leia." wire:model="terms" />',
            errors: ['terms' => 'Aceite os termos.'],
        );

        $this->assertContains('is-invalid', $this->classes($this->first($html, '//input')));
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"form-check")]/div[contains(@class,"invalid-feedback")]'));
        // ajuda fica FORA do `.form-check` (sem recuo do padding-left)
        $this->assertSame(1, $this->countNodes($html, '//div[contains(@class,"form-check")]/following-sibling::div[contains(@class,"form-text")]'));
    }

    public function test_check_switch_radio_and_group_usage(): void
    {
        $switch = $this->render('<x-form-check name="on" label="On" switch />');
        $this->assertSame('form-check form-switch', $this->first($switch, '//div')->getAttribute('class'));

        $radio = $this->render('<x-form-check name="opt" type="radio" id="opt_a" value="a" label="A" />');
        $this->assertSame('radio', $this->first($radio, '//input')->getAttribute('type'));
        $this->assertSame('opt_a', $this->first($radio, '//label')->getAttribute('for'));

        $unvalidated = $this->render('<x-form-check name="category_ids" id="cat_1" value="1" label="X" :validates="false" />', errors: ['category_ids' => 'e']);
        $this->assertStringNotContainsString('is-invalid', $unvalidated);
    }

    public function test_inside_a_livewire_render_no_morph_marker_lands_inside_a_tag(): void
    {
        $html = $this->renderInsideLivewire(<<<'BLADE'
            <x-form-field name="name" label="Nome" required help="Ajuda" wire:model.defer="name" />
            <x-form-field type="group" name="category_ids" label="Categorias">
                <x-form-check name="category_ids" id="cat_1" label="A" :validates="false" />
            </x-form-field>
            <x-form-select name="status" label="Status"><option>a</option></x-form-select>
            <x-form-check name="x" label="X" help="h" switch />
            BLADE, errors: ['name' => 'Erro', 'category_ids.0' => 'Erro 2']);

        $this->assertStringContainsString('<!--[if BLOCK]>', $html);
        $this->assertNoMarkerInsideTags($html);
        $this->assertSame('name', $this->first($html, '//label')->getAttribute('for'));
        $this->assertContains('is-invalid', $this->classes($this->first($html, '//input')));
    }
}
