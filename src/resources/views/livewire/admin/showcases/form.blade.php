<div class="vstack gap-3">
    <x-page-header :title="$showcaseModel ? 'Editar vitrine' : 'Nova vitrine'" :subtitle="$tenant->name">
        <x-slot:actions>
            <x-btn variant="secondary" outline
                   :href="route('tenant.admin.showcases.index', ['tenant' => $tenant->slug])">
                Voltar
            </x-btn>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger mb-0">{{ session('error') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">

            <div class="col-md-8">
                <x-form-field name="name" label="Nome" required wire:model.defer="name" placeholder="Ex: Destaques, Bolos Especiais" />
            </div>

            <div class="col-md-4">
                <x-form-field name="slug" label="Slug (opcional)" wire:model.defer="slug" placeholder="ex: destaques" />
            </div>

            <div class="col-md-6">
                <x-form-select name="type" label="Tipo" required wire:model.live="type">
                    @foreach($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="col-md-6">
                <x-form-select name="display_mode" label="Modo de Exibição" required
                               help="Lista exibe em grade, Carrossel exibe em slides deslizantes."
                               wire:model.defer="display_mode">
                    <option value="list">Por Lista</option>
                    <option value="carousel">Por Slides (Carrossel)</option>
                </x-form-select>
            </div>

            <div class="col-md-3">
                @php
                    $maxItemsHelp = $type === 'all' ? 'Tipo "Todos" exibe até 100 produtos.' : null;
                @endphp
                <x-form-field type="number" name="max_items" label="Máx. itens"
                              :help="$maxItemsHelp"
                              wire:model.defer="max_items" min="1" max="50"
                              :disabled="$type === 'all'" />
            </div>

            <div class="col-md-3 d-flex align-items-end">
                <x-form-check name="is_active" id="is_active_showcase" label="Ativa" wire:model.defer="is_active" />
            </div>

            @if($type === 'category')
                <div class="col-12">
                    <x-form-select name="category_id" label="Categoria" required wire:model.defer="category_id">
                        <option value="">Selecione uma categoria</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </x-form-select>
                </div>
            @endif

            @if($type === 'custom')
                <div class="col-12">
                    <x-form-field type="group" name="selectedProducts" label="Produtos"
                                  help="Selecione os produtos que aparecerão nesta vitrine.">
                        <div class="border rounded p-3 mh-300 overflow-y-auto">
                            @forelse($products as $product)
                                <x-form-check name="selectedProducts"
                                              :id="'product_'.$product->id"
                                              :value="$product->id"
                                              :validates="false"
                                              wire:model.defer="selectedProducts">
                                    {{ $product->name }}
                                    <span class="text-body-secondary small">
                                        — R$ {{ number_format($product->price, 2, ',', '.') }}
                                    </span>
                                </x-form-check>
                            @empty
                                <div class="text-body-secondary">Nenhum produto ativo encontrado.</div>
                            @endforelse
                        </div>
                    </x-form-field>
                </div>
            @endif

            <div class="col-12 border-top pt-3 mt-2">
                <div class="text-body-secondary small mb-3">
                    <strong>Tipos de vitrine:</strong>
                    <ul class="mb-0 mt-1">
                        <li><strong>Todos os Produtos</strong> — Exibe todos os produtos ativos</li>
                        <li><strong>Destaques</strong> — Produtos marcados como destaque</li>
                        <li><strong>Mais Vendidos</strong> — Produtos com mais vendas</li>
                        <li><strong>Novidades</strong> — Produtos mais recentes</li>
                        <li><strong>Por Categoria</strong> — Produtos de uma categoria específica</li>
                        <li><strong>Personalizada</strong> — Seleção manual de produtos</li>
                    </ul>
                </div>
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>

        </form>
    </x-card>
</div>
