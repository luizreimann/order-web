<?php

namespace App\Livewire\Tenant\Auth;

use App\Models\Tenant;
use App\Services\CustomerAuthService;
use Livewire\Component;

class LoginPopup extends Component
{
    public Tenant $tenant;
    public string $phone = '';
    public bool $show = false;

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        
        $authService = app(CustomerAuthService::class);
        $cookieName = $authService->getCookieName($tenant);
        $sessionToken = request()->cookie($cookieName);
        
        if (!$sessionToken) {
            $dismissed = request()->cookie('login_popup_dismissed_' . $tenant->id);
            if (!$dismissed) {
                $this->show = true;
            }
        }
    }

    protected function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:10', 'max:15', 'regex:/^[0-9\s\(\)\-\+]+$/'],
        ];
    }

    public function login(CustomerAuthService $authService): void
    {
        $this->validate();

        $result = $authService->login($this->tenant, $this->phone);

        $cookie = $authService->createCookie($this->tenant, $result['session_token']);

        $this->dispatch('customer-logged-in', customerId: $result['customer']->id);
        
        session()->flash('status', 'Login realizado com sucesso!');
        
        $this->show = false;
        
        cookie()->queue($cookie);
        
        $this->dispatch('$refresh');
    }

    public function dismiss(): void
    {
        $this->show = false;
        
        $dismissCookie = cookie(
            'login_popup_dismissed_' . $this->tenant->id,
            '1',
            60 * 24, // 1 dia
            '/',
            null,
            false, // secure = false para funcionar em localhost
            true,  // httpOnly = true
            false, // raw
            'lax'  // sameSite
        );
        
        cookie()->queue($dismissCookie);
    }

    public function render()
    {
        return view('livewire.tenant.auth.login-popup');
    }
}
