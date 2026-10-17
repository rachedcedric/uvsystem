package ci.uvmaster.app;

import android.content.res.Configuration;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;

import androidx.core.content.ContextCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsControllerCompat;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;

public class MainActivity extends BridgeActivity implements UvmasterNative.Host {

    private static final int MAX_TEXT_ZOOM = 150;

    private LoadingOverlay loadingOverlay;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Sans cela la WebView reste sur son fond blanc par défaut tant que la page n'a rien dessiné : flash blanc au
        // démarrage, pendant un chargement lent et en mode sombre.
        applyWebViewBackground();

        // Écran de chargement natif, dans la toute première image (le splash système se ferme dessus) : il reste
        // jusqu'au signal appReady() du web, ou affiche une erreur avec Réessayer si rien n'arrive.
        loadingOverlay = new LoadingOverlay(this, this::onRetry);
        loadingOverlay.attach();

        // La WebView suit la taille de police du système. Au-delà de 150 % la mise en page casse (solde rogné,
        // libellés de la barre d'onglets coupés) : on garde l'agrandissement jusqu'à 150 % et on le plafonne.
        WebSettings settings = getBridge().getWebView().getSettings();
        int systemZoom = Math.round(getResources().getConfiguration().fontScale * 100);
        settings.setTextZoom(Math.max(100, Math.min(systemZoom, MAX_TEXT_ZOOM)));

        // La page web interroge cette interface avant d'activer les notifications push, et signale qu'elle est prête.
        getBridge().getWebView().addJavascriptInterface(new UvmasterNative(this, this), "UvmasterNative");

        // Capacitor charge la page « Pas de connexion » à la moindre réponse 4xx/5xx de la page principale.
        // Or une réponse HTTP (403, 404, 419, 500…) prouve que le serveur est joignable : on laisse s'afficher
        // la page d'erreur du site. « Pas de connexion » reste réservé aux vraies pannes réseau (onReceivedError).
        getBridge().setWebViewClient(new BridgeWebViewClient(getBridge()) {
            @Override
            public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
                // volontairement vide
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                super.onPageFinished(view, url);

                // La page « Pas de connexion » locale est affichable : l'écran de chargement s'efface (secours si son
                // JavaScript ne s'exécute pas). Pour toute autre page de confiance, le web signale lui-même appReady() ;
                // sinon l'écran est libéré après un court délai.
                String errorUrl = getBridge().getErrorUrl();

                if (errorUrl != null && url != null && url.startsWith(errorUrl)) {
                    loadingOverlay.ready();
                } else if (isTrustedUrl(url)) {
                    loadingOverlay.pageLoaded();
                }
            }
        });
    }

    // Thème clair/sombre changé pendant que l'app est ouverte : les icônes de la barre d'état gardaient l'ancien contraste
    // (heure illisible). Capacitor ne les règle qu'au démarrage.
    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);

        // L'activité traite elle-même le changement de thème (uiMode) : le fond de la WebView et l'écran de chargement
        // doivent suivre.
        applyWebViewBackground();
        loadingOverlay.onConfigurationChanged();

        boolean night = (newConfig.uiMode & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        WindowInsetsControllerCompat controller = WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());
        controller.setAppearanceLightStatusBars(!night);
        controller.setAppearanceLightNavigationBars(!night);
    }

    /** Couleur « papier » (clair) ou « nuit » (sombre) de l'interface, lue dans les ressources m_bg. */
    private void applyWebViewBackground() {
        getBridge().getWebView().setBackgroundColor(ContextCompat.getColor(this, R.color.m_bg));
    }

    /* ------------------------------------------------------------------------ UvmasterNative.Host */

    @Override
    public void post(Runnable action) {
        runOnUiThread(action);
    }

    @Override
    public String currentUrl() {
        return getBridge().getWebView().getUrl();
    }

    /**
     * Origines de confiance : le serveur configuré dans Capacitor et l'origine locale de Capacitor (page « Pas de
     * connexion »). Toute autre page chargée dans la WebView n'a aucun pouvoir sur l'application.
     */
    @Override
    public boolean isTrustedUrl(String url) {
        return TrustedOrigin.isTrusted(url, getBridge().getServerUrl(), getBridge().getScheme() + "://" + getBridge().getHost());
    }

    /**
     * Seule la page « Pas de connexion » locale peut demander une nouvelle tentative : son URL est celle que Capacitor
     * charge en cas d'échec (getErrorUrl), comparée par origine et chemin exacts. Les pages du serveur, leurs iframes et
     * toute autre origine n'ont aucun moyen de déclencher onRetry() par ce pont.
     */
    @Override
    public boolean isErrorPageUrl(String url) {
        return UvmasterNative.isSamePage(url, getBridge().getErrorUrl());
    }

    @Override
    public void onAppReady() {
        loadingOverlay.ready();
    }

    @Override
    public void onRetry() {
        // L'écran de chargement revient, puis l'URL réellement configurée (serveur de production ou de développement)
        // est rechargée : mêmes cookies, même session.
        loadingOverlay.show();
        getBridge().reload();
    }

    @Override
    public void onDestroy() {
        loadingOverlay.destroy();
        super.onDestroy();
    }
}
