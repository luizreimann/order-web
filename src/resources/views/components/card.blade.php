{{--
    <x-card> — container padrão (E4.2): `card shadow-sm`, SEM margem própria (quem
    posiciona é o container: `vstack gap-3`, `col-*`).

    Props:
      flush  corpo sem `card-body` (sem padding) — para tabela/lista embutida.
      muted  `bg-light`.
      full   `h-100` (cards de mesma altura em grid).
    Slots: `header` (`card-header`), default (corpo) e `footer` (`card-footer`).
    Atributos extras (class, wire:key, id...) vão para o `div.card`.

    Exemplos:
      <x-card><x-form-field .../></x-card>
      <x-card flush><x-data-table>...</x-data-table></x-card>
      <x-card full muted>
          <x-slot:header><x-section-title>Resumo</x-section-title></x-slot:header>
          ...
          <x-slot:footer>...</x-slot:footer>
      </x-card>
--}}
@props(['flush' => false, 'muted' => false, 'full' => false, 'header' => null, 'footer' => null])

<div {{ $attributes->class(['card', 'shadow-sm', 'bg-light' => $muted, 'h-100' => $full]) }}>
    @if ($header !== null && ! $header->isEmpty())
        <div class="card-header">{{ $header }}</div>
    @endif
    @if ($flush)
        {{ $slot }}
    @else
        <div class="card-body">{{ $slot }}</div>
    @endif
    @if ($footer !== null && ! $footer->isEmpty())
        <div class="card-footer">{{ $footer }}</div>
    @endif
</div>
