<div class="vstack gap-3">
    <x-page-header title="Personalização" subtitle="Logo, cores e fonte do tenant" />

    @if (session('status'))
        <div class="alert alert-success mb-0">
            {{ session('status') }}
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <x-card>
                <form wire:submit.prevent="save" class="row g-3">

                    <!-- Identidade Visual -->
                    <div class="col-12">
                        <x-section-title>Identidade Visual</x-section-title>
                    </div>

                    <div class="col-12">
                        <x-form-field name="name" label="Nome da loja"
                                      help="Nome exibido no header quando não há logo."
                                      wire:model.defer="name" />
                    </div>

                    <div class="col-12">
                        <x-form-field type="file" name="logo" label="Logo (PNG/JPG)"
                                      help="Máx. 2MB. Exibido no header da loja no lugar do nome."
                                      wire:model="logo" accept="image/*" />
                        @if($this->logoUrl)
                            <div class="mt-2 p-2 bg-light rounded d-flex align-items-center gap-2">
                                <img src="{{ $this->logoUrl }}" alt="Logo" class="border rounded bg-white p-1 logo-preview">
                                <x-btn variant="danger" outline size="sm" wire:click="removeLogo">Remover</x-btn>
                            </div>
                        @endif
                    </div>

                    <div class="col-12">
                        <x-form-field type="file" name="favicon" label="Favicon (ICO/PNG)"
                                      help="Máx. 512KB. Ícone exibido na aba do navegador."
                                      wire:model="favicon" accept=".ico,.png" />
                        @if($this->faviconUrl)
                            <div class="mt-2 p-2 bg-light rounded d-flex align-items-center gap-2">
                                <img src="{{ $this->faviconUrl }}" alt="Favicon" class="border rounded bg-white p-1 favicon-preview">
                                <x-btn variant="danger" outline size="sm" wire:click="removeFavicon">Remover</x-btn>
                            </div>
                        @endif
                    </div>

                    <!-- Imagens -->
                    <div class="col-12 mt-4">
                        <x-section-title>Imagens</x-section-title>
                    </div>

                    <div class="col-12">
                        <x-form-field type="file" name="banner" label="Banner/Capa (JPG/PNG)"
                                      help="Máx. 5MB. Recomendado: 1200x400px. Exibido no topo da homepage."
                                      wire:model="banner" accept="image/*" />
                        @if($this->bannerUrl)
                            <div class="mt-2 p-2 bg-light rounded d-flex align-items-center gap-2">
                                <img src="{{ $this->bannerUrl }}" alt="Banner" class="border rounded banner-preview-sm">
                                <x-btn variant="danger" outline size="sm" wire:click="removeBanner">Remover</x-btn>
                            </div>
                        @endif
                    </div>

                    <!-- Cores -->
                    <div class="col-12 mt-4">
                        <x-section-title>Cores</x-section-title>
                    </div>

                    {{-- Seletor de cor + campo de texto lado a lado: o feedback não pode ficar dentro do
                         flex, então usa-se `type="group"` (label, erro `d-block` e ajuda do componente) e
                         o `is-invalid` do campo de texto é derivado de $errors à mão. --}}
                    <div class="col-md-6">
                        <x-form-field type="group" name="primary_color" label="Cor primária" help="Botões e links principais.">
                            <div class="d-flex gap-2">
                                <input type="color" class="form-control form-control-color w-3-25rem" wire:model.defer="primary_color">
                                <input id="primary_color" @class(['form-control', 'is-invalid' => $errors->has('primary_color')]) wire:model.defer="primary_color" placeholder="#0d6efd">
                            </div>
                        </x-form-field>
                    </div>

                    <div class="col-md-6">
                        <x-form-field type="group" name="secondary_color" label="Cor secundária" help="Elementos secundários.">
                            <div class="d-flex gap-2">
                                <input type="color" class="form-control form-control-color w-3-25rem" wire:model.defer="secondary_color">
                                <input id="secondary_color" @class(['form-control', 'is-invalid' => $errors->has('secondary_color')]) wire:model.defer="secondary_color" placeholder="#6c757d">
                            </div>
                        </x-form-field>
                    </div>

                    <!-- Tipografia -->
                    <div class="col-12 mt-4">
                        <x-section-title>Tipografia</x-section-title>
                    </div>

                    <div class="col-12">
                        <x-form-select name="font_family" label="Fonte"
                                       help="Família de fonte aplicada em toda a loja."
                                       wire:model.defer="font_family">
                            @foreach($fontOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-form-select>
                    </div>

                    <div class="col-12 mt-4">
                        <x-btn type="submit" loading="save">
                            <x-slot:icon><i class="fa-solid fa-save"></i></x-slot:icon>
                            Salvar Personalização
                        </x-btn>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-5">
            <div class="vstack gap-3">
                <x-card>
                    <div class="vstack gap-3">
                        <x-section-title>Preview do Header</x-section-title>

                        {{-- E5.1: as props são controladas pelo cliente (wire:model); só valores da allow-list chegam ao CSS --}}
                        @php
                            $previewFont = \App\Support\TenantTheme::fontStack($font_family);
                            $previewPrimary = \App\Support\TenantTheme::primary($primary_color);
                            $previewSecondary = \App\Support\TenantTheme::secondary($secondary_color);
                        @endphp
                        <div class="p-3 rounded border bg-white"
                             style="
                                font-family: {{ $previewFont }};
                                --tenant-primary: {{ $previewPrimary }};
                                --tenant-secondary: {{ $previewSecondary }};
                             ">
                            <div class="mb-3">
                                @if($this->logoUrl)
                                    <img src="{{ $this->logoUrl }}" alt="Logo" class="logo-header">
                                @else
                                    <h4 class="mb-0">{{ $name }}</h4>
                                @endif
                            </div>

                            <div class="d-flex gap-2 mb-3">
                                <button type="button" class="btn btn-sm tenant-preview-btn-primary">
                                    Primário
                                </button>
                                <button type="button" class="btn btn-sm tenant-preview-btn-secondary">
                                    Secundário
                                </button>
                            </div>

                            <div>
                                <a href="#" class="tenant-preview-link">
                                    <i class="fa-solid fa-link me-1"></i> Link de exemplo
                                </a>
                            </div>
                        </div>

                        <div class="alert alert-info small mb-0">
                            <i class="fa-solid fa-info-circle me-1"></i>
                            <strong>Dica:</strong> O logo será exibido no header da loja no lugar do nome. Se não houver logo, o nome será exibido.
                        </div>
                    </div>
                </x-card>

                @if($this->bannerUrl)
                    <x-card>
                        <div class="vstack gap-3">
                            <x-section-title>Preview do Banner</x-section-title>
                            <img src="{{ $this->bannerUrl }}" alt="Banner" class="img-fluid rounded border banner-preview-lg">
                        </div>
                    </x-card>
                @endif
            </div>
        </div>
    </div>
</div>
<script>
  document.addEventListener('livewire:init', () => {
    Livewire.on('tenant-theme-saved', () => {
      window.location.reload();
    });
  });
</script>
