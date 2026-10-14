{{--
    <x-data-table> — única tabela padrão (E4.2): `table-responsive` > `table table-hover
    align-middle mb-0`. Põe-se dentro de `<x-card flush>`.

    Props:
      empty        true => no lugar das linhas, mostra <x-empty-state> (cabeçalho mantido)
      columns      nº de colunas (colspan do estado vazio)
      empty-message / empty-icon   texto/ícone do estado vazio
    Slots: `head` (as <th> de UMA linha de cabeçalho), default (as <tr> do corpo) e
           `emptyAction` (ação do estado vazio).
    Atributos extras vão para o <table>.

    Exemplo:
      <x-data-table :empty="$products->isEmpty()" :columns="5" empty-message="Nenhum produto.">
          <x-slot:head><th>Nome</th><th class="text-end">Ações</th></x-slot:head>
          @foreach ($products as $p) <tr>...</tr> @endforeach
      </x-data-table>
--}}
@props([
    'empty' => false,
    'columns' => 1,
    'emptyMessage' => 'Nenhum registro encontrado.',
    'emptyIcon' => null,
    'head' => null,
    'emptyAction' => null,
])

<div class="table-responsive">
    <table {{ $attributes->class(['table', 'table-hover', 'align-middle', 'mb-0']) }}>
        @if ($head !== null && ! $head->isEmpty())
            <thead>
            <tr>{{ $head }}</tr>
            </thead>
        @endif
        <tbody>
        @if ($empty)
            <tr>
                <td colspan="{{ $columns }}" class="p-0">
                    <x-empty-state :message="$emptyMessage" :icon="$emptyIcon">{{ $emptyAction }}</x-empty-state>
                </td>
            </tr>
        @else
            {{ $slot }}
        @endif
        </tbody>
    </table>
</div>
