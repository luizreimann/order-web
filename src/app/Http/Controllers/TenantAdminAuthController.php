<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TenantAdminAuthController extends Controller
{
    /**
     * Tela de login do admin do tenant
     */
    public function showLogin(Tenant $tenant)
    {
        return view('admin.auth.login', [
            'tenant' => $tenant,
        ]);
    }

    /**
     * Processa o login
     */
    public function login(Request $request, Tenant $tenant)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', Role::TenantAdmin->value)
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withErrors(['email' => 'Credenciais inválidas.'])
                ->withInput(['email' => $credentials['email']]);
        }

        // O guard grava a chave de sessão derivada do tenant e regenera o id
        // da sessão (anti session-fixation).
        Auth::guard('tenant_admin')->login($user);

        return redirect()->route('tenant.admin.dashboard', [
            'tenant' => $tenant->slug,
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request, Tenant $tenant)
    {
        // Só desloga ESTE tenant: `invalidate()` derrubaria também o login
        // simultâneo em outros tenants no mesmo navegador.
        Auth::guard('tenant_admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.admin.login', [
            'tenant' => $tenant->slug,
        ]);
    }
}
