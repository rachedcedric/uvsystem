package ci.uvmaster.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertNull;
import static org.junit.Assert.assertTrue;

import android.content.Context;
import android.content.ContextWrapper;

import org.junit.Test;

public class TrustedOriginTest {

    private static final String SERVER = "https://uvmaster.ci/m";
    private static final String LOCAL = "https://localhost";

    private static boolean trusted(String url) {
        return TrustedOrigin.isTrusted(url, SERVER, LOCAL);
    }

    @Test
    public void originIsSchemeHostAndNonDefaultPort() {
        assertEquals("https://uvmaster.ci", TrustedOrigin.originOf("https://uvmaster.ci/m/ventes?x=1#top"));
        assertEquals("http://localhost:8770", TrustedOrigin.originOf("http://localhost:8770/m"));
    }

    @Test
    public void defaultPortsAndCaseAreNormalised() {
        assertEquals("https://uvmaster.ci", TrustedOrigin.originOf("HTTPS://UVMaster.CI:443/"));
        assertEquals("http://localhost", TrustedOrigin.originOf("http://localhost:80/"));
    }

    @Test
    public void malformedOrUnsupportedUrlsHaveNoOrigin() {
        assertNull(TrustedOrigin.originOf(null));
        assertNull(TrustedOrigin.originOf(""));
        assertNull(TrustedOrigin.originOf("not a url"));
        assertNull(TrustedOrigin.originOf("about:blank"));
        assertNull(TrustedOrigin.originOf("javascript:alert(1)"));
        assertNull(TrustedOrigin.originOf("file:///sdcard/x.html"));
        assertNull(TrustedOrigin.originOf("data:text/html,<b>x</b>"));
        assertNull(TrustedOrigin.originOf("https:///m"));
    }

    @Test
    public void configuredServerAndLocalCapacitorOriginAreTrusted() {
        assertTrue(trusted("https://uvmaster.ci/m"));
        assertTrue(trusted("https://uvmaster.ci/m/ventes/create"));
        assertTrue(trusted("https://localhost/index.html"));
    }

    @Test
    public void developmentServerIsTrustedOnlyWhenConfigured() {
        assertTrue(TrustedOrigin.isTrusted("http://localhost:8770/m", "http://localhost:8770/m", LOCAL));
        assertFalse(trusted("http://localhost:8770/m"));
    }

    @Test
    public void lookAlikeHostsAreRejected() {
        assertFalse(trusted("https://uvmaster.ci.evil.example/m"));
        assertFalse(trusted("https://evil-uvmaster.ci/m"));
        assertFalse(trusted("https://uvmaster.cc/m"));
        assertFalse(trusted("https://sub.uvmaster.ci/m"));
        assertFalse(trusted("https://evil.example/https://uvmaster.ci/m"));
        assertFalse(trusted("https://evil.example/?u=https://uvmaster.ci"));
    }

    @Test
    public void credentialsInUrlCannotSpoofTheHost() {
        assertFalse(trusted("https://uvmaster.ci@evil.example/m"));
        assertFalse(trusted("https://uvmaster.ci:pass@evil.example/m"));
        assertFalse(trusted("https://user@uvmaster.ci/m"));
    }

    @Test
    public void otherSchemesAndPortsAreRejected() {
        assertFalse(trusted("http://uvmaster.ci/m"));
        assertFalse(trusted("https://uvmaster.ci:8443/m"));
        assertFalse(trusted("http://localhost/index.html"));
        assertFalse(trusted("javascript://uvmaster.ci/%0aalert(1)"));
        assertFalse(trusted("file:///android_asset/index.html"));
    }

    @Test
    public void nothingIsTrustedWithoutUrlOrWithoutTrustedOrigins() {
        assertFalse(trusted(null));
        assertFalse(trusted(""));
        assertFalse(trusted("about:blank"));
        assertFalse(TrustedOrigin.isTrusted("https://uvmaster.ci/m"));
        assertFalse(TrustedOrigin.isTrusted("https://uvmaster.ci/m", (String) null));
    }

    /* ------------------------------------------------------------------------------------------------------------
     * UvmasterNative.isSamePage : seule la page « Pas de connexion » locale peut demander retry().
     * Capacitor charge cette page sur getErrorUrl() = scheme://hostname/errorPath.
     * ------------------------------------------------------------------------------------------------------------ */

    private static final String ERROR_PAGE = "https://localhost/index.html";

    private static boolean samePage(String url) {
        return UvmasterNative.isSamePage(url, ERROR_PAGE);
    }

    private static void assertAllAccepted(String... urls) {
        for (String url : urls) {
            assertTrue("doit être accepté : " + url, samePage(url));
        }
    }

    private static void assertAllRejected(String... urls) {
        for (String url : urls) {
            assertFalse("doit être refusé : " + url, samePage(url));
        }
    }

    @Test
    public void samePageAcceptsTheExactErrorPageUrl() {
        assertTrue(samePage(ERROR_PAGE));
        assertTrue(UvmasterNative.isSamePage(ERROR_PAGE, ERROR_PAGE));
    }

