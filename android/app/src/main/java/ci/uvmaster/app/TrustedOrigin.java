package ci.uvmaster.app;

import java.net.URI;
import java.util.Locale;

/**
 * Calcul d'origine (schéma + hôte + port) d'une URL, utilisé pour décider si une page peut appeler le pont natif
 * (UvmasterNative). Java pur, sans dépendance Android : testable sur la JVM.
 *
 * Volontairement strict : toute URL ambiguë ou mal formée donne null, donc « non fiable ». Sont refusées les URL qui
 * ne sont pas en http/https, sans hôte, ou avec des identifiants (« https://hôte-fiable@autre-hôte/ »).
 */
final class TrustedOrigin {

    private TrustedOrigin() {
    }

    /** scheme://hôte[:port] en minuscules, port par défaut omis ; null si l'URL n'est pas une URL http(s) sûre. */
    static String originOf(String url) {
        if (url == null || url.isEmpty()) {
            return null;
        }

        URI uri;

        try {
            uri = new URI(url);
        } catch (Exception malformed) {
            return null;
        }

        String scheme = uri.getScheme();
        String host = uri.getHost();

        if (scheme == null || host == null || uri.getUserInfo() != null) {
            return null;
        }

        scheme = scheme.toLowerCase(Locale.ROOT);

        if (!scheme.equals("http") && !scheme.equals("https")) {
            return null;
        }

        int port = uri.getPort();
        boolean defaultPort = port == -1 || (scheme.equals("http") && port == 80) || (scheme.equals("https") && port == 443);

        return scheme + "://" + host.toLowerCase(Locale.ROOT) + (defaultPort ? "" : ":" + port);
    }

    /** Vrai si l'origine de l'URL est exactement l'une des origines de confiance (null ignorées). */
    static boolean isTrusted(String url, String... trustedUrls) {
        String origin = originOf(url);

        if (origin == null) {
            return false;
        }

        for (String trusted : trustedUrls) {
            if (origin.equals(originOf(trusted))) {
                return true;
            }
        }

        return false;
    }
}
