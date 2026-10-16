package ci.uvmaster.app;

import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;

import com.getcapacitor.BridgeActivity;
import com.getcapacitor.BridgeWebViewClient;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

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
}
