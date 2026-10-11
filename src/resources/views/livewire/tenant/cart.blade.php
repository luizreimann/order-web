<div class="container py-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Carrinho</h1>
            <div class="text-muted small">Revise seus itens</div>
        </div>

        <a class="btn btn-outline-secondary" href="{{ route('tenant.home', ['tenant' => $tenant->slug]) }}">
            Continuar comprando
        </a>
    </div>

    @error('stock')
        <div class="alert alert-warning">{{ $message }}</div>
    @enderror

    @if (count($lines) === 0)
        <div class="alert alert-info">
            Seu carrinho está vazio.
        </div>
    @else
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    @foreach($lines as $line)
                        <div class="d-flex align-items-start justify-content-between gap-3 border-bottom pb-3">
                            <div class="d-flex gap-3">
                                @if($line['product']['image_url'])
                                    <img src="{{ $line['product']['image_url'] }}" alt="{{ $line['product']['name'] }}" style="width: 64px; height: 64px; object-fit: cover;" class="rounded">
                                @else
                                    <div class="rounded bg-body-tertiary" style="width: 64px; height: 64px;"></div>
                                @endif

                                <div>
                                    <div class="fw-semibold">{{ $line['product']['name'] }}</div>
                                    <div class="text-muted small">
                                        R$ {{ number_format($line['product']['price_cents'] / 100, 2, ',', '.') }}
                                    </div>

                                    @if($line['product']['available'] !== null && $line['qty'] > $line['product']['available'])
                                        <div class="text-warning-emphasis small">
                                            {{ $line['product']['available'] === 0 ? 'Esgotado' : 'Disponível: '.$line['product']['available'] }} — ajuste a quantidade.
                                        </div>
                                    @endif

                                    <div class="mt-2 d-flex align-items-center gap-2">
                                        <div class="btn-group" role="group" aria-label="Quantidade">
                                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="decrement('{{ $line['product_id'] }}')">-</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" disabled>
                                                {{ $line['qty'] }}
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" wire:click="increment('{{ $line['product_id'] }}')">+</button>
                                        </div>

                                        <button type="button" class="btn btn-link text-danger btn-sm" wire:click="remove('{{ $line['product_id'] }}')">
                                            Remover
                                        </button>
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
                    <button type="button" class="btn btn-outline-danger" wire:click="clear">
                        Limpar carrinho
                    </button>

                    <div class="text-end">
                        <div class="text-muted small">Subtotal</div>
                        <div class="h5 mb-0">R$ {{ number_format($this->subtotalCents / 100, 2, ',', '.') }}</div>
                    </div>
                </div>

                <div class="d-grid mt-3">
                    <a class="btn btn-primary" href="{{ route('tenant.checkout', ['tenant' => $tenant->slug]) }}">
                        Ir para o checkout
                    </a>
                </div>
            </div>
        </div>
    @endif
</div>
