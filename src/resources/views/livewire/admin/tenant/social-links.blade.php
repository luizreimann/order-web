<div class="vstack gap-3">
    <x-page-header title="Redes Sociais" subtitle="Links para suas redes sociais" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            {{-- Labels com ícone: <x-form-field label> só aceita texto, então o label fica à mão
                 (com `for`) e o controle sem label recebe o `id` explicitamente. --}}
            <div class="col-md-6">
                <label class="form-label" for="instagram">
                    <i class="fa-brands fa-instagram me-1"></i> Instagram
                </label>
                <x-form-field type="url" name="instagram" id="instagram" wire:model="instagram" placeholder="https://instagram.com/sualoja" />
            </div>

            <div class="col-md-6">
                <label class="form-label" for="facebook">
                    <i class="fa-brands fa-facebook me-1"></i> Facebook
                </label>
                <x-form-field type="url" name="facebook" id="facebook" wire:model="facebook" placeholder="https://facebook.com/sualoja" />
            </div>

            <div class="col-md-6">
                <label class="form-label" for="tiktok">
                    <i class="fa-brands fa-tiktok me-1"></i> TikTok
                </label>
                <x-form-field type="url" name="tiktok" id="tiktok" wire:model="tiktok" placeholder="https://tiktok.com/@sualoja" />
            </div>

            <div class="col-md-6">
                <label class="form-label" for="youtube">
                    <i class="fa-brands fa-youtube me-1"></i> YouTube
                </label>
                <x-form-field type="url" name="youtube" id="youtube" wire:model="youtube" placeholder="https://youtube.com/@sualoja" />
            </div>

            <div class="col-12">
                <div class="form-text">
                    Os ícones das redes sociais serão exibidos no rodapé da loja.
                </div>
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