    @Test
    public void samePageAcceptsLegitimateVariantsOfTheSameDocument() {
        assertAllAccepted(
            "https://LOCALHOST/index.html",
            "HTTPS://localhost/index.html",
            "https://localhost:443/index.html",
            "https://localhost/index.html?retry=1",
            "https://localhost/index.html?",
            "https://localhost/index.html#top",
            "https://localhost/index.html?a=b&c=d#frag",
            "https://localhost/index.html#/x/../y"
        );
    }

    @Test
    public void samePageRejectsDifferentPathsSuffixesAndPrefixes() {
        assertAllRejected(
            "https://localhost",
            "https://localhost/",
            "https://localhost/index.htm",
            "https://localhost/index.htmlx",
            "https://localhost/index.html.bak",
            "https://localhost/index.html@evil.example",
            "https://localhost/index.html;x=1",
            "https://localhost/index.html/",
            "https://localhost/index.html/extra",
            "https://localhost/xindex.html",
            "https://localhost/a/index.html",
            "https://localhost/m/index.html",
            "https://localhost/m",
            "https://localhost/INDEX.HTML",
            "https://localhost/Index.html"
        );
    }

    @Test
    public void samePageRejectsEncodedAndAmbiguousPaths() {
        // Le chemin est comparé brut : aucune forme encodée ou à segments relatifs n'équivaut à /index.html.
        assertAllRejected(
            "https://localhost/%69ndex.html",
            "https://localhost/index%2ehtml",
            "https://localhost/index.html%20",
            "https://localhost/index.html%00",
            "https://localhost/index.html%2f",
            "https://localhost/index.html%3fx",
            "https://localhost/%2findex.html",
            "https://localhost/..%2findex.html",
            "https://localhost/./index.html",
            "https://localhost/x/../index.html",
            "https://localhost//index.html",
            "https://localhost/index.html/.",
            "https://localhost/index.html/.."
        );
    }

    @Test
    public void samePageRejectsOtherOriginsPortsAndSchemes() {
        assertAllRejected(
            "https://localhost:8443/index.html",
            "https://localhost:80/index.html",
            "http://localhost/index.html",
            "http://localhost:443/index.html",
            "https://127.0.0.1/index.html",
            "https://[::1]/index.html",
            "https://localhost./index.html",
            "https://localhost.evil.example/index.html",
            "https://sub.localhost/index.html",
            "https://evil.example/index.html",
            "https://uvmaster.ci/index.html",
            "https://localhost@evil.example/index.html",
            "https://user@localhost/index.html",
            "https://user:pw@localhost/index.html",
            "https://evil.example/https://localhost/index.html",
            "https://evil.example/?u=https://localhost/index.html",
            "https://evil.example#https://localhost/index.html",
            "ftp://localhost/index.html",
            "capacitor://localhost/index.html",
            "ws://localhost/index.html"
        );
    }

    @Test
    public void samePageRejectsMalformedAndNonHttpUrls() {
        assertAllRejected(
            null,
            "",
            " ",
            " https://localhost/index.html",
            "https://localhost/index.html ",
            "https://localhost/index.html\n",
            "https://localhost/index.html\r\n",
            "https://localhost/\tindex.html",
            "https://local host/index.html",
            "https://localhost/index.html\\..\\x",
            "https://localhost\\index.html",
            "https:/localhost/index.html",
            "https:localhost/index.html",
            "//localhost/index.html",
            "/index.html",
            "index.html",
            "https://",
            "https:///index.html",
            "https://localhost:99999999999/index.html",
            "https://localhost:-1/index.html",
            "https://localhost:abc/index.html",
            "about:blank",
            "data:text/html,<script>UvmasterNative.retry()</script>",
            "blob:https://localhost/abc",
            "file:///android_asset/public/index.html",
            "javascript:retry()",
            "content://localhost/index.html"
        );
    }

    @Test
    public void samePageRejectsEverythingWhenTheErrorPageUrlIsMissingOrUnusable() {
        // getErrorUrl() nul, vide, mal formé, sans chemin ou non http(s) : rien n'est accepté, pas même l'URL identique.
        String[] unusablePages = {
            null,
            "",
            " ",
            "not a url",
            "https://localhost",
            "about:blank",
            "javascript:retry()",
            "file:///android_asset/public/index.html",
            "ftp://localhost/index.html",
            "https://user@localhost/index.html",
            "https://localhost/index.html\n"
        };

        for (String page : unusablePages) {
            assertFalse("page nulle ou inutilisable, URL identique : " + page, UvmasterNative.isSamePage(page, page));
            assertFalse("page nulle ou inutilisable, URL attendue : " + page, UvmasterNative.isSamePage(ERROR_PAGE, page));
        }

        assertFalse(UvmasterNative.isSamePage(null, null));
    }

