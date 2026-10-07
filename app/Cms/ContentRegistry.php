<?php

namespace App\Cms;

use App\Notifications\Subscriptions\PaymentConfirmed;
use App\Notifications\Subscriptions\PaymentFailed;
use App\Notifications\Subscriptions\SubscriptionChangeRequested;
use App\Notifications\Subscriptions\SubscriptionExpired;
use App\Notifications\Subscriptions\SubscriptionExpiring;
use App\Notifications\Subscriptions\SubscriptionNotification;
use App\Notifications\Subscriptions\SubscriptionPlanChanged;
use App\Notifications\Subscriptions\SubscriptionReactivated;
use App\Notifications\Subscriptions\SubscriptionRenewalDue;
use App\Notifications\Subscriptions\SubscriptionSuspended;
use App\Notifications\Subscriptions\TrialExpiring;
use App\Notifications\Subscriptions\TrialStarted;
use InvalidArgumentException;

/**
 * Registre de TOUT ce que le CMS permet de modifier : clé, libellé, valeur par défaut, type et limites.
 * Seules les clés listées ici sont éditables : le CMS ne peut ni créer de clé, ni modifier autre chose que du contenu.
 * Les valeurs par défaut sont celles du code : un contenu jamais modifié s'affiche comme avant le CMS.
 *
 * Types : text (une ligne), multiline (plusieurs lignes), email, phone, url (http(s):// ou chemin commençant par /).
 */
class ContentRegistry
{
    public const SECTION_LANDING = 'landing';

    public const SECTION_PUBLIC = 'public';

    public const SECTION_EMAILS = 'emails';

    public const SECTION_SETTINGS = 'settings';

    /** Notifications d'abonnement dont les textes sont éditables. @var list<class-string<SubscriptionNotification>> */
    public const NOTIFICATIONS = [
        TrialStarted::class, TrialExpiring::class, SubscriptionExpiring::class, SubscriptionExpired::class,
        PaymentConfirmed::class, PaymentFailed::class, SubscriptionPlanChanged::class, SubscriptionSuspended::class,
        SubscriptionReactivated::class, SubscriptionRenewalDue::class, SubscriptionChangeRequested::class,
    ];

    /** @var array<string, array<string, array{label: string, default: string, type: string, max: int, help: ?string}>>|null */
    private static ?array $entries = null;

    /** @return array<string, array{label: string, description: string}> */
    public static function sections(): array
    {
        return [
            self::SECTION_LANDING => ['label' => 'Page d\'accueil', 'description' => 'Textes de la page d\'accueil publique. Un champ vide rétablit le texte d\'origine.'],
            self::SECTION_PUBLIC => ['label' => 'Pages publiques', 'description' => 'Textes des pages /offres et d\'inscription. Les prix et les fonctionnalités des offres se gèrent dans « Offres ».'],
            self::SECTION_EMAILS => ['label' => 'E-mails et messages', 'description' => 'Objet et corps des e-mails envoyés. Un repère entre accolades, comme {plan}, est remplacé automatiquement.'],
            self::SECTION_SETTINGS => ['label' => 'Réglages du SaaS', 'description' => 'Nom de la marque, coordonnées de contact et liens légaux utilisés sur les pages publiques et dans les e-mails.'],
        ];
    }

    /** @return array<string, array{label: string, default: string, type: string, max: int, help: ?string}> clé => définition */
    public static function entries(string $section): array
    {
        if (! array_key_exists($section, self::sections())) {
            throw new InvalidArgumentException("Section CMS inconnue : {$section}");
        }

        return self::all()[$section];
    }

    /** @return array{label: string, default: string, type: string, max: int, help: ?string, section: string} */
    public static function definition(string $key): array
    {
        foreach (self::all() as $section => $entries) {
            if (isset($entries[$key])) {
                return $entries[$key] + ['section' => $section];
            }
        }

        throw new InvalidArgumentException("Contenu CMS inconnu : {$key}");
    }

