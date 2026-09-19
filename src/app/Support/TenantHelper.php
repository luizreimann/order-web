<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\Carbon;

class TenantHelper
{
    public static function isOpen(Tenant $tenant): bool
    {
        $hours = data_get($tenant->settings, 'business_hours');

        if (!data_get($hours, 'enabled', false)) {
            return true;
        }

        if (data_get($hours, 'paused', false)) {
            return false;
        }

        if (!data_get($hours, 'auto_close', true)) {
            return true;
        }

        $timezone = data_get($hours, 'timezone', 'America/Sao_Paulo');
        $now = Carbon::now($timezone);
        $dayOfWeek = strtolower($now->englishDayOfWeek);

        $daySchedule = data_get($hours, "schedule.{$dayOfWeek}");

        if (!$daySchedule || data_get($daySchedule, 'closed', false)) {
            return false;
        }

        $open = data_get($daySchedule, 'open');
        $close = data_get($daySchedule, 'close');

        if (!$open || !$close) {
            return true;
        }

        $openTime = Carbon::createFromFormat('H:i', $open, $timezone)->setDateFrom($now);
        $closeTime = Carbon::createFromFormat('H:i', $close, $timezone)->setDateFrom($now);

        return $now->between($openTime, $closeTime);
    }

    public static function getFormattedAddress(Tenant $tenant): ?string
    {
        $addr = data_get($tenant->settings, 'address');

        if (!$addr || !data_get($addr, 'show_on_storefront', false)) {
            return null;
        }

        $parts = array_filter([
            data_get($addr, 'street'),
            data_get($addr, 'number'),
            data_get($addr, 'complement'),
        ]);

        $line1 = implode(', ', $parts);

        $line2Parts = array_filter([
            data_get($addr, 'neighborhood'),
            data_get($addr, 'city'),
            data_get($addr, 'state'),
        ]);

        $line2 = implode(' - ', $line2Parts);

        $zip = data_get($addr, 'zip_code');

        return trim("{$line1}\n{$line2}" . ($zip ? " - CEP: {$zip}" : ''));
    }

    public static function getWhatsAppUrl(Tenant $tenant): ?string
    {
        $whatsapp = data_get($tenant->settings, 'contact.whatsapp');

        if (!$whatsapp) {
            return null;
        }

        $message = data_get($tenant->settings, 'contact.whatsapp_message', '');
        $encoded = urlencode($message);

        return "https://wa.me/{$whatsapp}" . ($encoded ? "?text={$encoded}" : '');
    }

    public static function getDeliveryInfo(Tenant $tenant): array
    {
        $delivery = data_get($tenant->settings, 'delivery', []);

        return [
            'minimum_order' => data_get($delivery, 'minimum_order_cents', 0) / 100,
            'fee' => data_get($delivery, 'default_fee_cents', 0) / 100,
            'estimated_time' => data_get($delivery, 'estimated_time_minutes'),
            'free_above' => data_get($delivery, 'free_delivery_above_cents', 0) / 100,
        ];
    }

    public static function getSocialLinks(Tenant $tenant): array
    {
        $social = data_get($tenant->settings, 'social', []);

        return array_filter([
            'instagram' => data_get($social, 'instagram'),
            'facebook' => data_get($social, 'facebook'),
            'tiktok' => data_get($social, 'tiktok'),
            'youtube' => data_get($social, 'youtube'),
        ]);
    }

    public static function getContactInfo(Tenant $tenant): array
    {
        $contact = data_get($tenant->settings, 'contact', []);

        return [
            'phone' => data_get($contact, 'phone'),
            'whatsapp' => data_get($contact, 'whatsapp'),
            'email' => data_get($contact, 'email'),
            'show_whatsapp_button' => data_get($contact, 'show_whatsapp_button', false),
        ];
    }

    public static function getBusinessHoursForDisplay(Tenant $tenant): array
    {
        $hours = data_get($tenant->settings, 'business_hours', []);

        if (!data_get($hours, 'enabled', false)) {
            return [];
        }

        $days = [
            'monday' => 'Segunda',
            'tuesday' => 'Terça',
            'wednesday' => 'Quarta',
            'thursday' => 'Quinta',
            'friday' => 'Sexta',
            'saturday' => 'Sábado',
            'sunday' => 'Domingo',
        ];

        $result = [];

        foreach ($days as $key => $label) {
            $schedule = data_get($hours, "schedule.{$key}", []);

            if (data_get($schedule, 'closed', false)) {
                $result[] = ['day' => $label, 'hours' => 'Fechado'];
            } else {
                $open = data_get($schedule, 'open', '');
                $close = data_get($schedule, 'close', '');
                $result[] = ['day' => $label, 'hours' => $open && $close ? "{$open} - {$close}" : '-'];
            }
        }

        return $result;
    }

    public static function getSeoData(Tenant $tenant): array
    {
        $seo = data_get($tenant->settings, 'seo', []);
        $store = data_get($tenant->settings, 'store', []);

        return [
            'title' => data_get($seo, 'meta_title') ?: $tenant->name,
            'description' => data_get($seo, 'meta_description') ?: data_get($store, 'short_description', ''),
            'og_image' => data_get($seo, 'og_image_path'),
            'google_analytics_id' => data_get($seo, 'google_analytics_id'),
        ];
    }
}
