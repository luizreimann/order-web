<?php

namespace Tests\Feature\Components;

use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Components\Support\RendersComponents;
use Tests\TestCase;

/**
 * E4.3 — `<x-confirm-action>`: `wire:confirm` padronizado, variante danger e `@can` embutido.
 */
class ConfirmActionTest extends TestCase
{
    use RendersComponents;

    public function test_default_is_a_small_outline_danger_button_with_the_standard_message(): void
    {
        $html = $this->render('<x-confirm-action wire:click="delete(1)">Excluir</x-confirm-action>');

        $btn = $this->first($html, '/html/body/button');
        $this->assertEqualsCanonicalizing(['btn', 'btn-outline-danger', 'btn-sm'], $this->classes($btn));
        $this->assertSame('button', $btn->getAttribute('type'));
        $this->assertSame('Tem certeza? Esta ação não pode ser desfeita.', $btn->getAttribute('wire:confirm'));
        $this->assertSame('delete(1)', $btn->getAttribute('wire:click'));
        $this->assertSame('Excluir', trim($btn->textContent));
    }

    public function test_custom_message_and_extra_attributes_pass_through(): void
    {
        $html = $this->render('<x-confirm-action message="Excluir este produto?" class="ms-2" data-x="1" wire:click="d">Excluir</x-confirm-action>');

        $btn = $this->first($html, '/html/body/button');
        $this->assertSame('Excluir este produto?', $btn->getAttribute('wire:confirm'));
        $this->assertSame('1', $btn->getAttribute('data-x'));
        $this->assertContains('ms-2', $this->classes($btn));
        $this->assertContains('btn-outline-danger', $this->classes($btn));
    }

    public function test_message_is_escaped_in_the_attribute(): void
    {
        $html = $this->render('<x-confirm-action :message="$m">X</x-confirm-action>', ['m' => 'Excluir "a" <b>?']);

        $this->assertSame('Excluir "a" <b>?', $this->first($html, '/html/body/button')->getAttribute('wire:confirm'));
        $this->assertStringNotContainsString('<b>?"', $html);
    }

    public function test_variant_outline_and_size_can_be_changed(): void
    {
        $html = $this->render('<x-confirm-action variant="secondary" :outline="false" size="">X</x-confirm-action>');

        $this->assertEqualsCanonicalizing(['btn', 'btn-secondary'], $this->classes($this->first($html, '/html/body/button')));
    }

    public function test_renders_nothing_when_the_user_cannot(): void
    {
        Gate::before(fn () => false);

        $html = $this->render('<x-confirm-action can="delete" :arg="$t" wire:click="d">Excluir</x-confirm-action>', ['t' => new Tenant]);

        $this->assertSame(0, $this->countNodes($html, '//button'));
        $this->assertStringNotContainsString('Excluir', $html);
        $this->assertStringNotContainsString('wire:click', $html);
    }

    public function test_renders_when_the_user_can_and_passes_the_argument_to_the_gate(): void
    {
        $seen = [];
        Gate::before(function ($user = null, string $ability = '', array $args = []) use (&$seen) {
            $seen = [$ability, $args];

            return true;
        });
        $tenant = new Tenant;

        $html = $this->render('<x-confirm-action can="delete" :arg="$t">Excluir</x-confirm-action>', ['t' => $tenant]);

        $this->assertSame(1, $this->countNodes($html, '//button[@*[name()="wire:confirm"]]'));
        $this->assertSame('delete', $seen[0]);
        $this->assertSame($tenant, $seen[1][0]);
    }

    public function test_without_can_it_always_renders(): void
    {
        Gate::before(fn () => false);

        $this->assertSame(1, $this->countNodes($this->render('<x-confirm-action>Remover</x-confirm-action>'), '//button'));
    }

    public function test_no_livewire_marker_lands_inside_the_tag(): void
    {
        $html = $this->renderInsideLivewire('<x-confirm-action wire:click="d" message="Ok?">Excluir</x-confirm-action>');

        $this->assertNoMarkerInsideTags($html);
        $this->assertSame(1, $this->countNodes($html, '//button[@*[name()="wire:confirm"]="Ok?"]'));
    }
}
