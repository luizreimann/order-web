<div class="vstack gap-3">
    <x-page-header title="Agendamento" subtitle="Dia e horário da encomenda. A mesma lista vale para entrega e retirada." />

    @if (session('status'))
        <div class="alert alert-success mb-0">{{ session('status') }}</div>
    @endif

    <form wire:submit.prevent="save" class="vstack gap-3">
        <x-card>
            <div class="vstack gap-3">
                <x-form-check switch name="enabled" label="Aceitar encomenda com dia e horário"
                              help="Desligado, o checkout não pede dia nem horário."
                              wire:model.live="enabled" />

                <div class="row g-3">
                    <div class="col-md-6">
                        <x-form-field type="number" name="min_days" label="Antecedência mínima (dias)"
                                      help="0 é amanhã. O dia de hoje não entra."
                                      wire:model="min_days" min="0" max="365" step="1" />
                    </div>

                    <div class="col-md-6">
                        <x-form-field type="number" name="max_days" label="Máximo de dias"
                                      help="0 não oferece data. A última data é hoje mais esse número."
                                      wire:model="max_days" min="0" max="365" step="1" />
                    </div>
                </div>
            </div>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Dias da semana</x-section-title>
                <div class="text-body-secondary small">
                    Os sete dias começam marcados. Em branco, a capacidade não limita. 0 não aceita pedido.
                    O horário é de 00:00 a 23:59. No intervalo, o fim é depois do começo.
                </div>

                @foreach ($days as $day => $label)
                    @continue(! isset($weekdays[$day]) || ! is_array($weekdays[$day]) || ! is_array($weekdays[$day]['options'] ?? null))
                    <div class="border rounded p-3" wire:key="day-{{ $day }}">
                        <div class="fw-semibold mb-2">{{ $label }}</div>

                        <x-form-check switch name="weekdays.{{ $day }}.enabled" id="weekday-{{ $day }}"
                                      label="Aceita encomenda"
                                      wire:model.live="weekdays.{{ $day }}.enabled" />

                        <div class="col-md-4 mt-3">
                            <x-form-field type="text" name="weekdays.{{ $day }}.capacity" id="capacity-{{ $day }}"
                                          label="Capacidade do dia"
                                          help="Em branco não limita. 0 não aceita nenhum pedido."
                                          wire:model="weekdays.{{ $day }}.capacity"
                                          inputmode="numeric" placeholder="Sem limite" autocomplete="off" />
                        </div>

                        <div class="vstack gap-2 mt-3">
                            @foreach ($weekdays[$day]['options'] as $i => $option)
                                <div class="row g-2 align-items-start" wire:key="option-{{ $option['uid'] }}">
                                    <div class="col-md-3">
                                        <x-form-field type="select" name="weekdays.{{ $day }}.options.{{ $i }}.kind"
                                                      id="kind-{{ $day }}-{{ $i }}" label="Tipo"
                                                      wire:model.live="weekdays.{{ $day }}.options.{{ $i }}.kind">
                                            <option value="time">Horário</option>
                                            <option value="range">Intervalo</option>
                                        </x-form-field>
                                    </div>
                                    <div class="col-md-3">
                                        <x-form-field type="time" name="weekdays.{{ $day }}.options.{{ $i }}.start"
                                                      id="start-{{ $day }}-{{ $i }}"
                                                      label="{{ $option['kind'] === 'range' ? 'Início' : 'Horário' }}"
                                                      wire:model="weekdays.{{ $day }}.options.{{ $i }}.start"
                                                      step="60" />
                                    </div>
                                    @if ($option['kind'] === 'range')
                                        <div class="col-md-3">
                                            <x-form-field type="time" name="weekdays.{{ $day }}.options.{{ $i }}.end"
                                                          id="end-{{ $day }}-{{ $i }}" label="Fim"
                                                          wire:model="weekdays.{{ $day }}.options.{{ $i }}.end"
                                                          step="60" />
                                        </div>
                                    @endif
                                    <div class="col-md-3 pt-md-4">
                                        <div class="btn-group" role="group">
                                            <x-btn variant="secondary" outline size="sm"
                                                   wire:click="moveOptionUp('{{ $day }}', {{ $i }})"
                                                   :disabled="$i === 0"
                                                   aria-label="Subir o horário">
                                                <i class="fa-solid fa-arrow-up"></i>
                                            </x-btn>
                                            <x-btn variant="secondary" outline size="sm"
                                                   wire:click="moveOptionDown('{{ $day }}', {{ $i }})"
                                                   :disabled="$i === count($weekdays[$day]['options']) - 1"
                                                   aria-label="Descer o horário">
                                                <i class="fa-solid fa-arrow-down"></i>
                                            </x-btn>
                                            <x-btn variant="danger" outline size="sm"
                                                   wire:click="removeOption('{{ $day }}', {{ $i }})"
                                                   aria-label="Remover o horário">
                                                Remover
                                            </x-btn>
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            <div>
                                <x-btn variant="secondary" outline size="sm" wire:click="addOption('{{ $day }}')">
                                    Adicionar horário
                                </x-btn>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>

        <x-card>
            <div class="vstack gap-3">
                <x-section-title>Folgas</x-section-title>
                <div class="text-body-secondary small">
                    O dia inteiro, uma vez. Tirar a data devolve o dia. A mesma lista vale para entrega e retirada.
                </div>

                <div class="schedule-calendar" data-schedule-off-day aria-label="Calendário de folgas"></div>

                <ul class="list-unstyled vstack gap-2 mb-0">
                    @forelse ($off_days as $date)
                        <li class="d-flex align-items-center justify-content-between gap-2" wire:key="off-{{ $date }}">
                            <span>{{ substr($date, 8, 2) }}/{{ substr($date, 5, 2) }}/{{ substr($date, 0, 4) }}</span>
                            <x-btn variant="danger" outline size="sm" wire:click="removeOffDay('{{ $date }}')">
                                Tirar
                            </x-btn>
                        </li>
                    @empty
                        <li class="text-body-secondary">Nenhuma folga.</li>
                    @endforelse
                </ul>
            </div>
        </x-card>

        <div>
            <x-btn type="submit" loading="save">Salvar</x-btn>
        </div>
    </form>
</div>
