<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0b2f21">
        <meta name="description" content="{{ config('branding.name') }} centralise vos ventes UV, encaissements, dettes, commissions et mouvements de caisse dans une seule plateforme sécurisée.">

        <title>{{ config('branding.name') }} — Pilotez vos opérations UV</title>
        <link rel="canonical" href="{{ rtrim(config('branding.url'), '/') }}/">
        <meta property="og:site_name" content="{{ config('branding.name') }}">
        <meta property="og:title" content="{{ config('branding.name') }} — {{ config('branding.tagline') }}">

        <link rel="icon" href="{{ asset('images/logo.png') }}">
        @vite('resources/css/landing.css')
    </head>
    <body>
        <a class="skip-link" href="#contenu">Aller au contenu</a>

        <header class="site-header">
            <nav class="site-nav shell" aria-label="Navigation principale">
                <a class="brand" href="/" aria-label="{{ config('branding.name') }} — Accueil">
                    <img src="{{ asset('images/logo-wide.png') }}" width="205" height="75" alt="{{ config('branding.name') }}">
                </a>

                <div class="nav-links" aria-label="Navigation de la page">
                    <a href="#solution">La solution</a>
                    <a href="#fonctionnalites">Fonctionnalités</a>
                    <a href="#securite">Sécurité</a>
                    <a href="#mobile">Application mobile</a>
                </div>

                <div class="nav-actions">
                    <a class="text-link" href="/m/login">Accès mobile</a>
                    <a class="button button--small button--dark" href="{{ route('filament.admin.auth.login', [], false) }}">Se connecter</a>
                    <a class="button button--small button--primary" href="{{ route('offres', [], false) }}">{{ cms('landing.cta.primary') }}</a>
                </div>

                <details class="mobile-nav">
                    <summary aria-label="Ouvrir le menu">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 7h16M4 12h16M4 17h16"/>
                        </svg>
                    </summary>
                    <div class="mobile-nav__panel">
                        <a href="#solution">La solution</a>
                        <a href="#fonctionnalites">Fonctionnalités</a>
                        <a href="#securite">Sécurité</a>
                        <a href="#mobile">Application mobile</a>
                        <a href="/m/login">Accès mobile</a>
                        <a class="button button--dark" href="{{ route('filament.admin.auth.login', [], false) }}">Se connecter</a>
                        <a class="button button--primary" href="{{ route('offres', [], false) }}">{{ cms('landing.cta.primary') }}</a>
                    </div>
                </details>
            </nav>
        </header>

        <main id="contenu">
            <section class="hero">
                <div class="hero__rails" aria-hidden="true"></div>
                <div class="shell hero__grid">
                    <div class="hero__copy">
                        <p class="signal">
                            <span></span>
                            {{ cms('landing.hero.signal') }}
                        </p>
                        <h1>{{ cms_html('landing.hero.title') }}</h1>
                        <p class="hero__lead">
                            {{ cms('landing.hero.lead') }}
                        </p>
                        <div class="hero__actions">
                            <a class="button button--primary" href="{{ route('offres', [], false) }}">
                                {{ cms('landing.cta.primary') }}
                                <svg viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="m9 18 6-6-6-6"/>
                                </svg>
                            </a>
                            <a class="button button--ghost" href="#solution">{{ cms('landing.cta.discover') }}</a>
                            <a class="button button--ghost" href="{{ route('filament.admin.auth.login', [], false) }}">{{ cms('landing.cta.login') }}</a>
                        </div>
                        <ul class="assurance-list" aria-label="Principaux avantages">
                            <li>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                                Web et mobile
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                                Données isolées
                            </li>
                            <li>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                                Accès par rôle
                            </li>
                        </ul>
                    </div>

                    <div class="control-room" aria-label="Aperçu du tableau de bord {{ config('branding.name') }}">
                        <div class="control-room__top">
                            <div class="window-controls" aria-hidden="true"><span></span><span></span><span></span></div>
                            <span class="control-room__title">Vue d'ensemble</span>
                            <span class="live-chip"><i></i> En direct</span>
                        </div>
                        <div class="control-room__body">
                            <div class="balance-line">
                                <div>
                                    <span class="mini-label">Solde UV disponible</span>
                                    <strong>31 650 <small>FCFA</small></strong>
                                </div>
                                <div class="balance-mark" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M4 16 9 11l4 4 7-8"/><path d="M15 7h5v5"/></svg>
                                </div>
                            </div>

                            <div class="metric-strip">
                                <div><span>Ventes</span><strong>5,0 M</strong><small>Aujourd'hui</small></div>
                                <div><span>Remboursé</span><strong>1,8 M</strong><small>Aujourd'hui</small></div>
                                <div><span>Dette</span><strong>70 K</strong><small>À suivre</small></div>
                            </div>

                            <div class="activity-panel">
                                <div class="activity-panel__head">
                                    <div>
                                        <span class="mini-label">Activité des ventes</span>
                                        <strong>7 derniers jours</strong>
                                    </div>
                                    <span class="trend">+12,4 %</span>
                                </div>
                                <div class="chart" aria-hidden="true">
                                    <span style="--h: 34%"></span>
                                    <span style="--h: 58%"></span>
                                    <span style="--h: 46%"></span>
                                    <span style="--h: 74%"></span>
                                    <span style="--h: 63%"></span>
                                    <span style="--h: 88%"></span>
                                    <span class="is-today" style="--h: 78%"></span>
                                </div>
                                <div class="chart-labels" aria-hidden="true">
                                    <span>Mer</span><span>Jeu</span><span>Ven</span><span>Sam</span><span>Dim</span><span>Lun</span><span>Mar</span>
                                </div>
                            </div>

                            <div class="transaction-line">
                                <span class="transaction-line__icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11l-3-3m2 13H6l3 3"/></svg>
                                </span>
                                <span><strong>Nouvelle vente enregistrée</strong><small>Agent partenaire · à l'instant</small></span>
                                <b>+ 500 000</b>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="flow-band" aria-label="Périmètre fonctionnel">
                <div class="shell flow-band__inner">
                    <span>Approvisionner</span>
                    <i aria-hidden="true"></i>
                    <span>Distribuer</span>
                    <i aria-hidden="true"></i>
                    <span>Encaisser</span>
                    <i aria-hidden="true"></i>
                    <span>Contrôler</span>
                </div>
            </section>

            <section class="section solution" id="solution">
                <div class="shell">
                    <div class="section-intro section-intro--split">
                        <div>
                            <p class="section-kicker">La solution</p>
                            <h2>{{ cms_html('landing.solution.title') }}</h2>
                        </div>
                        <p>
                            Fini les informations dispersées entre messages, cahiers et fichiers.
                            {{ config('branding.name') }} construit une chaîne continue, du stock UV jusqu'à la caisse.
                        </p>
                    </div>

                    <div class="solution-board">
                        <div class="solution-board__visual" aria-hidden="true">
                            <div class="pulse-map">
                                <span class="pulse-map__hub">{{ config('branding.name') }}</span>
                                <span class="pulse-map__node pulse-map__node--one">Agents</span>
                                <span class="pulse-map__node pulse-map__node--two">Caisse</span>
                                <span class="pulse-map__node pulse-map__node--three">Fournisseurs</span>
                                <span class="pulse-map__node pulse-map__node--four">Direction</span>
                            </div>
                        </div>
                        <div class="solution-board__list">
                            <article>
                                <span class="feature-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg>
                                </span>
                                <div>
                                    <h3>Décider avec les bons chiffres</h3>
                                    <p>Soldes, ventes, dettes et tendances se mettent à jour dans une vue cohérente.</p>
                                </div>
                            </article>
                            <article>
                                <span class="feature-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 8 8 9 4.6-1 8-4 8-9V7l-8-4Z"/><path d="m9 12 2 2 4-4"/></svg>
                                </span>
                                <div>
                                    <h3>Garder chaque mouvement traçable</h3>
                                    <p>Les opérations restent datées, attribuées et accessibles selon le rôle de chacun.</p>
                                </div>
                            </article>
                            <article>
                                <span class="feature-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v4H7zM5 7h14v14H5z"/><path d="M9 12h6m-6 4h4"/></svg>
                                </span>
                                <div>
                                    <h3>Réduire le travail administratif</h3>
                                    <p>Exports, rappels et calculs de commissions accompagnent les opérations quotidiennes.</p>
                                </div>
                            </article>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section operations" id="fonctionnalites">
                <div class="shell">
                    <div class="section-intro">
                        <p class="section-kicker">Fonctionnalités</p>
                        <h2>{{ cms_html('landing.features.title') }}</h2>
                        <p>Chaque module répond à une étape réelle de l'activité, sans casser la continuité des données.</p>
                    </div>

                    <div class="operation-grid">
                        <article class="operation operation--wide">
                            <div class="operation__copy">
                                <span class="feature-icon feature-icon--light">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14v10H5z"/><path d="M8 11h8m-8 3h5M7 7V5h10v2"/></svg>
                                </span>
                                <h3>Ventes et dettes agents</h3>
                                <p>Distribuez les UV, suivez les plafonds et voyez immédiatement ce qui reste à encaisser.</p>
                            </div>
                            <div class="operation__data" aria-hidden="true">
                                <div><span>Agent</span><span>Volume</span><span>Statut</span></div>
                                <div><b>Agence Cocody</b><strong>2 200 000</strong><i>Payé</i></div>
                                <div><b>Success Center</b><strong>800 000</strong><i class="pending">En cours</i></div>
                                <div><b>Velma SARL</b><strong>400 000</strong><i>Payé</i></div>
                            </div>
                        </article>

                        <article class="operation">
                            <span class="feature-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v12H4z"/><path d="M4 10h16M8 15h3"/></svg>
                            </span>
                            <h3>Caisse journalière</h3>
                            <p>Enregistrez entrées et sorties par lot, avec catégories, notes et heure exacte.</p>
                            <div class="operation__foot"><span>Solde du jour</span><strong>Remise à zéro à minuit</strong></div>
                        </article>

                        <article class="operation operation--accent">
                            <span class="feature-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2v20M17 6.5c0-1.4-2.2-2.5-5-2.5S7 5.1 7 6.5 9.2 9 12 9s5 1.1 5 2.5S14.8 14 12 14s-5 1.1-5 2.5S9.2 19 12 19s5-1.1 5-2.5"/></svg>
                            </span>
                            <h3>Commissions maîtrisées</h3>
                            <p>Appliquez vos paliers, calculez les montants et contrôlez leur évolution.</p>
                            <div class="mini-bars" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
                        </article>

                        <article class="operation operation--wide operation--supply">
                            <div class="operation__copy">
                                <span class="feature-icon">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h11v10H3zM14 10h4l3 3v4h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg>
                                </span>
                                <h3>Approvisionnements fournisseurs</h3>
                                <p>Reliez chaque entrée UV à son fournisseur et gardez une lecture nette des dettes associées.</p>
                            </div>
                            <div class="supply-route" aria-hidden="true">
                                <span>Fournisseur</span><i></i><b>Stock UV</b><i></i><span>Distribution</span>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="section process" aria-labelledby="process-title">
                <div class="shell process__grid">
                    <div class="process__intro">
                        <p class="section-kicker">Comment ça marche</p>
                        <h2 id="process-title">{{ cms_html('landing.process.title') }}</h2>
                        <p>La donnée est saisie une fois, puis réutilisée dans les soldes, les suivis et les rapports.</p>
                    </div>
                    <ol class="process-list">
                        <li>
                            <span>01</span>
                            <div><h3>Approvisionnez</h3><p>Le stock UV augmente et l'origine fournisseur reste associée.</p></div>
                        </li>
                        <li>
                            <span>02</span>
                            <div><h3>Distribuez et encaissez</h3><p>Les ventes agents, remboursements et dettes évoluent ensemble.</p></div>
                        </li>
                        <li>
                            <span>03</span>
                            <div><h3>Pilotez</h3><p>La caisse, les commissions et les indicateurs reflètent les mouvements réels.</p></div>
                        </li>
                    </ol>
                </div>
            </section>

            <section class="security" id="securite">
                <div class="shell security__grid">
                    <div class="security__copy">
                        <p class="section-kicker section-kicker--light">Sécurité</p>
                        <h2>{{ cms_html('landing.security.title') }}</h2>
                        <p>
                            {{ config('branding.name') }} sépare les données de chaque entreprise et applique les autorisations
                            au niveau du serveur, pas seulement dans l'interface.
                        </p>
                        <a class="button button--light" href="{{ route('filament.admin.auth.login', [], false) }}">Accéder à l'espace sécurisé</a>
                    </div>
                    <div class="security__features">
                        <article>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 7v5c0 5 3.4 8 8 9 4.6-1 8-4 8-9V7l-8-4Z"/></svg>
                            </span>
                            <div><h3>Isolation par entreprise</h3><p>Les données métier sont cloisonnées par tenant.</p></div>
                        </article>
                        <article>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 10V7a4 4 0 0 1 8 0v3M5 10h14v11H5z"/><path d="M12 14v3"/></svg>
                            </span>
                            <div><h3>Double authentification</h3><p>Les comptes sensibles peuvent être protégés par 2FA.</p></div>
                        </article>
                        <article>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"/><path d="M8 9h8m-8 4h8m-8 4h5"/></svg>
                            </span>
                            <div><h3>Journal d'activité</h3><p>Les actions importantes restent consultables et datées.</p></div>
                        </article>
                        <article>
                            <span>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="8" r="3"/><circle cx="17" cy="9" r="2"/><path d="M3 20v-2a5 5 0 0 1 10 0v2m2-5a4 4 0 0 1 6 3v2"/></svg>
                            </span>
                            <div><h3>Permissions par rôle</h3><p>Master, admin, comptable et commercial voient le nécessaire.</p></div>
                        </article>
                    </div>
                </div>
            </section>

            <section class="section mobile-showcase" id="mobile">
                <div class="shell mobile-showcase__grid">
                    <div class="phone-stage" aria-hidden="true">
                        <div class="phone">
                            <div class="phone__speaker"></div>
                            <div class="phone__screen">
                                <div class="phone__head"><span>Bonsoir, Paulin</span><i>3</i></div>
                                <div class="phone__balance"><small>Solde UV disponible</small><strong>31 650</strong><span>FCFA</span></div>
                                <div class="phone__metrics"><i></i><i></i><i></i><i></i></div>
                                <div class="phone__chart">
                                    <span style="--h:35%"></span><span style="--h:55%"></span><span style="--h:43%"></span>
                                    <span style="--h:70%"></span><span style="--h:60%"></span><span style="--h:82%"></span>
                                </div>
                                <div class="phone__nav"><i></i><i></i><b></b><i></i><i></i></div>
                            </div>
                        </div>
                        <div class="phone-stage__note phone-stage__note--one">Saisie rapide</div>
                        <div class="phone-stage__note phone-stage__note--two">Notifications push</div>
                    </div>
                    <div class="mobile-showcase__copy">
                        <p class="section-kicker">Application mobile</p>
                        <h2>{{ cms_html('landing.mobile.title') }}</h2>
                        <p>
                            Depuis un téléphone, les équipes retrouvent les opérations essentielles dans une
                            interface conçue pour les gestes rapides et les écrans compacts.
                        </p>
                        <ul class="mobile-points">
                            <li><span>01</span><div><strong>Vendre et encaisser</strong><small>Date, heure et contrôle des montants inclus.</small></div></li>
                            <li><span>02</span><div><strong>Consulter les alertes</strong><small>Dettes et notifications restent accessibles au bon moment.</small></div></li>
                            <li><span>03</span><div><strong>Suivre l'activité</strong><small>Indicateurs journaliers et mensuels dans la même expérience.</small></div></li>
                        </ul>
                        <a class="button button--dark" href="/m/login">Ouvrir l'accès mobile</a>
                    </div>
                </div>
            </section>

            <section class="final-cta">
                <div class="shell final-cta__inner">
                    <div>
                        <p class="section-kicker section-kicker--light">{{ config('branding.name') }}</p>
                        <h2>{{ cms_html('landing.final.title') }}</h2>
                    </div>
                    <div class="final-cta__actions">
                        <a class="button button--light" href="{{ route('offres', [], false) }}">{{ cms('landing.cta.primary') }}</a>
                        <a class="button button--outline-light" href="{{ route('filament.admin.auth.login', [], false) }}">Se connecter</a>
                        <a class="button button--outline-light" href="mailto:{{ cms('settings.contact.email') }}">Échanger avec nous</a>
                    </div>
                </div>
            </section>
        </main>

        <footer class="site-footer">
            <div class="shell site-footer__inner">
                <div class="footer-brand">
                    <strong>{{ cms('settings.brand.name') }}</strong>
                    <span>{{ cms('landing.footer.tagline') }}</span>
                </div>
                <div class="footer-links">
                    <a href="mailto:{{ cms('settings.contact.email') }}">{{ cms('settings.contact.email') }}</a>
                    @if (cms('settings.contact.phone') !== '')
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', cms('settings.contact.phone')) }}">{{ cms('settings.contact.phone') }}</a>
                    @endif
                    <a href="{{ route('offres', [], false) }}">Offres et tarifs</a>
                    @if (cms('settings.links.terms_url') !== '')
                        <a href="{{ cms('settings.links.terms_url') }}">Conditions d'utilisation</a>
                    @endif
                    @if (cms('settings.links.privacy_url') !== '')
                        <a href="{{ cms('settings.links.privacy_url') }}">Confidentialité</a>
                    @endif
                    <a href="{{ route('filament.admin.auth.login', [], false) }}">Espace web</a>
                    <a href="/m/login">Accès mobile</a>
                </div>
                <p>© {{ date('Y') }} {{ cms('settings.brand.legal_name') }}</p>
            </div>
        </footer>
    </body>
</html>
