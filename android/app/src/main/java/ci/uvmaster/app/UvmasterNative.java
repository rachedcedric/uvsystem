package ci.uvmaster.app;

import android.content.Context;
import android.webkit.JavascriptInterface;

import java.net.URI;
import java.util.List;

/**
 * Interface native exposée à la page web (window.UvmasterNative).
 *
 * hasFirebase() dit si cet APK a été construit avec google-services.json. Sans
 * cela, l'appel PushNotifications.register() du plugin lève une exception non
 * rattrapable et fait planter toute l'application : la page ne doit donc
 * l'appeler que lorsque cette méthode renvoie true.
 *
 * appReady() et retry() pilotent l'écran de chargement natif. L'interface est injectée dans TOUTES les pages de la
 * WebView, et dans tous leurs cadres : avant d'agir, chaque appel vérifie, sur le thread UI, l'URL de la page courante.
 * - appReady() exige une origine de confiance (le serveur configuré ou l'origine locale de Capacitor) ;
 * - retry() est plus strict : il n'est accepté que depuis la page « Pas de connexion » locale elle-même (isErrorPageUrl),
 *   la seule qui le demande. Android ne dit pas quel cadre appelle : une iframe d'une page du serveur verrait sinon
 *   l'URL de la fenêtre principale, fiable, et pourrait relancer le chargement. La page d'erreur est statique et n'a
 *   aucune iframe.
 * Une page d'une autre origine est ignorée dans tous les cas.
 */
public class UvmasterNative {

    /** Ce que la page web peut demander à l'application. Implémentée par MainActivity. */
    public interface Host {
        /** Exécute l'action sur le thread UI (les méthodes @JavascriptInterface s'exécutent sur un thread dédié). */
        void post(Runnable action);

        /** URL de la page affichée par la WebView. À appeler sur le thread UI. */
        String currentUrl();

        /** Vrai si l'URL appartient au serveur configuré ou à l'origine locale de Capacitor (page d'erreur). */
        boolean isTrustedUrl(String url);

        /** Vrai si l'URL est exactement celle de la page « Pas de connexion » locale de Capacitor (getErrorUrl). */
        boolean isErrorPageUrl(String url);

        /** Le contenu utile (ou la page d'erreur locale) est prêt à être affiché. */
        void onAppReady();

        /** Recharger l'URL réellement configurée, sans toucher aux cookies ni à la session. */
        void onRetry();
    }

    private final Context context;
    private final Host host;

    public UvmasterNative(Context context, Host host) {
        this.context = context.getApplicationContext();
        this.host = host;
    }

    @JavascriptInterface
    public boolean hasFirebase() {
        try {
            // Réflexion : la classe vient de firebase-messaging, absente du classpath de compilation de l'app.
            Object apps = Class.forName("com.google.firebase.FirebaseApp")
                    .getMethod("getApps", Context.class)
                    .invoke(null, context);

            return apps instanceof List && !((List<?>) apps).isEmpty();
        } catch (Throwable ignored) {
            return false;
        }
    }

    /** Signal explicite du web : le contenu utile (ou la page d'erreur locale) peut remplacer l'écran de chargement. */
    @JavascriptInterface
    public void appReady() {
        host.post(() -> {
            if (host.isTrustedUrl(host.currentUrl())) {
                host.onAppReady();
            }
        });
    }

    /** Nouvelle tentative depuis la page d'erreur locale : recharge l'URL du serveur configurée dans l'application. */
    @JavascriptInterface
    public void retry() {
        host.post(() -> {
            if (host.isErrorPageUrl(host.currentUrl())) {
                host.onRetry();
            }
        });
    }

    /**
     * Vrai si {@code url} désigne le même document que {@code pageUrl} : même origine (TrustedOrigin : http/https, sans
     * identifiants, hôte et port identiques) ET même chemin, comparé tel quel, sans décodage ni normalisation. La requête
     * et le fragment ne changent pas de document et sont ignorés. Toute URL nulle, mal formée ou sans chemin est refusée.
     * Java pur : testable sur la JVM.
     */
    static boolean isSamePage(String url, String pageUrl) {
        if (!TrustedOrigin.isTrusted(url, pageUrl)) {
            return false;
        }

        String path = rawPathOf(url);

        return path != null && !path.isEmpty() && path.equals(rawPathOf(pageUrl));
    }

    private static String rawPathOf(String url) {
        try {
            return new URI(url).getRawPath();
        } catch (Exception malformed) {
            return null;
        }
    }
}
