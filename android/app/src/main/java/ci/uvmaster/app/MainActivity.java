package ci.uvmaster.app;

import android.content.res.Configuration;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;

import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsControllerCompat;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;

public class MainActivity extends BridgeActivity {

    private static final int MAX_TEXT_ZOOM = 150;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // La WebView suit la taille de police du système. Au-delà de 150 % la mise en page casse (solde rogné,
        // libellés de la barre d'onglets coupés) : on garde l'agrandissement jusqu'à 150 % et on le plafonne.
        WebSettings settings = getBridge().getWebView().getSettings();
        int systemZoom = Math.round(getResources().getConfiguration().fontScale * 100);
        settings.setTextZoom(Math.max(100, Math.min(systemZoom, MAX_TEXT_ZOOM)));

        // La page web interroge cette interface avant d'activer les notifications push.
        getBridge().getWebView().addJavascriptInterface(new UvmasterNative(this), "UvmasterNative");

        // Capacitor charge la page « Pas de connexion » à la moindre réponse 4xx/5xx de la page principale.
        // Or une réponse HTTP (403, 404, 419, 500…) prouve que le serveur est joignable : on laisse s'afficher
        // la page d'erreur du site. « Pas de connexion » reste réservé aux vraies pannes réseau (onReceivedError).
        getBridge().setWebViewClient(new BridgeWebViewClient(getBridge()) {
            @Override
            public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
                // volontairement vide
            }
        });
    }

    // Thème clair/sombre changé pendant que l'app est ouverte : les icônes de la barre d'état gardaient l'ancien contraste
    // (heure illisible). Capacitor ne les règle qu'au démarrage.
    @Override
    public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);

        boolean night = (newConfig.uiMode & Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES;
        WindowInsetsControllerCompat controller = WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());
        controller.setAppearanceLightStatusBars(!night);
        controller.setAppearanceLightNavigationBars(!night);
    }
}
