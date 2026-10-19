@php
    use App\Support\TenantHelper;

    $contact = TenantHelper::getContactInfo($currentTenant);
    $address = TenantHelper::getFormattedAddress($currentTenant);
    $social = TenantHelper::getSocialLinks($currentTenant);
    $businessHours = TenantHelper::getBusinessHoursForDisplay($currentTenant);
    $googleMapsUrl = data_get($currentTenant->settings, 'address.google_maps_url');
@endphp

<footer class="bg-light border-top mt-5 py-4">
    <div class="container">
        <div class="row g-4">
            @if($address || $contact['phone'] || $contact['email'])
                <div class="col-md-4">
                    <div class="vstack gap-3">
                        <x-section-title>Contato</x-section-title>

                        <div>
                            @if($contact['phone'])
                            <div class="mb-2">
                                <i class="fa-solid fa-phone me-2 text-muted"></i>
                                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}" class="text-decoration-none">
                                    {{ $contact['phone'] }}
                                </a>
                            </div>
                            @endif

                            @if($contact['whatsapp'])
                            <div class="mb-2">
                                <i class="fa-brands fa-whatsapp me-2 text-muted"></i>
                                <a href="{{ TenantHelper::getWhatsAppUrl($currentTenant) }}" target="_blank" class="text-decoration-none">
                                    WhatsApp
                                </a>
                            </div>
                            @endif

                            @if($contact['email'])
                            <div class="mb-2">
                                <i class="fa-solid fa-envelope me-2 text-muted"></i>
                                <a href="mailto:{{ $contact['email'] }}" class="text-decoration-none">
                                    {{ $contact['email'] }}
                                </a>
                            </div>
                            @endif

                            @if($address)
                            <div class="mb-2">
                                <i class="fa-solid fa-location-dot me-2 text-muted"></i>
                                <span class="text-muted pre-line">{{ $address }}</span>
                                @if($googleMapsUrl)
                                    <br>
                                    <a href="{{ $googleMapsUrl }}" target="_blank" class="small">Ver no mapa</a>
                                @endif
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            @if(count($businessHours) > 0)
                <div class="col-md-4">
                    <div class="vstack gap-3">
                        <x-section-title>Horário de Funcionamento</x-section-title>
                        <div class="small">
                            @foreach($businessHours as $item)
                                <div class="d-flex justify-content-between mb-1">
                                    <span>{{ $item['day'] }}</span>
                                    <span class="text-muted">{{ $item['hours'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

            @if(count($social) > 0)
                <div class="col-md-4">
                    <div class="vstack gap-3">
                        <x-section-title>Redes Sociais</x-section-title>
                        <div class="d-flex gap-3">
                            @if($social['instagram'] ?? null)
                                <a href="{{ $social['instagram'] }}" target="_blank" class="text-decoration-none fs-4" title="Instagram">
                                    <i class="fa-brands fa-instagram"></i>
                                </a>
                            @endif

                            @if($social['facebook'] ?? null)
                                <a href="{{ $social['facebook'] }}" target="_blank" class="text-decoration-none fs-4" title="Facebook">
                                    <i class="fa-brands fa-facebook"></i>
                                </a>
                            @endif

                            @if($social['tiktok'] ?? null)
                                <a href="{{ $social['tiktok'] }}" target="_blank" class="text-decoration-none fs-4" title="TikTok">
                                    <i class="fa-brands fa-tiktok"></i>
                                </a>
                            @endif

                            @if($social['youtube'] ?? null)
                                <a href="{{ $social['youtube'] }}" target="_blank" class="text-decoration-none fs-4" title="YouTube">
                                    <i class="fa-brands fa-youtube"></i>
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <hr class="my-4">

        <div class="text-center text-muted small">
            &copy; {{ date('Y') }} {{ $currentTenant->name }}. Todos os direitos reservados.
        </div>
    </div>
</footer>
