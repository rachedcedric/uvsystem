<?php

namespace App\Http\Controllers\Public;

use App\Enums\SubscriptionCycle;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\TenantSignupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Parcours de souscription : offre -> cycle -> entreprise -> premier master -> conditions -> essai -> connexion.
 * Le navigateur n'envoie que l'identifiant d'offre (slug) et des champs de saisie ; le reste est recalculé.
 */
class SubscribeController extends Controller
{
    public function show(Request $request)
    {
        if ($redirect = $this->refuseIfAlreadyInTenant($request)) {
            return $redirect;
        }

        $plan = $this->plan((string) $request->query('plan'));
        if (! $plan) {
            return redirect()->route('offres');
        }

        $cycle = SubscriptionCycle::tryFrom((string) $request->query('cycle')) ?? SubscriptionCycle::Monthly;

        // Jeton à usage unique : empêche la double soumission (le second envoi, même jeton, est refusé).
        $token = Str::random(40);
        $request->session()->put('signup_token', $token);

        return view('public.souscrire', ['plan' => $plan, 'cycle' => $cycle, 'price' => $plan->priceFor($cycle), 'token' => $token]);
    }

    public function store(Request $request, TenantSignupService $signup): RedirectResponse
    {
        if ($redirect = $this->refuseIfAlreadyInTenant($request)) {
            return $redirect;
        }

        // Seuls ces champs sont lus : prix, limites, rôle, statut, essai, tenant_id… envoyés par le client sont ignorés.
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:100'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'company_name' => ['required', 'string', 'min:2', 'max:150'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'accept_terms' => ['accepted'],
            'token' => ['required', 'string'],
        ], ['accept_terms.accepted' => 'Vous devez accepter les conditions d\'utilisation.']);

        // Consommé AVANT toute création : un second envoi du même formulaire ne passe jamais.
        $expected = $request->session()->pull('signup_token');
        if (! is_string($expected) || ! hash_equals($expected, $data['token'])) {
            return redirect()->route('offres')->withErrors(['token' => 'Ce formulaire a expiré ou a déjà été envoyé. Recommencez depuis le choix de l\'offre.']);
        }

        $plan = $this->plan($data['plan']);
        if (! $plan) {
            return redirect()->route('offres')->withErrors(['plan' => 'Cette offre n\'est plus disponible.']);
        }

        $signup->register($plan, SubscriptionCycle::from($data['cycle']), $data);

        return redirect()->route('m.login')->with('status', 'Votre entreprise est créée et votre essai gratuit démarre. Connectez-vous avec l\'adresse e-mail et le mot de passe choisis.');
    }

    private function plan(string $slug): ?SubscriptionPlan
    {
        return SubscriptionPlan::active()->where('slug', $slug)->first();
    }

    /** Un utilisateur déjà rattaché à une entreprise ne peut pas en créer une seconde. */
    private function refuseIfAlreadyInTenant(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        return $user && ($user->tenant_id !== null || $user->isSuperAdmin())
            ? redirect()->route('offres')->withErrors(['account' => 'Votre compte est déjà rattaché à une entreprise : vous ne pouvez pas en créer une autre.'])
            : null;
    }
}
