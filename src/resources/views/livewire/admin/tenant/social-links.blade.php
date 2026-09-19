<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Redes Sociais</h1>
            <div class="text-muted small">Links para suas redes sociais</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">
                        <i class="fa-brands fa-instagram me-1"></i> Instagram
                    </label>
                    <input type="url" class="form-control" wire:model="instagram" placeholder="https://instagram.com/sualoja">
                    @error('instagram') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        <i class="fa-brands fa-facebook me-1"></i> Facebook
                    </label>
                    <input type="url" class="form-control" wire:model="facebook" placeholder="https://facebook.com/sualoja">
                    @error('facebook') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        <i class="fa-brands fa-tiktok me-1"></i> TikTok
                    </label>
                    <input type="url" class="form-control" wire:model="tiktok" placeholder="https://tiktok.com/@sualoja">
                    @error('tiktok') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">
                        <i class="fa-brands fa-youtube me-1"></i> YouTube
                    </label>
                    <input type="url" class="form-control" wire:model="youtube" placeholder="https://youtube.com/@sualoja">
                    @error('youtube') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                </div>

                <div class="col-12">
                    <div class="form-text">
                        Os ícones das redes sociais serão exibidos no rodapé da loja.
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
