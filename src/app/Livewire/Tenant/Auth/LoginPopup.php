<?php

namespace App\Livewire\Tenant\Auth;

use App\Models\Tenant;
use App\Rules\PhoneNumber;
use App\Services\CustomerAuthService;
use App\Services\CustomerLoginCodeService;
use Livewire\Component;

class LoginPopup extends Component
{
    public Tenant $tenant;

    public string $phone = '';

    public bool $show = false;

    public bool $awaitingCode = false;

    public string $notice = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        $authService = app(CustomerAuthService::class);
        $cookieName = $authService->getCookieName($tenant);
        $sessionToken = request()->cookie($cookieName);

        if (! $sessionToken) {
            $dismissed = request()->cookie('login_popup_dismissed_'.$tenant->id);
            if (! $dismissed) {
                $this->show = true;
            }
        }
    }

    protected function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:40', new PhoneNumber],
        ];
    }

    public function login(CustomerLoginCodeService $codes, CustomerAuthService $auth): void
    {
        $this->notice = '';
        $this->validate();

        $result = $codes->requestCode($this->tenant, $this->phone);

        if ($result === true) {
            $this->awaitingCode = true;

            return;
        }

        if (is_array($result)) {
            $this->enter($auth, $result);

            return;
        }

        $this->awaitingCode = false;
        $this->notice = 'Não foi possível enviar o código agora. Espere um pouco ou peça de novo.';
    }

    public function confirm(string $code, CustomerLoginCodeService $codes, CustomerAuthService $auth): void
    {
        $result = $codes->verifyCode($this->tenant, $this->phone, $code);

        if ($result === null) {
            $this->notice = 'Esse código não confere.';

            return;
        }

        $this->enter($auth, $result);
    }

    public function dismiss(): void
    {
        $this->show = false;
        $this->awaitingCode = false;

        $dismissCookie = app(CustomerAuthService::class)->createDismissCookie($this->tenant);

        cookie()->queue($dismissCookie);
    }

    /**
     * @param  array{customer: \App\Models\Customer, session_token: string}  $session
     */
    private function enter(CustomerAuthService $auth, array $session): void
    {
        $cookie = $auth->createCookie($this->tenant, $session['session_token']);

        $this->dispatch('customer-logged-in', customerId: $session['customer']->id);

        session()->flash('status', 'Login realizado com sucesso!');

        $this->show = false;
        $this->awaitingCode = false;
        $this->notice = '';

        cookie()->queue($cookie);

        $this->dispatch('$refresh');
    }

    public function render()
    {
        return view('livewire.tenant.auth.login-popup');
    }
}
