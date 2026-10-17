package ci.uvmaster.app;

import android.app.Activity;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;

/**
 * Écran de chargement natif : posé au-dessus de la WebView dès la première image (même fond et même glyphe que le
 * splash système), il reste jusqu'à ce que le web signale que le contenu est prêt (appReady) ou qu'une page d'erreur
 * locale soit affichable. Il évite l'écran blanc des chargements lents et reste en état d'erreur, avec un bouton
 * Réessayer, si rien n'arrive.
 *
 * Tout s'exécute sur le thread UI. Les phases et seuils de temps sont dans LoadingState.
 */
final class LoadingOverlay {

    /** Délai laissé au web pour appeler appReady() après la fin du chargement de la page avant de libérer l'écran. */
    static final long PAGE_FINISHED_GRACE_MS = 3_000;

    private static final long FADE_OUT_MS = 200;

    interface Listener {
        /** L'utilisateur a appuyé sur Réessayer. */
        void onRetryRequested();
    }

    private final Activity activity;
    private final Listener listener;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable tick = this::onTick;
    private final Runnable graceElapsed = this::ready;
    private final LoadingState state = new LoadingState(SystemClock.elapsedRealtime());

    private View root;

    LoadingOverlay(Activity activity, Listener listener) {
        this.activity = activity;
        this.listener = listener;
    }

    /** À appeler dans onCreate, avant le premier affichage de l'activité : l'écran fait partie de la première image. */
    void attach() {
        build();
        schedule();
    }

    /** Nouvelle tentative (bouton, ou page d'erreur locale) : l'écran revient, y compris s'il avait déjà disparu. */
    void show() {
        handler.removeCallbacks(graceElapsed);
        state.restart(SystemClock.elapsedRealtime());

        if (root != null) {
            root.animate().cancel();
            root.setAlpha(1f);
        } else {
            build();
        }

        render();
        schedule();
    }

    /** Le contenu utile (ou la page d'erreur locale) est prêt : l'écran disparaît en fondu. Sans effet s'il est déjà parti. */
    void ready() {
        if (!state.isVisible()) {
            return;
        }

        state.ready();
        handler.removeCallbacks(tick);
        handler.removeCallbacks(graceElapsed);

        final View view = root;
        root = null;

        if (view == null) {
            return;
        }

        view.animate().cancel();
        view.animate().alpha(0f).setDuration(FADE_OUT_MS).withEndAction(() -> {
            ViewGroup parent = (ViewGroup) view.getParent();
            if (parent != null) {
                parent.removeView(view);
            }
        }).start();
    }

    /**
     * La page de confiance a fini de charger. Le web appelle normalement appReady() de lui-même ; si ce signal ne vient
     * pas (page serveur sans notre JavaScript, erreur de script), l'écran est libéré après un court délai plutôt que
     * de rester indéfiniment, ou de passer à tort en état d'erreur alors que la page est affichée.
     */
    void pageLoaded() {
        if (state.isVisible()) {
            handler.removeCallbacks(graceElapsed);
            handler.postDelayed(graceElapsed, PAGE_FINISHED_GRACE_MS);
        }
    }

    /** Thème clair/sombre changé pendant que l'écran est affiché : il est reconstruit pour relire les couleurs. */
    void onConfigurationChanged() {
        if (state.isVisible() && root != null) {
            build();
        }
    }

    void destroy() {
        handler.removeCallbacks(tick);
        handler.removeCallbacks(graceElapsed);

        if (root != null) {
            root.animate().cancel();
        }
    }

    private void build() {
        ViewGroup content = activity.findViewById(android.R.id.content);

        if (root != null) {
            content.removeView(root);
        }

        root = LayoutInflater.from(activity).inflate(R.layout.loading_overlay, content, false);
        root.findViewById(R.id.uvmaster_loading_retry).setOnClickListener(view -> listener.onRetryRequested());
        root.addOnLayoutChangeListener((view, left, top, right, bottom, oldLeft, oldTop, oldRight, oldBottom) -> centerOnWindow());
        content.addView(root);

        render();
    }

    /**
     * Le splash système est centré sur l'écran entier, alors que la zone de contenu exclut les barres système : son centre
     * est décalé de (barre d'état − barre de navigation) / 2 (environ 12 dp sur l'émulateur). Le contenu est donc décalé de
     * la valeur mesurée, jamais d'une constante, pour que le glyphe ne saute pas au passage du splash à cet écran, quel
     * que soit l'appareil (barres, encoche, navigation par gestes ou par boutons).
     */
    private void centerOnWindow() {
        if (root == null) {
            return;
        }

        View window = activity.getWindow().getDecorView();
        int[] position = new int[2];
        root.getLocationInWindow(position);

        float shift = window.getHeight() / 2f - (position[1] + root.getHeight() / 2f);
        root.findViewById(R.id.uvmaster_loading_content).setTranslationY(shift);
    }

    private void render() {
        if (root == null) {
            return;
        }

        LoadingState.Phase phase = state.phase();
        boolean waiting = phase == LoadingState.Phase.LOADING || phase == LoadingState.Phase.SLOW;

        root.findViewById(R.id.uvmaster_loading_bar).setVisibility(waiting ? View.VISIBLE : View.GONE);
        root.findViewById(R.id.uvmaster_loading_slow).setVisibility(phase == LoadingState.Phase.SLOW ? View.VISIBLE : View.GONE);
        root.findViewById(R.id.uvmaster_loading_failed).setVisibility(phase == LoadingState.Phase.FAILED ? View.VISIBLE : View.GONE);
    }

    private void onTick() {
        state.update(SystemClock.elapsedRealtime());
        render();
        schedule();
    }

    private void schedule() {
        handler.removeCallbacks(tick);

        long delay = state.msUntilNextChange(SystemClock.elapsedRealtime());

        if (delay >= 0) {
            handler.postDelayed(tick, delay);
        }
    }
}
