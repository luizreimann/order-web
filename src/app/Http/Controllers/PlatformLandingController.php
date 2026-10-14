<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * Landing institucional da plataforma em `/` (D7, E4.4).
 *
 * Rota sem contexto de tenant (allowlist do RouteMiddlewareAuditTest): não lê
 * dado nenhum e não aponta para nenhuma loja específica. Conteúdo de marketing
 * e planos ficam para a Sprint 07.
 */
class PlatformLandingController extends Controller
{
    public function __invoke(): View
    {
        return view('platform.landing');
    }
}
