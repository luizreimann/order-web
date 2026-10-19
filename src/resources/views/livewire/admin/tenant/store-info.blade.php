<div class="vstack gap-3">
    <x-page-header title="Informações da Loja" subtitle="Descrição e informações sobre seu negócio" />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save" class="row g-3">
            <div class="col-12">
                <x-form-field name="short_description" label="Descrição curta"
                              help="Máximo 160 caracteres. Usada em resultados de busca e compartilhamentos."
                              wire:model="short_description" maxlength="160"
                              placeholder="Descrição breve para SEO e redes sociais" />
            </div>

            <div class="col-12">
                <x-form-field type="textarea" name="full_description" label="Sobre a loja"
                              help="Máximo 2000 caracteres."
                              wire:model="full_description" rows="6" maxlength="2000"
                              placeholder="Conte a história do seu negócio, diferenciais, especialidades..." />
            </div>

            <div class="col-12">
                <x-form-check switch name="show_about" label='Exibir seção "Sobre" na loja' wire:model="show_about" />
            </div>

            <div class="col-12">
                <x-btn type="submit" loading="save">Salvar</x-btn>
            </div>
        </form>
    </x-card>
</div>
