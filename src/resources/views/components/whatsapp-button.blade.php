@php
    use App\Support\TenantHelper;

    $contact = TenantHelper::getContactInfo($currentTenant);
    $whatsappUrl = TenantHelper::getWhatsAppUrl($currentTenant);
@endphp

@if($contact['show_whatsapp_button'] && $whatsappUrl)
    <a
        href="{{ $whatsappUrl }}"
        target="_blank"
        class="whatsapp-float"
        title="Fale conosco pelo WhatsApp"
        aria-label="WhatsApp"
    >
        <i class="fa-brands fa-whatsapp"></i>
    </a>

    <style>
        .whatsapp-float {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background-color: #25D366;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            text-decoration: none;
            z-index: 1000;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .whatsapp-float:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
            color: white;
        }

        @media (max-width: 576px) {
            .whatsapp-float {
                width: 50px;
                height: 50px;
                font-size: 1.5rem;
                bottom: 15px;
                right: 15px;
            }
        }
    </style>
@endif
