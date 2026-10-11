<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * Pausa/reabre a loja (`business_hours.paused`). Antes era uma closure em
 * `routes/web.php` sem nenhuma checagem (SEC-004 / E1.6).
 */
class ToggleStoreStatusController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Tenant $tenant): JsonResponse
    {
        $this->authorize('toggleStoreStatus', $tenant);

        $settings = $tenant->settings ?? [];
        $newPaused = ! data_get($settings, 'business_hours.paused', false);

        data_set($settings, 'business_hours.paused', $newPaused);
        $tenant->settings = $settings;
        $tenant->save();

        return response()->json([
            'success' => true,
            'paused' => $newPaused,
        ]);
    }
}
