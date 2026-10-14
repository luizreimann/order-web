<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Personalização</h1>
            <div class="text-muted small">Logo, cores e fonte do tenant</div>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form wire:submit.prevent="save" class="row g-3">
                        
                        <!-- Identidade Visual -->
                        <div class="col-12">
                            <h5 class="mb-3 pb-2 border-bottom">Identidade Visual</h5>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Nome da loja</label>
                            <input class="form-control" wire:model.defer="name">
                            @error('name') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Nome exibido no header quando não há logo.</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Logo (PNG/JPG)</label>
                            <input type="file" class="form-control" wire:model="logo" accept="image/*">
                            @error('logo') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Máx. 2MB. Exibido no header da loja no lugar do nome.</div>
                            @if($this->logoUrl)
                                <div class="mt-2 p-2 bg-light rounded">
                                    <img src="{{ $this->logoUrl }}" alt="Logo" class="border rounded bg-white p-1 logo-preview">
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" wire:click="removeLogo">Remover</button>
                                </div>
                            @endif
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Favicon (ICO/PNG)</label>
                            <input type="file" class="form-control" wire:model="favicon" accept=".ico,.png">
                            @error('favicon') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Máx. 512KB. Ícone exibido na aba do navegador.</div>
                            @if($this->faviconUrl)
                                <div class="mt-2 p-2 bg-light rounded">
                                    <img src="{{ $this->faviconUrl }}" alt="Favicon" class="border rounded bg-white p-1 favicon-preview">
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" wire:click="removeFavicon">Remover</button>
                                </div>
                            @endif
                        </div>

                        <!-- Imagens -->
                        <div class="col-12 mt-4">
                            <h5 class="mb-3 pb-2 border-bottom">Imagens</h5>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Banner/Capa (JPG/PNG)</label>
                            <input type="file" class="form-control" wire:model="banner" accept="image/*">
                            @error('banner') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Máx. 5MB. Recomendado: 1200x400px. Exibido no topo da homepage.</div>
                            @if($this->bannerUrl)
                                <div class="mt-2 p-2 bg-light rounded">
                                    <img src="{{ $this->bannerUrl }}" alt="Banner" class="border rounded banner-preview-sm">
                                    <button type="button" class="btn btn-sm btn-outline-danger ms-2" wire:click="removeBanner">Remover</button>
                                </div>
                            @endif
                        </div>

                        <!-- Cores -->
                        <div class="col-12 mt-4">
                            <h5 class="mb-3 pb-2 border-bottom">Cores</h5>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cor primária</label>
                            <div class="d-flex gap-2">
                                <input type="color" class="form-control form-control-color w-3-25rem" wire:model.defer="primary_color">
                                <input class="form-control" wire:model.defer="primary_color" placeholder="#0d6efd">
                            </div>
                            @error('primary_color') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Botões e links principais.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cor secundária</label>
                            <div class="d-flex gap-2">
                                <input type="color" class="form-control form-control-color w-3-25rem" wire:model.defer="secondary_color">
                                <input class="form-control" wire:model.defer="secondary_color" placeholder="#6c757d">
                            </div>
                            @error('secondary_color') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Elementos secundários.</div>
                        </div>

                        <!-- Tipografia -->
                        <div class="col-12 mt-4">
                            <h5 class="mb-3 pb-2 border-bottom">Tipografia</h5>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Fonte</label>
                            <select class="form-select" wire:model.defer="font_family">
                                @foreach($fontOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('font_family') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            <div class="form-text">Família de fonte aplicada em toda a loja.</div>
                        </div>

                        <div class="col-12 mt-4">
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-save me-1"></i> Salvar Personalização
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="mb-3">Preview do Header</h6>

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
                            <button class="btn btn-sm" style="background-color: {{ $previewPrimary }}; color: white; border: none;">
                                Primário
                            </button>
                            <button class="btn btn-sm" style="background-color: {{ $previewSecondary }}; color: white; border: none;">
                                Secundário
                            </button>
                        </div>

                        <div>
                            <a href="#" style="color: {{ $previewPrimary }}; text-decoration: none;">
                                <i class="fa-solid fa-link me-1"></i> Link de exemplo
                            </a>
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 small mb-0">
                        <i class="fa-solid fa-info-circle me-1"></i>
                        <strong>Dica:</strong> O logo será exibido no header da loja no lugar do nome. Se não houver logo, o nome será exibido.
                    </div>
                </div>
            </div>

            @if($this->bannerUrl)
            <div class="card shadow-sm mt-3">
                <div class="card-body">
                    <h6 class="mb-3">Preview do Banner</h6>
                    <img src="{{ $this->bannerUrl }}" alt="Banner" class="img-fluid rounded border banner-preview-lg">
                </div>
            </div>
            @endif
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