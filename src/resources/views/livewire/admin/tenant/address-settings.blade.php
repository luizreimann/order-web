<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Endereço</h1>
            <div class="text-muted small">Localização do seu estabelecimento</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Rua/Avenida</label>
                    <input type="text" class="form-control" wire:model="street" placeholder="Rua Exemplo">
                    @error('street') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">Número</label>
                    <input type="text" class="form-control" wire:model="number" placeholder="123">
                    @error('number') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Complemento</label>
                    <input type="text" class="form-control" wire:model="complement" placeholder="Sala 1, Bloco A...">
                    @error('complement') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">Bairro</label>
                    <input type="text" class="form-control" wire:model="neighborhood" placeholder="Centro">
                    @error('neighborhood') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-5">
                    <label class="form-label">Cidade</label>
                    <input type="text" class="form-control" wire:model="city" placeholder="São Paulo">
                    @error('city') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-3">
                    <label class="form-label">Estado</label>
                    <input type="text" class="form-control" wire:model="state" placeholder="SP" maxlength="2">
                    @error('state') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label">CEP</label>
                    <input type="text" class="form-control" wire:model="zip_code" placeholder="01234-567">
                    @error('zip_code') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Link do Google Maps</label>
                    <input type="url" class="form-control" wire:model="google_maps_url" placeholder="https://maps.google.com/...">
                    @error('google_maps_url') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="form-text">Cole o link de compartilhamento do Google Maps</div>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" wire:model="show_on_storefront" id="show_on_storefront">
                        <label class="form-check-label" for="show_on_storefront">
                            Exibir endereço na loja
                        </label>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