    @Test
    public void samePageFollowsTheConfiguredErrorPageRatherThanAConstant() {
        // getErrorUrl() dépend de la configuration (errorPath, hostname, schéma) : la comparaison suit cette valeur.
        String[] configuredPages = {
            "https://localhost/offline.html",
            "http://localhost:8770/offline.html",
            "https://app.example/m/erreur.html"
        };

        for (String page : configuredPages) {
            assertTrue(page, UvmasterNative.isSamePage(page, page));
            assertTrue(page, UvmasterNative.isSamePage(page + "?x=1#y", page));
            assertFalse("l'ancienne page ne doit plus être acceptée : " + page, UvmasterNative.isSamePage(ERROR_PAGE, page));
            assertFalse("un voisin ne doit pas être accepté : " + page, UvmasterNative.isSamePage(page + "x", page));
            assertFalse("l'URL de ce serveur ne doit pas être acceptée : " + page, UvmasterNative.isSamePage("https://uvmaster.ci/m", page));
        }
    }

    /* ------------------------------------------------------------------------------------------------------------
     * Comportement réel du pont : qui peut déclencher retry() et appReady(), via un Host qui reproduit le câblage de
     * MainActivity (isTrustedUrl = origines de confiance, isErrorPageUrl = isSamePage sur getErrorUrl()).
     * ------------------------------------------------------------------------------------------------------------ */

    private static final class RecordingHost implements UvmasterNative.Host {
        String current;
        int retries;
        int ready;

        @Override
        public void post(Runnable action) {
            action.run();
        }

        @Override
        public String currentUrl() {
            return current;
        }

        @Override
        public boolean isTrustedUrl(String url) {
            return TrustedOrigin.isTrusted(url, SERVER, LOCAL);
        }

        @Override
        public boolean isErrorPageUrl(String url) {
            return UvmasterNative.isSamePage(url, ERROR_PAGE);
        }

        @Override
        public void onAppReady() {
            ready++;
        }

        @Override
        public void onRetry() {
            retries++;
        }
    }

    private static UvmasterNative bridgeFor(RecordingHost host) {
        // Le constructeur ne garde que le contexte d'application : un ContextWrapper qui se renvoie lui-même suffit.
        Context context = new ContextWrapper(null) {
            @Override
            public Context getApplicationContext() {
                return this;
            }
        };

        return new UvmasterNative(context, host);
    }

    /** Appelle retry() puis appReady() depuis une page dont l'URL est {@code url}. */
    private static RecordingHost callBothFrom(String url) {
        RecordingHost host = new RecordingHost();
        host.current = url;

        UvmasterNative bridge = bridgeFor(host);
        bridge.retry();
        bridge.appReady();

        return host;
    }

    @Test
    public void retryFiresFromTheLocalErrorPageOnlyOnce() {
        for (String url : new String[] {ERROR_PAGE, "https://localhost/index.html?x=1#y", "https://LOCALHOST:443/index.html"}) {
            RecordingHost host = new RecordingHost();
            host.current = url;
            bridgeFor(host).retry();

            assertEquals("retry() depuis " + url, 1, host.retries);
            assertEquals("retry() ne signale pas appReady : " + url, 0, host.ready);
        }
    }

    @Test
    public void retryIsRefusedFromTrustedServerPagesWhichIsWhatAnIframeWouldSee() {
        // Dans une iframe, la page « courante » vue par l'application est celle de la fenêtre principale : une page
        // fiable du serveur. Elle ne doit pas pouvoir relancer le chargement.
        for (String url : new String[] {SERVER, "https://uvmaster.ci/m/ventes/create", "https://uvmaster.ci/m/", "https://uvmaster.ci/m?x=1"}) {
            assertEquals("retry() depuis la page du serveur " + url, 0, callBothFrom(url).retries);
        }
    }

    @Test
    public void retryIsRefusedFromAnyOtherPageOrOrigin() {
        String[] urls = {
            "https://localhost/",
            "https://localhost/index.htm",
            "https://localhost/%69ndex.html",
            "https://localhost:8443/index.html",
            "http://localhost/index.html",
            "https://evil.example/index.html",
            "https://localhost@evil.example/index.html",
            "https://localhost.evil.example/index.html",
            "https://evil.example/https://localhost/index.html",
            "about:blank",
            "data:text/html,<script>UvmasterNative.retry()</script>",
            "blob:https://localhost/abc",
            "file:///android_asset/public/index.html",
            "javascript:retry()",
            "https://localhost/index.html\n",
            "",
            null
        };

        for (String url : urls) {
            assertEquals("retry() depuis " + url, 0, callBothFrom(url).retries);
        }
    }

    @Test
    public void appReadyKeepsItsOriginCheckAndIsUnchangedByTheRetryRestriction() {
        // appReady() reste accepté depuis le serveur configuré et l'origine locale de Capacitor, et seulement là.
        for (String url : new String[] {SERVER, "https://uvmaster.ci/m/ventes", ERROR_PAGE, "https://localhost/"}) {
            assertEquals("appReady() depuis " + url, 1, callBothFrom(url).ready);
        }

        for (String url : new String[] {"https://evil.example/m", "https://uvmaster.ci@evil.example/m", "https://localhost:8443/index.html",
                "http://uvmaster.ci/m", "about:blank", "data:text/html,x", "blob:https://uvmaster.ci/abc", "", null}) {
            assertEquals("appReady() depuis " + url, 0, callBothFrom(url).ready);
        }
    }
}