    public static function has(string $key): bool
    {
        foreach (self::all() as $entries) {
            if (isset($entries[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::all())));
    }

    /** Titre lisible d'un bloc de champs (second segment de la clé : landing.{hero}.title). */
    public static function groupTitle(string $section, string $group, string $fallback): string
    {
        return [
            'landing' => [
                'hero' => 'En-tête de la page', 'cta' => 'Boutons', 'solution' => 'Section « La solution »', 'features' => 'Section « Fonctionnalités »',
                'process' => 'Section « Comment ça marche »', 'security' => 'Section « Sécurité »', 'mobile' => 'Section « Application mobile »',
                'final' => 'Appel final', 'footer' => 'Pied de page',
            ],
            'public' => ['offres' => 'Page /offres', 'inscription' => 'Page d\'inscription', 'sent' => 'Après l\'envoi du formulaire', 'confirm' => 'Page de confirmation'],
            'emails' => ['common' => 'Éléments communs', 'verify_signup' => 'Confirmation d\'inscription'],
            'settings' => ['brand' => 'Marque', 'contact' => 'Contact', 'links' => 'Liens légaux'],
        ][$section][$group] ?? $fallback;
    }

    /** @return array<string, array<string, array{label: string, default: string, type: string, max: int, help: ?string}>> */
    private static function all(): array
    {
        return self::$entries ??= [
            self::SECTION_LANDING => self::landing(),
            self::SECTION_PUBLIC => self::publicPages(),
            self::SECTION_EMAILS => self::emails(),
            self::SECTION_SETTINGS => self::settings(),
        ];
    }

    private static function entry(string $label, string $default, string $type = 'text', ?string $help = null): array
    {
        return ['label' => $label, 'default' => $default, 'type' => $type, 'max' => $type === 'multiline' ? 800 : 200, 'help' => $help];
    }

    private static function landing(): array
    {
        return [
            'landing.hero.signal' => self::entry('Accroche au-dessus du titre', 'Conçu pour la distribution UV en Côte d\'Ivoire'),
            'landing.hero.title' => self::entry('Titre principal', "Vos flux UV.\nUne seule tour de contrôle.", 'multiline', 'Un saut de ligne devient un retour à la ligne dans le titre.'),
            'landing.hero.lead' => self::entry('Texte d\'introduction', 'MODELEC CI relie ventes, remboursements, dettes, commissions et caisse pour que chaque mouvement soit visible, traçable et maîtrisé.', 'multiline'),
            'landing.cta.primary' => self::entry('Bouton principal (inscription)', 'Commencer gratuitement', 'text', 'Utilisé dans le menu, le haut de page et l\'appel final : il mène à /offres.'),
            'landing.cta.discover' => self::entry('Bouton « découvrir »', 'Découvrir la plateforme'),
            'landing.cta.login' => self::entry('Bouton de connexion', 'Accéder à mon espace'),
            'landing.solution.title' => self::entry('Titre : la solution', "Le terrain avance vite.\nVos chiffres aussi.", 'multiline'),
            'landing.features.title' => self::entry('Titre : fonctionnalités', "Tout le cycle UV,\nsans angle mort.", 'multiline'),
            'landing.process.title' => self::entry('Titre : comment ça marche', "Une chaîne simple,\ndu stock au résultat.", 'multiline'),
            'landing.security.title' => self::entry('Titre : sécurité', 'Vos opérations restent dans votre périmètre.'),
            'landing.mobile.title' => self::entry('Titre : application mobile', 'Le pilotage continue sur le terrain.'),
            'landing.final.title' => self::entry('Titre : appel final', "Moins de zones grises.\nPlus de maîtrise.", 'multiline'),
            'landing.footer.tagline' => self::entry('Slogan du pied de page', 'La tour de contrôle de vos opérations UV.'),
        ];
    }

    private static function publicPages(): array
    {
        return [
            'public.offres.title' => self::entry('/offres : titre', 'Choisissez votre offre'),
            'public.offres.subtitle' => self::entry('/offres : sous-titre', 'Essai gratuit, sans engagement. Vos données vous appartiennent et sont toujours conservées.', 'multiline'),
            'public.offres.featured' => self::entry('/offres : étiquette de l\'offre recommandée', 'Recommandée'),
            'public.offres.cta_trial' => self::entry('/offres : bouton d\'une offre avec essai', 'Essayer {days} jours', 'text', 'Repère : {days} = durée d\'essai de l\'offre.'),
            'public.offres.cta_choose' => self::entry('/offres : bouton d\'une offre sans essai', 'Choisir cette offre'),
            'public.inscription.title' => self::entry('Inscription : titre', 'Créer votre entreprise'),
            'public.inscription.trial_note' => self::entry('Inscription : mention de l\'essai', 'Essai gratuit de {days} jours, rien n\'est facturé aujourd\'hui.', 'text', 'Repère : {days} = durée d\'essai de l\'offre choisie.'),
            'public.inscription.submit' => self::entry('Inscription : bouton d\'envoi', 'Recevoir mon lien de confirmation'),
            'public.sent.title' => self::entry('Après l\'envoi : titre', 'Vérifiez votre boîte mail'),
            'public.sent.body' => self::entry('Après l\'envoi : texte', 'Cliquez sur le lien pour créer votre entreprise et démarrer votre essai gratuit.', 'multiline'),
            'public.sent.note' => self::entry('Après l\'envoi : précision', 'Le lien est valable {hours} heures et ne fonctionne qu\'une fois. Rien n\'est créé tant que vous ne l\'avez pas ouvert.', 'multiline', 'Repère : {hours} = durée de validité du lien.'),
            'public.confirm.title' => self::entry('Confirmation : titre', 'Confirmer la création'),
            'public.confirm.body' => self::entry('Confirmation : texte', 'Un dernier clic pour créer votre entreprise et démarrer votre essai gratuit.', 'multiline'),
            'public.confirm.button' => self::entry('Confirmation : bouton', 'Confirmer et démarrer mon essai'),
        ];
    }

    private static function emails(): array
    {
        $generic = 'Repères : {plan}, {cycle}, {date}, {days}, {amount}.';
        $entries = [
            'emails.common.greeting' => self::entry('Formule d\'appel (tous les e-mails)', 'Bonjour,'),
            'emails.common.action' => self::entry('Bouton « voir mon abonnement » (e-mails d\'abonnement)', 'Voir mon abonnement'),
            'emails.verify_signup.subject' => self::entry('Confirmation d\'inscription : objet', 'Confirmez la création de votre entreprise', 'text', 'Repères : {company}, {hours}.'),
            'emails.verify_signup.intro' => self::entry('Confirmation d\'inscription : introduction', 'Vous avez demandé la création de l\'entreprise « {company} ». Confirmez votre adresse e-mail pour démarrer votre essai gratuit.', 'multiline', 'Repères : {company}, {hours}. Une ligne = un paragraphe.'),
            'emails.verify_signup.button' => self::entry('Confirmation d\'inscription : bouton', 'Confirmer et démarrer mon essai'),
            'emails.verify_signup.outro' => self::entry('Confirmation d\'inscription : fin du message', 'Ce lien est valable {hours} heures et ne fonctionne qu\'une fois. Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message : rien ne sera créé.', 'multiline', 'Repères : {company}, {hours}.'),
        ];

        foreach (self::NOTIFICATIONS as $class) {
            $type = $class::type();
            $defaults = $class::defaults();
            $label = $class::label();

            $entries["emails.{$type}.subject"] = self::entry("{$label} : objet", $defaults['subject'], 'text', $generic);
            $entries["emails.{$type}.body"] = self::entry("{$label} : corps", implode("\n", $defaults['lines']), 'multiline', $generic.' Une ligne = un paragraphe.');
        }

        $entries['emails.subscription_change_requested.body_free'] = self::entry(
            'Demande de changement d\'offre : corps quand rien n\'est à payer',
            implode("\n", SubscriptionChangeRequested::freeLines()),
            'multiline',
            $generic.' Une ligne = un paragraphe.',
        );

        return $entries;
    }

    private static function settings(): array
    {
        return [
            'settings.brand.name' => self::entry('Nom de la marque', 'MODELEC CI', 'text', 'Affiché dans le pied de page et les pages de connexion.'),
            'settings.brand.legal_name' => self::entry('Raison sociale', 'MODELEC Côte d\'Ivoire', 'text', 'Affichée à côté du copyright.'),
            'settings.contact.email' => self::entry('E-mail de contact', 'contact@uvsystem.pro', 'email'),
            'settings.contact.phone' => self::entry('Téléphone de contact', '', 'phone', 'Facultatif : affiché dans le pied de page quand il est renseigné.'),
            'settings.links.terms_url' => self::entry('Lien des conditions d\'utilisation', '', 'url', 'Adresse complète (https://…) ou chemin du site (/conditions). Vide : le texte simple est affiché.'),
            'settings.links.privacy_url' => self::entry('Lien de la politique de confidentialité', '', 'url', 'Adresse complète (https://…) ou chemin du site. Vide : aucun lien affiché.'),
        ];
    }
}
