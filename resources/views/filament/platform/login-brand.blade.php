{{-- Panneau de marque de la connexion plateforme (écrans larges, visiteurs non connectés uniquement). --}}
<aside class="pf-aside" aria-label="Présentation de la console plateforme">
    <div class="pf-aside__inner">
        <p class="pf-aside__brand">
            <span class="pf-aside__mark" aria-hidden="true">M</span>
            <span>MODELEC CI <small>Console plateforme</small></span>
        </p>

        <div class="pf-aside__body">
            <h2>Pilotez toutes vos entreprises clientes depuis un seul endroit.</h2>
            <p class="pf-aside__lead">Offres, abonnements et entreprises : l'administration de la plateforme, simplement et en sécurité.</p>

            <ul class="pf-aside__list">
                <li>
                    <x-filament::icon icon="heroicon-o-building-office-2" class="pf-aside__icon" />
                    <span><strong>Entreprises clientes</strong>Création, suspension et réactivation de chaque entreprise.</span>
                </li>
                <li>
                    <x-filament::icon icon="heroicon-o-credit-card" class="pf-aside__icon" />
                    <span><strong>Offres et abonnements</strong>Essais, renouvellements, paiements et changements d'offre.</span>
                </li>
                <li>
                    <x-filament::icon icon="heroicon-o-shield-check" class="pf-aside__icon" />
                    <span><strong>Données cloisonnées</strong>Chaque entreprise ne voit que ses propres données.</span>
                </li>
            </ul>
        </div>

        <p class="pf-aside__foot">© {{ date('Y') }} MODELEC CI · Côte d'Ivoire</p>
    </div>
</aside>

{{-- Le logo MODELEC CI a un fond blanc : la page de connexion reste donc en thème clair, quel que soit le réglage du système. --}}
<script>document.documentElement.classList.remove('dark')</script>
