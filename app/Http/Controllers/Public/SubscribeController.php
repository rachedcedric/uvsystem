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
use Illuminate\Validation\ValidationException;

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
        $request->session()->put('signup_issued_at', now()->timestamp);

        return view('public.inscription', ['plan' => $plan, 'cycle' => $cycle, 'price' => $plan->priceFor($cycle), 'token' => $token]);
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
            'website' => ['nullable', 'max:0'], // leurre : un humain ne voit pas ce champ
        ], ['accept_terms.accepted' => 'Vous devez accepter les conditions d\'utilisation.']);

        // Double clic / rechargement du MÊME formulaire déjà accepté : idempotent, on renvoie à la même page, sans second e-mail.
        $done = $request->session()->get('signup_done_token');
        if (is_string($done) && hash_equals($done, $data['token'])) {
            return redirect()->route('inscription.envoye')->with('signup_email', $data['email']);
        }

        // Jeton à usage unique : consommé AVANT toute création.
        $expected = $request->session()->pull('signup_token');
        if (! is_string($expected) || ! hash_equals($expected, $data['token'])) {
            return redirect()->route('offres')->withErrors(['token' => 'Ce formulaire a expiré ou a déjà été envoyé. Recommencez depuis le choix de l\'offre.']);
        }

        // Un robot remplit tout instantanément : un formulaire renvoyé trop vite est refusé.
        $issuedAt = (int) $request->session()->pull('signup_issued_at', 0);
        if (now()->timestamp - $issuedAt < (int) config('subscriptions.signup.min_seconds_to_submit')) {
            return redirect()->route('offres')->withErrors(['token' => 'Formulaire envoyé trop vite. Recommencez depuis le choix de l\'offre.']);
        }

        $plan = $this->plan($data['plan']);
        if (! $plan) {
            return redirect()->route('offres')->withErrors(['plan' => 'Cette offre n\'est plus disponible.']);
        }

        // Aucune entreprise n'est créée ici : un lien de confirmation est envoyé à l'adresse indiquée.
        $signup->request($plan, SubscriptionCycle::from($data['cycle']), $data, $request->ip());
        $request->session()->put('signup_done_token', $data['token']);

        return redirect()->route('inscription.envoye')->with('signup_email', $data['email']);
    }

    public function sent(Request $request)
    {
        return view('public.verification-envoyee', ['email' => $request->session()->get('signup_email')]);
    }

    /** Page de confirmation : un simple GET ne crée rien (les scanners de liens e-mail ne doivent pas consommer le jeton). */
    public function verify(string $token)
    {
        return view('public.verification', ['token' => $token]);
    }

    public function confirm(string $token, TenantSignupService $signup): RedirectResponse
    {
        try {
            $signup->complete($token, $created);
        } catch (ValidationException $e) {
            return redirect()->route('offres')->withErrors($e->errors());
        } catch (\Throwable $e) {
            // La transaction est annulée : rien n'a été créé et le lien reste utilisable pour un nouvel essai.
            report($e);

            return redirect()->back()->withErrors(['token' => 'La création de votre entreprise a échoué, rien n\'a été créé. Réessayez dans un instant en rouvrant le lien reçu par e-mail.']);
        }

        return redirect()->route('m.login')->with('status', $created
            ? 'Votre adresse est confirmée, votre entreprise est créée et votre essai gratuit démarre. Connectez-vous avec l\'adresse e-mail et le mot de passe choisis.'
            : 'Votre entreprise est déjà créée : connectez-vous avec l\'adresse e-mail et le mot de passe choisis.');
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
