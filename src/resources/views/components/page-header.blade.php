{{--
    <x-page-header> — cabeçalho de página (E4.2): `h1.h4` + subtítulo opcional e ações
    alinhadas à direita. Sem margem própria (use `vstack gap-3` no container).

    Props:  title (obrigatório), subtitle.
    Slot:   `actions` — botões/links; ficam alinhados à direita, lado a lado, sempre
            via <x-btn> (a ação primária é a última/mais à direita).

    Exemplo:
      <x-page-header title="Produtos" subtitle="Gerencie o catálogo do tenant.">
          <x-slot:actions>
              <x-btn href="{{ route('...') }}">Novo produto</x-btn>
          </x-slot:actions>
      </x-page-header>
--}}
@props(['title', 'subtitle' => null, 'actions' => null])

<div {{ $attributes->class(['d-flex', 'align-items-center', 'justify-content-between', 'gap-2']) }}>
    <div>
        <h1 class="h4 mb-0">{{ $title }}</h1>
        @if ($subtitle !== null)
            <div class="text-muted small">{{ $subtitle }}</div>
        @endif
    </div>

    @if ($actions !== null && ! $actions->isEmpty())
        <div class="d-flex align-items-center gap-2 flex-shrink-0">{{ $actions }}</div>
    @endif
</div>
