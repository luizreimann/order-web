<div class="container py-4">
    <div class="vstack gap-3">
        <x-page-header title="Carrinho" subtitle="Revise seus itens">
            <x-slot:actions>
                <x-btn variant="secondary" outline :href="route('tenant.home', ['tenant' => $tenant->slug])">
                    Continuar comprando
                </x-btn>
            </x-slot:actions>
        </x-page-header>

        @error('stock')
            <div class="alert alert-warning mb-0">{{ $message }}</div>
        @enderror

        @if (count($this->lines) === 0)
            <x-empty-state message="Seu carrinho está vazio." />
        @else
            <x-card>
                <div class="d-flex flex-column gap-3">
                    @foreach($this->lines as $line)
                        <div class="d-flex align-items-start justify-content-between gap-3 border-bottom pb-3">
                            <div class="d-flex gap-3">
                                @if($line['product']->imageUrl())
                                    <img src="{{ $line['product']->imageUrl() }}" alt="{{ $line['product']->name }}" class="rounded size-64 object-fit-cover">
                                @else
                                    <div class="rounded bg-body-tertiary size-64"></div>
                                @endif

                                <div>
                                    <div class="fw-semibold">{{ $line['product']->name }}</div>
                                    <div class="text-muted small">
                                        R$ {{ number_format($line['unit_cents'] / 100, 2, ',', '.') }}
                                    </div>

                                    @if($line['available'] !== null && $line['qty'] > $line['available'])
                                        <div class="text-warning-emphasis small">
                                            {{ $line['available'] === 0 ? 'Esgotado' : 'Disponível: '.$line['available'] }} — ajuste a quantidade.
                                        </div>
                                    @endif

                                    <div class="mt-2 d-flex align-items-center gap-2">
                                        <div class="btn-group" role="group" aria-label="Quantidade">
                                            <x-btn variant="secondary" outline size="sm" wire:click="decrement('{{ $line['product_id'] }}')">-</x-btn>
                                            <x-btn variant="secondary" outline size="sm" disabled>
                                                {{ $line['qty'] }}
                                            </x-btn>
                                            <x-btn variant="secondary" outline size="sm" wire:click="increment('{{ $line['product_id'] }}')">+</x-btn>
                                        </div>

                                        <x-btn variant="link" size="sm" class="text-danger" wire:click="remove('{{ $line['product_id'] }}')">
                                            Remover
                                        </x-btn>
                                    </div>
                                </div>
                            </div>

                            <div class="text-end">
                                <div class="fw-semibold">
                                    R$ {{ number_format($line['total_cents'] / 100, 2, ',', '.') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="d-flex align-items-center justify-content-between mt-3">
                    <x-btn variant="danger" outline wire:click="clear">
                        Limpar carrinho
                    </x-btn>

                    <div class="text-end">
                        <div class="text-muted small">Subtotal</div>
                        <div class="h5 mb-0">R$ {{ number_format($this->subtotalCents / 100, 2, ',', '.') }}</div>
                    </div>
                </div>

                <div class="d-grid mt-3">
                    <x-btn :href="route('tenant.checkout', ['tenant' => $tenant->slug])">
                        Ir para o checkout
                    </x-btn>
                </div>
            </x-card>
        @endif
    </div>
</div>
