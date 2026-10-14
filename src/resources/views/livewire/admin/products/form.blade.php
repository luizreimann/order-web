<div class="vstack gap-3">
    <x-page-header :title="$productModel ? 'Editar produto' : 'Novo produto'" :subtitle="$tenant->name">
        <x-slot:actions>
            <x-btn variant="secondary" outline
                   :href="route('tenant.admin.products.index', ['tenant' => $tenant->slug])">
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
                <x-form-field type="file" name="image" label="Imagem do produto"
                              help="PNG/JPG/WebP, até 3MB."
                              wire:model="image" accept="image/*">
                    <x-slot:after>
                        @if($this->imageUrl)
                            <div class="mt-3 d-flex align-items-center gap-3">
                                <img src="{{ $this->imageUrl }}"
                                    alt="Imagem do produto"
                                    class="rounded border size-72 object-fit-cover">

                                @if($productModel && data_get($productModel->meta, 'image_path'))
                                    <x-btn variant="danger" outline size="sm"
                                           wire:click="removeImage"
                                           wire:confirm="Remover a imagem deste produto?">
                                        Remover imagem
                                    </x-btn>
                                @endif
                            </div>
                        @endif
                    </x-slot:after>
                </x-form-field>
            </div>

            <div class="col-12">
                <x-form-field name="name" label="Nome" required wire:model.defer="name" />
            </div>

            <div class="col-md-4">
                <x-form-field name="sku" label="SKU" wire:model.defer="sku" />
            </div>

            <div class="col-md-4">
                <x-form-field name="price" label="Preço (R$)" required placeholder="19,90" wire:model.defer="price" />
            </div>

            <div class="col-md-4">
                <x-form-field name="compare_at" label="Preço riscado (R$)" placeholder="29,90" wire:model.defer="compare_at" />
            </div>

            <div class="col-12">
                <x-form-field type="textarea" name="description" label="Descrição" rows="5" wire:model.defer="description" />
            </div>

            <div class="col-md-4">
                <x-form-field type="number" name="sort_order" label="Ordenação" wire:model.defer="sort_order" />
            </div>

            <div class="col-md-4">
                <x-form-field type="number" name="min_days_notice" label="Antecedência mínima (dias)" wire:model.defer="min_days_notice" />
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <x-form-check name="requires_scheduling" label="Exigir data/hora no pedido" wire:model.defer="requires_scheduling" />
            </div>

            <div class="col-md-4">
                <x-form-check name="track_inventory" label="Controlar estoque"
                              help="Desligado = sob encomenda, sem limite de quantidade."
                              wire:model.live="track_inventory" />
            </div>

            @if($track_inventory)
                <div class="col-md-4">
                    <x-form-field type="number" name="stock_quantity" label="Quantidade em estoque" min="0" wire:model.defer="stock_quantity" />
                </div>
            @endif

            <div class="col-md-4">
                <x-form-check name="is_active" label="Ativo" wire:model.defer="is_active" />
            </div>

            <div class="col-md-4">
                <x-form-check name="is_featured" label="Destaque" wire:model.defer="is_featured" />
            </div>

            <div class="col-12">
                <x-form-field type="group" name="category_ids" label="Categorias">
                    @if($categories->isEmpty())
                        <div class="text-body-secondary small">
                            Nenhuma categoria cadastrada. Crie uma em <a href="{{ route('tenant.admin.categories.index', ['tenant' => $tenant->slug]) }}">Categorias</a>.
                        </div>
                    @else
                        <div class="row g-2">
                            @foreach($categories as $cat)
                                <div class="col-md-6">
                                    <x-form-check name="category_ids"
                                                  :id="'cat_'.$cat->id"
                                                  :value="$cat->id"
                                                  :label="$cat->name"
                                                  :validates="false"
                                                  wire:model.defer="category_ids" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-form-field>
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
