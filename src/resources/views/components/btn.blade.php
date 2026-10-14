{{--
    <x-btn> — botão/link padrão (E4.2, D8/9.4). Sem cor fixa: `btn-primary` herda
    `--bs-primary` do tema do tenant.

    Props:
      variant  primary (padrão) | secondary | danger | link
      outline  flag: `btn-outline-{variant}` (ignorado em `link`)
      size     sm | lg
      href     renderiza `<a class="btn">` em vez de `<button>`
      type     button (padrão) | submit | reset (só `<button>`)
      loading  nome da ação Livewire (ex.: "save"): enquanto ela roda, o botão fica
               `disabled` e mostra spinner (`wire:loading` + `wire:target`).
               Ignorado quando há `href`.
    Slots: default (texto) e `icon` (ex.: <i class="fa-solid fa-plus"></i>).
    Atributos extras (wire:click, wire:confirm, data-*, class, disabled...) passam.
    Dentro de `.btn-group` o `.btn` continua filho direto (nenhum wrapper).

    Exemplos:
      <x-btn type="submit" loading="save">Salvar</x-btn>
      <x-btn variant="secondary" outline size="sm" href="{{ $url }}">Voltar</x-btn>
      <x-btn variant="danger" outline size="sm" wire:click="delete('{{ $id }}')"
             wire:confirm="Tem certeza?">Excluir</x-btn>
      <x-btn href="...">
          <x-slot:icon><i class="fa-solid fa-plus"></i></x-slot:icon>
          Novo
      </x-btn>
    Atenção (Blade): `</x-slot:nome>` precisa estar numa linha própria/seguido de quebra de
    linha; colado ao texto seguinte o Blade quebra o `@endslot`.
--}}
@props([
    'variant' => 'primary',
    'outline' => false,
    'size' => null,
    'href' => null,
    'type' => 'button',
    'loading' => null,
    'icon' => null,
])

@php
    $isLink = $variant === 'link';
    $classes = [
        'btn',
        $isLink ? 'btn-link' : ($outline ? "btn-outline-{$variant}" : "btn-{$variant}"),
        "btn-{$size}" => $size !== null,
    ];
    $hasIcon = $icon !== null && ! $icon->isEmpty();

    // Atributos montados aqui, sem @if dentro da tag (não depende de o Livewire pular
    // diretivas dentro de tags ao injetar seus marcadores de morph).
    $extra = $href !== null
        ? ['href' => $href]
        : array_filter([
            'type' => $type,
            'wire:loading.attr' => $loading !== null ? 'disabled' : null,
            'wire:target' => $loading,
        ], fn ($v) => $v !== null);
@endphp

@if ($href !== null)
    <a {{ $attributes->class($classes)->merge($extra) }}>@if ($hasIcon)<span class="me-1">{{ $icon }}</span>@endif{{ $slot }}</a>
@else
    <button {{ $attributes->class($classes)->merge($extra) }}>@if ($loading !== null)<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true" wire:loading wire:target="{{ $loading }}"></span>@endif@if ($hasIcon)<span class="me-1">{{ $icon }}</span>@endif{{ $slot }}</button>
@endif
