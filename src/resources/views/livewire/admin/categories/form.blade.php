<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $categoryModel ? 'Editar categoria' : 'Nova categoria' }}</h1>
            <div class="text-body-secondary small">{{ $tenant->name }}</div>
        </div>

        <a class="btn btn-outline-secondary"
           href="{{ route('tenant.admin.categories.index', ['tenant' => $tenant->slug]) }}">
            Voltar
        </a>
    </div>

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">

                <div class="col-12">
                    <label class="form-label">Nome *</label>
                    <input class="form-control" wire:model.defer="name">
                    @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Slug (opcional)</label>
                    <input class="form-control" wire:model.defer="slug" placeholder="ex: bolos-caseiros">
                    @error('slug') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Se vazio, será gerado automaticamente a partir do nome.</div>
                </div>

                <div class="col-12">
                    <label class="form-label">Descrição</label>
                    <textarea class="form-control" rows="4" wire:model.defer="description"></textarea>
                    @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Ordem</label>
                    <input type="number" class="form-control" wire:model.defer="sort_order">
                    @error('sort_order') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" wire:model.defer="is_active" id="is_active_cat">
                        <label class="form-check-label" for="is_active_cat">Ativa</label>
                    </div>
                </div>

                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Salvar</button>
                </div>

            </form>
        </div>
    </div>

    @if($categoryModel && count($categoryProducts) > 0)
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0">Produtos desta Categoria</h5>
                <small class="text-muted">Arraste para reordenar</small>
            </div>
            <div class="card-body">
                <div id="productsList" class="list-group">
                    @foreach($categoryProducts as $product)
                        <div class="list-group-item d-flex align-items-center gap-3" data-product-id="{{ $product['id'] }}" style="cursor: move;">
                            <i class="fa-solid fa-grip-vertical text-muted"></i>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ $product['name'] }}</div>
                                <small class="text-muted">Ordem: {{ $product['sort_order'] }}</small>
                            </div>
                            @if($product['is_active'])
                                <span class="badge bg-success">Ativo</span>
                            @else
                                <span class="badge bg-secondary">Inativo</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const el = document.getElementById('productsList');
                if (el) {
                    const sortable = new Sortable(el, {
                        animation: 150,
                        handle: '.fa-grip-vertical',
                        ghostClass: 'bg-light',
                        onEnd: function(evt) {
                            const items = el.querySelectorAll('[data-product-id]');
                            const orderedIds = Array.from(items).map(item => item.getAttribute('data-product-id'));
                            
                            @this.call('updateProductOrder', orderedIds);
                        }
                    });
                }
            });
        </script>
        @endpush
    @endif
</div>