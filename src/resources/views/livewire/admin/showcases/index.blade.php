<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Vitrines</h1>
            <div class="text-body-secondary small">Organize a exibição dos produtos na home</div>
        </div>

        @if($canCreate)
            <a class="btn btn-primary"
               href="{{ route('tenant.admin.showcases.create', ['tenant' => $tenant->slug]) }}">
                Nova vitrine
            </a>
        @else
            <button class="btn btn-secondary" disabled title="Limite de {{ \App\Models\Showcase::MAX_SHOWCASES_PER_TENANT }} vitrines atingido">
                Nova vitrine
            </button>
        @endif
    </div>

    @if(session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            @if($showcases->isEmpty())
                <div class="text-center py-5 text-body-secondary">
                    Nenhuma vitrine cadastrada.
                </div>
            @else
                <div class="list-group list-group-flush" id="showcaseList">
                    @foreach($showcases as $showcase)
                        <div class="list-group-item d-flex align-items-center gap-3" data-id="{{ $showcase->id }}">
                            <div class="drag-handle cursor-grab text-body-secondary" style="cursor: grab;">
                                <i class="fa-solid fa-grip-vertical"></i>
                            </div>

                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $showcase->name }}</div>
                                <div class="text-body-secondary small">
                                    {{ $types[$showcase->type] ?? $showcase->type }}
                                    @if($showcase->type === 'category' && $showcase->category)
                                        — {{ $showcase->category->name }}
                                    @endif
                                    @if($showcase->type !== 'all')
                                        <span class="ms-2">Máx: {{ $showcase->max_items }} itens</span>
                                    @endif
                                </div>
                            </div>

                            <div>
                                @if($showcase->is_active)
                                    <span class="badge text-bg-success">Ativa</span>
                                @else
                                    <span class="badge text-bg-secondary">Inativa</span>
                                @endif
                            </div>

                            <div class="btn-group">
                                <a class="btn btn-outline-primary btn-sm"
                                   href="{{ route('tenant.admin.showcases.edit', ['tenant' => $tenant->slug, 'showcaseId' => $showcase->id]) }}">
                                    Editar
                                </a>

                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        wire:click="toggleActive('{{ $showcase->id }}')">
                                    {{ $showcase->is_active ? 'Desativar' : 'Ativar' }}
                                </button>

                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        wire:click="delete('{{ $showcase->id }}')"
                                        wire:confirm="Excluir esta vitrine?">
                                    Excluir
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="text-body-secondary small mt-2">
        {{ $showcases->count() }} de {{ \App\Models\Showcase::MAX_SHOWCASES_PER_TENANT }} vitrines
    </div>
</div>

@script
<script>
    let sortableInstance = null;

    function initSortable() {
        const el = document.getElementById('showcaseList');
        if (!el) return;

        if (sortableInstance) {
            sortableInstance.destroy();
            sortableInstance = null;
        }

        sortableInstance = Sortable.create(el, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: function() {
                const ids = Array.from(el.children).map(item => item.dataset.id);
                $wire.call('updateOrder', ids);
            }
        });
    }

    // Carregar SortableJS dinamicamente
    if (typeof Sortable === 'undefined') {
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
        script.onload = initSortable;
        document.head.appendChild(script);
    } else {
        initSortable();
    }

    // Re-inicializar após updates do Livewire
    $wire.on('showcasesUpdated', () => {
        setTimeout(initSortable, 50);
    });

    // Também re-inicializar quando o DOM for atualizado
    Livewire.hook('morph.updated', ({ el }) => {
        if (el.id === 'showcaseList' || el.querySelector('#showcaseList')) {
            setTimeout(initSortable, 50);
        }
    });
</script>
@endscript
