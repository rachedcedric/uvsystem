<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class JekoPaymentReturnController extends Controller
{
    public function success(): View
    {
        return view('payments.jeko-return', [
            'successful' => true,
            'title' => 'Paiement transmis',
            'message' => 'Votre paiement est en cours de confirmation. Votre abonnement sera mis à jour automatiquement après validation par Jèko. Vous pouvez fermer cette page pour revenir à l’application.',
        ]);
    }

    public function error(): View
    {
        return view('payments.jeko-return', [
            'successful' => false,
            'title' => 'Paiement non finalisé',
            'message' => 'Le paiement n’a pas été confirmé. Fermez cette page pour revenir à l’application, puis réessayez depuis votre espace abonnement.',
        ]);
    }
}
