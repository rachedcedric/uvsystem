<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattrapage : une base qui avait déjà exécuté la première version de `create_subscription_tables` possède un journal de
 * notifications SANS les colonnes de reprise (contenu à rejouer, tentatives, réclamation, trace de l'échec). Sans elles,
 * l'envoi et la reprise des notifications d'abonnement échouent. Ajout idempotent : ne fait rien si les colonnes existent
 * (installations neuves, où `create_subscription_tables` les crée déjà) ni si la table n'existe pas encore.
 *
 * Horodatée AVANT les migrations d'abonnement : sur une installation neuve elle ne fait rien puis la création de la table
 * apporte les colonnes ; sur une base existante elle les ajoute. Cela laisse intacts les retours arrière « des N
 * dernières migrations » (tenants, abonnements) qu'utilisent les tests.
 */
return new class extends Migration
{
    private const COLUMNS = ['notification', 'payload', 'attempts', 'claimed_at', 'failed_at', 'last_error'];

    public function up(): void
    {
        if (! Schema::hasTable('subscription_notification_logs')) {
            return;
        }

        Schema::table('subscription_notification_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('subscription_notification_logs', 'notification')) {
                $table->string('notification')->nullable();
            }
            if (! Schema::hasColumn('subscription_notification_logs', 'payload')) {
                $table->json('payload')->nullable();
            }
            if (! Schema::hasColumn('subscription_notification_logs', 'attempts')) {
                $table->unsignedSmallInteger('attempts')->default(0);
            }
            if (! Schema::hasColumn('subscription_notification_logs', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable();
            }
            if (! Schema::hasColumn('subscription_notification_logs', 'failed_at')) {
                $table->timestamp('failed_at')->nullable();
            }
            if (! Schema::hasColumn('subscription_notification_logs', 'last_error')) {
                $table->text('last_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Volontairement sans effet : sur une installation neuve ces colonnes appartiennent à `create_subscription_tables`,
        // dont le retour arrière supprime toute la table ; les retirer ici casserait cette création.
    }
};
