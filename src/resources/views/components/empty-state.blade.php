{{--
    <x-empty-state> — estado vazio de listagens (E4.2): `text-center py-5 text-muted`.

    Props:  message (obrigatório), icon (classes Font Awesome, ex.: "fa-solid fa-box-open").
    Slot:   default = ação opcional (ex.: <x-btn>) abaixo da mensagem.

    Exemplos:
      <x-empty-state message="Nenhum produto encontrado." />
      <x-empty-state icon="fa-solid fa-box-open" message="Sem produtos.">
          <x-btn href="{{ $url }}">Novo produto</x-btn>
      </x-empty-state>
--}}
@props(['message', 'icon' => null])

<div {{ $attributes->class(['text-center', 'py-5', 'text-muted']) }}>
    @if ($icon !== null)
        <div class="fs-1 mb-2"><i class="{{ $icon }}" aria-hidden="true"></i></div>
    @endif
    <div>{{ $message }}</div>
    @if (trim((string) $slot) !== '')
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
