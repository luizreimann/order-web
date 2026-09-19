<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
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
            ->whereIn('role', ['tenant_admin', 'tenant_staff'])
            ->where('email', $credentials['email'])
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return back()
                ->withErrors(['email' => 'Credenciais inválidas.'])
                ->withInput(['email' => $credentials['email']]);
        }

        // login manual via session
        $request->session()->regenerate();
        $request->session()->put('tenant_admin_user_id', $user->id);

        return redirect()->route('tenant.admin.dashboard', [
            'tenant' => $tenant->slug,
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request, Tenant $tenant)
    {
        $request->session()->forget('tenant_admin_user_id');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.admin.login', [
            'tenant' => $tenant->slug,
        ]);
    }
}