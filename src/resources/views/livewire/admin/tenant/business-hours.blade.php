<div class="vstack gap-3">
    <x-page-header title="Horário de Funcionamento" subtitle="Configure quando sua loja está aberta">
        <x-slot:actions>
            @if($enabled && $auto_close)
                <x-btn :variant="$paused ? 'success' : 'warning'" wire:click="togglePause">
                    {{ $paused ? 'Reabrir Loja' : 'Pausar Pedidos' }}
                </x-btn>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <x-card>
        <form wire:submit.prevent="save">
            <div class="mb-3">
                <x-form-check switch name="enabled" label="Habilitar controle de horário"
                              help="Se desabilitado, a loja será considerada sempre aberta."
                              wire:model.live="enabled" />
            </div>

            @if($enabled)
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <x-form-select name="timezone" label="Fuso horário" wire:model="timezone">
                            <option value="America/Sao_Paulo">São Paulo (GMT-3)</option>
                            <option value="America/Manaus">Manaus (GMT-4)</option>
                            <option value="America/Cuiaba">Cuiabá (GMT-4)</option>
                            <option value="America/Fortaleza">Fortaleza (GMT-3)</option>
                            <option value="America/Belem">Belém (GMT-3)</option>
                            <option value="America/Recife">Recife (GMT-3)</option>
                        </x-form-select>
                    </div>

                    <div class="col-md-6">
                        <x-form-field type="group" name="auto_close" label="Fechamento automático">
                            <div class="mt-2">
                                <x-form-check switch name="auto_close" label="Bloquear pedidos fora do horário" wire:model="auto_close" :validates="false" />
                            </div>
                        </x-form-field>
                    </div>
                </div>

                <hr>

                <div class="mb-3">
                    <x-section-title>Horários por dia</x-section-title>
                </div>

                @foreach($this->days as $day => $label)
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-3">
                            <strong>{{ $label }}</strong>
                        </div>
                        <div class="col-2">
                            <x-form-field type="time" name="schedule.{{ $day }}.open"
                                          id="schedule_{{ $day }}_open"
                                          class="form-control-sm"
                                          wire:model="schedule.{{ $day }}.open"
                                          :disabled="$schedule[$day]['closed'] ?? false" />
                        </div>
                        <div class="col-auto text-muted">às</div>
                        <div class="col-2">
                            <x-form-field type="time" name="schedule.{{ $day }}.close"
                                          id="schedule_{{ $day }}_close"
                                          class="form-control-sm"
                                          wire:model="schedule.{{ $day }}.close"
                                          :disabled="$schedule[$day]['closed'] ?? false" />
                        </div>
                        <div class="col">
                            <x-form-check name="schedule.{{ $day }}.closed"
                                          id="closed_{{ $day }}"
                                          wire:model.live="schedule.{{ $day }}.closed">
                                <span class="text-muted">Fechado</span>
                            </x-form-check>
                        </div>
                    </div>
                @endforeach
            @endif

            <hr>

            <x-btn type="submit" loading="save">Salvar</x-btn>
        </form>
    </x-card>
</div>
