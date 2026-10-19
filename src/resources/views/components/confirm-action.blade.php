{{--
    <x-confirm-action> — botão de ação destrutiva com confirmação (E4.3). Encapsula
    `wire:confirm` + variante `danger` e NÃO renderiza nada se o usuário não pode (`@can`).

    Props:
      message  texto do `confirm()` do navegador. Padrão: "Tem certeza? Esta ação não pode ser desfeita."
               Prefira dizer O QUÊ: message="Excluir este produto?"
      variant  danger (padrão) | secondary | primary | link (repassado a <x-btn>)
      outline  padrão true (`btn-outline-danger`); `:outline="false"` para sólido
      size     padrão `sm`; `lg`, ou `size=""` para o tamanho normal (o Blade não deixa passar null
               por cima do padrão do @props)
      can      ability do Gate/Policy (ex.: "delete"). Sem `can`, sempre renderiza.
      arg      argumento da ability: model (`:arg="$product"`), `[Class::class, $tenant]`, etc.
    Slot: o rótulo (texto/ícone). Qualquer atributo extra passa ao <button>: wire:click, class,
    wire:loading.attr, data-*...

    Exemplos (substituem o par `@can('delete', $p)` + `<x-btn ... wire:confirm>`):
      <x-confirm-action can="delete" :arg="$product"
                        message="Tem certeza que deseja excluir este produto?"
                        wire:click="delete('{{ $product->id }}')">
          Excluir
      </x-confirm-action>

      <x-confirm-action message="Remover o Webhook Secret salvo?" wire:click="clearSecret">
          Remover
      </x-confirm-action>

    A policy continua sendo a fonte da verdade: a ação Livewire DEVE chamar `$this->authorize()`
    (esconder o botão não é segurança; ver LivewireAdminAuthorizationAuditTest).
--}}
@props([
    'message' => 'Tem certeza? Esta ação não pode ser desfeita.',
    'variant' => 'danger',
    'outline' => true,
    'size' => 'sm',
    'can' => null,
    'arg' => null,
])

@if ($can === null || \Illuminate\Support\Facades\Gate::allows($can, $arg))
<x-btn :variant="$variant" :outline="$outline" :size="$size ?: null" wire:confirm="{{ $message }}" {{ $attributes }}>{{ $slot }}</x-btn>
@endif
