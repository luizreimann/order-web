<div class="vstack gap-3">
    <x-page-header :title="$categoryModel ? 'Editar categoria' : 'Nova categoria'" :subtitle="$tenant->name">
        <x-slot:actions>
            <x-btn variant="secondary" outline
                   :href="route('tenant.admin.categories.index', ['tenant' => $tenant->slug])">
                Voltar
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    <x-card>
        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <form wire:submit.prevent="save" class="row g-3">

            <div class="col-12">
                <x-form-field name="name" label="Nome" required wire:model.defer="name" />
            </div>

            <div class="col-12">
                <x-form-field name="slug" label="Slug (opcional)"
                              help="Se vazio, será gerado automaticamente a partir do nome."
                              placeholder="ex: bolos-caseiros"
                              wire:model.defer="slug" />
            </div>

            <div class="col-12">
                <x-form-field type="textarea" name="description" label="Descrição" rows="4" wire:model.defer="description" />
            </div>

            <div class="col-md-4">
                <x-form-field type="number" name="sort_order" label="Ordem" wire:model.defer="sort_order" />
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <x-form-check name="is_active" label="Ativa" wire:model.defer="is_active" />
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>

        </form>
    </x-card>

    @if($categoryModel && count($categoryProducts) > 0)
        <x-card>
            <x-slot:header>
                <x-section-title>Produtos desta Categoria</x-section-title>
                <div class="text-muted small">Arraste para reordenar</div>
            </x-slot:header>

            <div id="productsList" class="list-group">
                @foreach($categoryProducts as $product)
                    <div class="list-group-item d-flex align-items-center gap-3 cursor-move" data-product-id="{{ $product['id'] }}">
                        <i class="fa-solid fa-grip-vertical text-muted"></i>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">{{ $product['name'] }}</div>
                            <small class="text-muted">Ordem: {{ $product['sort_order'] }}</small>
                        </div>
                        <x-status-badge :active="$product['is_active']" />
                    </div>
                @endforeach
            </div>
        </x-card>

        @push('scripts')
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
