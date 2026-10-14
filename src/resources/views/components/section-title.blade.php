{{--
    <x-section-title> — único tratamento de título de seção (E4.1/D8): `h2.h6.fw-semibold`,
    sem `border-bottom`, sem margem própria (o container espaça).
    Com `sub`, vira o subtítulo de bloco (D8/9.2): `h3.h6.text-muted`.
    Hierarquia: h1 (página) → h2 (seção) → h3 (subtítulo).

    Exemplos:
      <x-section-title>Dados do produto</x-section-title>
      <x-section-title sub>Agendamento</x-section-title>
--}}
@props(['sub' => false])

@if ($sub)
    <h3 {{ $attributes->class(['h6', 'text-muted', 'mb-0']) }}>{{ $slot }}</h3>
@else
    <h2 {{ $attributes->class(['h6', 'fw-semibold', 'mb-0']) }}>{{ $slot }}</h2>
@endif
