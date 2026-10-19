@props(['tenant'])

@php
    $contactInfo = \App\Support\TenantHelper::getContactInfo($tenant);
    $businessHours = \App\Support\TenantHelper::getBusinessHoursForDisplay($tenant);
    $isOpen = \App\Support\TenantHelper::isOpen($tenant);
    $whatsappUrl = \App\Support\TenantHelper::getWhatsAppUrl($tenant);
@endphp

<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactModalLabel">Informações de Contato</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-4 vstack gap-2">
                    <x-section-title>Status</x-section-title>
                    @if($isOpen)
                        <span class="badge text-bg-success fs-6 align-self-start">
                            <i class="fa-solid fa-circle-check me-1"></i>
                            Aberto agora
                        </span>
                    @else
                        <span class="badge text-bg-secondary fs-6 align-self-start">
                            <i class="fa-solid fa-circle-xmark me-1"></i>
                            Fechado
                        </span>
                    @endif
                </div>

                @if(count($businessHours) > 0)
                    <div class="mb-4 vstack gap-2">
                        <x-section-title>
                            <i class="fa-solid fa-clock me-1"></i>
                            Horário de Funcionamento
                        </x-section-title>
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0">
                                <tbody>
                                    @foreach($businessHours as $day)
                                        <tr>
                                            <td class="text-muted">{{ $day['day'] }}</td>
                                            <td class="text-end">{{ $day['hours'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                @if($contactInfo['phone'] || $contactInfo['whatsapp'] || $contactInfo['email'])
                    <div class="vstack gap-2">
                        <x-section-title>
                            <i class="fa-solid fa-address-book me-1"></i>
                            Contato
                        </x-section-title>

                        <div>
                            @if($contactInfo['phone'])
                                <div class="mb-2">
                                    <i class="fa-solid fa-phone text-muted me-2"></i>
                                    <a href="tel:{{ $contactInfo['phone'] }}" class="text-decoration-none">
                                        {{ $contactInfo['phone'] }}
                                    </a>
                                </div>
                            @endif

                            @if($contactInfo['whatsapp'])
                                <div class="mb-2">
                                    <i class="fa-brands fa-whatsapp text-success me-2"></i>
                                    <a href="{{ $whatsappUrl }}" target="_blank" class="text-decoration-none">
                                        {{ $contactInfo['whatsapp'] }}
                                    </a>
                                </div>
                            @endif

                            @if($contactInfo['email'])
                                <div class="mb-2">
                                    <i class="fa-solid fa-envelope text-muted me-2"></i>
                                    <a href="mailto:{{ $contactInfo['email'] }}" class="text-decoration-none">
                                        {{ $contactInfo['email'] }}
                                    </a>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif

                @if($contactInfo['whatsapp'] && $whatsappUrl)
                    <div class="mt-4">
                        <a href="{{ $whatsappUrl }}" target="_blank" class="btn btn-success w-100">
                            <i class="fa-brands fa-whatsapp me-2"></i>
                            Abrir WhatsApp
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
