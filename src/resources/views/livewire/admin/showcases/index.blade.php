<div class="vstack gap-3">
    <x-page-header title="Vitrines" subtitle="Organize a exibição dos produtos na home">
        <x-slot:actions>
            @if($canCreate)
                <x-btn :href="route('tenant.admin.showcases.create', ['tenant' => $tenant->slug])">
                    Nova vitrine
                </x-btn>
            @else
                <x-btn variant="secondary" disabled title="Limite de {{ \App\Models\Showcase::MAX_SHOWCASES_PER_TENANT }} vitrines atingido">
                    Nova vitrine
                </x-btn>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if(session('status'))
        <div class="alert alert-success alert-dismissible fade show mb-0" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show mb-0" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card flush>
        @if($showcases->isEmpty())
            <x-empty-state message="Nenhuma vitrine cadastrada." />
        @else
            <div class="list-group list-group-flush" id="showcaseList">
                @foreach($showcases as $showcase)
                    <div class="list-group-item d-flex align-items-center gap-3" data-id="{{ $showcase->id }}">
                        <div class="drag-handle cursor-grab text-body-secondary">
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
                            <x-status-badge :active="$showcase->is_active" :label="$showcase->is_active ? 'Ativa' : 'Inativa'" />
                        </div>

                        <div class="btn-group">
                            <x-btn outline size="sm"
                                   :href="route('tenant.admin.showcases.edit', ['tenant' => $tenant->slug, 'showcaseId' => $showcase->id])">
                                Editar
                            </x-btn>

                            @can('update', $showcase)
                            <x-btn variant="secondary" outline size="sm"
                                   wire:click="toggleActive('{{ $showcase->id }}')">
                                {{ $showcase->is_active ? 'Desativar' : 'Ativar' }}
                            </x-btn>
                            @endcan

                            @can('delete', $showcase)
                            <x-btn variant="danger" outline size="sm"
                                   wire:click="delete('{{ $showcase->id }}')"
                                   wire:confirm="Excluir esta vitrine?">
                                Excluir
                            </x-btn>
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-card>

    <div class="text-body-secondary small">
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

    // Sortable vem do bundle admin (resources/js/admin.js). Em carga inicial o
    // módulo roda antes de DOMContentLoaded, então aguardamos esse evento.
    if (typeof Sortable === 'undefined') {
        document.addEventListener('DOMContentLoaded', initSortable, { once: true });
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
