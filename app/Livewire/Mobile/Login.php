<?php

namespace App\Livewire\Mobile;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile', ['guest' => true])]
#[Title('Connexion')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    /** Session conservée par défaut : l'appli reste connectée après fermeture. */
    public bool $remember = true;

    public function mount()
    {
        if (Auth::check()) {
            return $this->redirectRoute('m.home', navigate: false);
        }
    }

    public function login()
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'Saisissez votre adresse e-mail.',
            'email.email' => 'Cette adresse e-mail n\'est pas valide.',
            'password.required' => 'Saisissez votre mot de passe.',
        ]);

        $throttleKey = 'm-login|'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Réessayez dans '.RateLimiter::availableIn($throttleKey).' secondes.',
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'E-mail ou mot de passe incorrect.',
            ]);
        }

        if (! Auth::user()->canAccessPanel(Filament::getPanel('admin'))) {
            Auth::logout();
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'Ce compte n\'a pas accès à l\'application.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        return $this->redirectIntended(default: route('m.home'), navigate: false);
    }

    public function render()
    {
        return view('livewire.mobile.login');
    }
}
