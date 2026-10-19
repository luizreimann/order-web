{{--
    Menu do painel admin (E4.3). Renderiza AdminNavigation; NÃO liste itens aqui:
    para adicionar/alterar um item edite App\Support\AdminNavigation::definition().

    <x-admin-nav />                    sidebar (desktop)
    <x-admin-nav mode="offcanvas" />   offcanvas (mobile): ids com sufixo "Mobile" + data-bs-dismiss

    Sem @if/@foreach dentro de tags (marcadores do Livewire quebram atributos): o que varia
    por modo chega pronto de App\View\Components\AdminNav ($idSuffix, $dismissAttr).
--}}
<nav class="nav flex-column gap-1 admin-nav">
    @foreach ($entries as $entry)
        @if ($entry instanceof \App\Support\AdminNavSection)
            @php($open = $entry->isActive(request()))
            <div class="accordion admin-nav-accordion" id="menu{{ $entry->key }}{{ $idSuffix }}">
                <div class="accordion-item">
                    <button class="accordion-button {{ $open ? 'has-active' : 'collapsed' }}"
                            type="button" data-bs-toggle="collapse" data-bs-target="#collapse{{ $entry->key }}{{ $idSuffix }}">
                        <i class="{{ $entry->icon }} me-2"></i> {{ $entry->label }}
                    </button>
                    <div id="collapse{{ $entry->key }}{{ $idSuffix }}" class="accordion-collapse collapse {{ $open ? 'show' : '' }}" data-bs-parent="#menu{{ $entry->key }}{{ $idSuffix }}">
                        <div class="accordion-body nav flex-column gap-1">
                            @foreach ($entry->items as $item)
                                <a class="nav-link {{ $item->isActive(request()) ? 'active' : '' }}"
                                   href="{{ $item->url($tenant) }}"{!! $dismissAttr !!}>
                                    <i class="{{ $item->icon }} me-2"></i> {{ $item->label }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @else
            <a class="nav-link {{ $entry->isActive(request()) ? 'active' : '' }}"
               href="{{ $entry->url($tenant) }}"{!! $dismissAttr !!}>
                <i class="{{ $entry->icon }} me-2"></i> {{ $entry->label }}
            </a>
            @if ($entry->dividerAfter)
                <div class="admin-nav-divider"></div>
            @endif
        @endif
    @endforeach
</nav>
