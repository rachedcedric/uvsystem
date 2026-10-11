<?php

namespace App\Livewire\Mobile\Agents;

use App\Models\Agent;
use App\Services\UvDistributionService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.mobile')]
#[Title('Agent')]
class Show extends Component
{
    public Agent $agent;

    public string $channel = 'sms';

    public string $customMessage = '';

    public function mount(Agent $agent): void
    {
        Gate::authorize('view', $agent);

        $this->agent = $agent;
    }

    /** Rappel de paiement par SMS et/ou e-mail (même logique que l'action « Envoyer rappel » de l'interface complète). */
    public function sendReminder(): void
    {
        Gate::authorize('view', $this->agent);
        abort_unless(in_array($this->channel, ['sms', 'email', 'both'], true), 422);

        $agent = $this->agent->fresh();
        if ($agent->current_debt <= 0) {
            $this->dispatch('m-toast', message: 'Cet agent n\'a pas de dette.', type: 'error');

            return;
        }

        $message = trim($this->customMessage) !== '' ? trim($this->customMessage) : null;
        $sent = [];

        try {
            if (in_array($this->channel, ['sms', 'both'], true)) {
                $sms = $message ?: "Bonjour {$agent->name}, vous avez une dette de ".number_format($agent->current_debt, 0, ',', ' ').' FCFA en attente. Merci de régulariser votre situation. - '.config('branding.name');
                if (app(\App\Services\SmsService::class)->sendToAgent($agent, $sms)) {
                    $sent[] = 'SMS';
                }
            }
            if (in_array($this->channel, ['email', 'both'], true) && $agent->email) {
                $agent->notify(new \App\Notifications\PaymentReminderNotification($agent, $message));
                $sent[] = 'E-mail';
            }
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('m-toast', message: 'Envoi impossible : '.$e->getMessage(), type: 'error');

            return;
        }

        if ($sent === []) {
            $this->dispatch('m-toast', message: 'Aucun rappel envoyé (numéro ou e-mail manquant).', type: 'error');

            return;
        }

        activity()->performedOn($agent)->causedBy(auth()->user())
            ->withProperties(['channels' => $sent, 'debt' => $agent->current_debt])->log("Rappel de paiement envoyé à {$agent->name}");

        $this->customMessage = '';
        $this->dispatch('m-toast', message: 'Rappel envoyé par '.implode(' + ', $sent).'.');
    }

    public function delete()
    {
        Gate::authorize('delete', $this->agent);

        $this->agent->delete();
        session()->flash('m-toast', 'Agent supprimé.');

        return $this->redirectRoute('m.agents', navigate: true);
    }

    public function render()
    {
        $agent = $this->agent->fresh();
        $totals = $agent->soldeTotals();
        $credit = (new UvDistributionService)->getAvailableCredit($agent);

        $ventes = $agent->transactions()->latest('transaction_date')->limit(15)->get()->map(fn ($t): array => [
            'kind' => $t->transaction_type === 'distribution' ? 'vente' : 'retour',
            'date' => $t->transaction_date,
            'amount' => (float) $t->amount,
            'ref' => $t->reference,
            'cancelled' => $t->status === 'cancelled',
            'url' => route('m.ventes.show', $t),
        ]);

        $rembs = $agent->remboursements()->latest('payment_date')->limit(15)->get()->map(fn ($r): array => [
            'kind' => 'remboursement',
            'date' => $r->payment_date,
            'amount' => (float) $r->amount,
            'ref' => $r->reference,
            'cancelled' => false,
            'url' => route('m.remboursements.show', $r),
        ]);

        $user = auth()->user();

        return view('livewire.mobile.agents.show', [
            'a' => $agent,
            'totals' => $totals,
            'unlimited' => $credit === PHP_FLOAT_MAX,
            'credit' => $credit === PHP_FLOAT_MAX ? null : $credit,
            'ratio' => $agent->debt_limit > 0 ? ($agent->current_debt / $agent->debt_limit) * 100 : 0,
            'timeline' => $ventes->concat($rembs)->sortByDesc('date')->take(15)->values(),
            'canRemind' => $agent->current_debt > 0 && $user->can('view', $agent),
            'canEdit' => $user->can('update', $agent),
            'canDelete' => $user->can('delete', $agent),
            'canSell' => $agent->status === 'active' && $user->can('create', \App\Models\UvTransaction::class),
            'canCollect' => $agent->status === 'active' && $agent->current_debt > 0 && $user->can('create', \App\Models\Remboursement::class),
        ]);
    }
}
