<div>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-0">Horário de Funcionamento</h1>
            <div class="text-muted small">Configure quando sua loja está aberta</div>
        </div>

        @if($enabled && $auto_close)
            <button
                type="button"
                class="btn {{ $paused ? 'btn-success' : 'btn-warning' }}"
                wire:click="togglePause"
            >
                {{ $paused ? 'Reabrir Loja' : 'Pausar Pedidos' }}
            </button>
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body">
            <form wire:submit.prevent="save">
                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" wire:model.live="enabled" id="enabled">
                        <label class="form-check-label" for="enabled">
                            Habilitar controle de horário
                        </label>
                    </div>
                    <div class="form-text">
                        Se desabilitado, a loja será considerada sempre aberta.
                    </div>
                </div>

                @if($enabled)
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Fuso horário</label>
                            <select class="form-select" wire:model="timezone">
                                <option value="America/Sao_Paulo">São Paulo (GMT-3)</option>
                                <option value="America/Manaus">Manaus (GMT-4)</option>
                                <option value="America/Cuiaba">Cuiabá (GMT-4)</option>
                                <option value="America/Fortaleza">Fortaleza (GMT-3)</option>
                                <option value="America/Belem">Belém (GMT-3)</option>
                                <option value="America/Recife">Recife (GMT-3)</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Fechamento automático</label>
                            <div class="form-check form-switch mt-2">
                                <input type="checkbox" class="form-check-input" wire:model="auto_close" id="auto_close">
                                <label class="form-check-label" for="auto_close">
                                    Bloquear pedidos fora do horário
                                </label>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <h6 class="mb-3">Horários por dia</h6>

                    @foreach($this->days as $day => $label)
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-3">
                                <strong>{{ $label }}</strong>
                            </div>
                            <div class="col-2">
                                <input
                                    type="time"
                                    class="form-control form-control-sm"
                                    wire:model="schedule.{{ $day }}.open"
                                    @if($schedule[$day]['closed'] ?? false) disabled @endif
                                >
                            </div>
                            <div class="col-auto text-muted">às</div>
                            <div class="col-2">
                                <input
                                    type="time"
                                    class="form-control form-control-sm"
                                    wire:model="schedule.{{ $day }}.close"
                                    @if($schedule[$day]['closed'] ?? false) disabled @endif
                                >
                            </div>
                            <div class="col">
                                <div class="form-check">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        wire:model.live="schedule.{{ $day }}.closed"
                                        id="closed_{{ $day }}"
                                    >
                                    <label class="form-check-label text-muted" for="closed_{{ $day }}">
                                        Fechado
                                    </label>
                                </div>
                            </div>
                        </div>
                    @endforeach
                @endif

                <hr>

                <button type="submit" class="btn btn-primary">Salvar</button>
            </form>
        </div>
    </div>
</div>
