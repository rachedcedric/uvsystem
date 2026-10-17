package ci.uvmaster.app;

import android.animation.ValueAnimator;
import android.app.Activity;
import android.os.Handler;
import android.os.Looper;
import android.os.SystemClock;
import android.provider.Settings;
import android.transition.TransitionManager;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.LinearInterpolator;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.List;

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

    // Apparition : le glyphe est déjà là (il vient du splash système) ; le dégradé, puis le nom, la baseline et les points
    // arrivent en fondu juste dessous. Rien ne bouge.
    private static final long GRADIENT_FADE_MS = 700;
    private static final long BRAND_FADE_DELAY_MS = 200;
    private static final long BRAND_FADE_MS = 450;
    private static final long TAGLINE_FADE_MS = 160;
    private static final float TAGLINE_ALPHA = 0.8f;
    private static final long FAILED_FADE_MS = 250;

    // Pulsation des trois points : un cycle, chaque point décalé du précédent.
    private static final long DOT_CYCLE_MS = 1_200;
    private static final long DOT_STAGGER_MS = 180;
    private static final float DOT_MIN_ALPHA = 0.22f;
    private static final float DOT_MIN_SCALE = 0.85f;

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

    private final List<ValueAnimator> dotAnimators = new ArrayList<>();

    private View root;
    private int taglineRes = R.string.loading_tagline;

    LoadingOverlay(Activity activity, Listener listener) {
        this.activity = activity;
        this.listener = listener;
    }

    /** À appeler dans onCreate, avant le premier affichage de l'activité : l'écran fait partie de la première image. */
    void attach() {
        build(true);
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
            build(true);
        }

        render(true);
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
        stopDots();

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
            build(false);
        }
    }

    void destroy() {
        handler.removeCallbacks(tick);
        handler.removeCallbacks(graceElapsed);
        stopDots();

        if (root != null) {
            root.animate().cancel();
        }
    }

    /** @param animateIn vrai au premier affichage et à chaque retour de l'écran ; faux quand il est seulement reconstruit (thème). */
    private void build(boolean animateIn) {
        ViewGroup content = activity.findViewById(android.R.id.content);

        stopDots();

        if (root != null) {
            content.removeView(root);
        }

        root = LayoutInflater.from(activity).inflate(R.layout.loading_overlay, content, false);
        root.findViewById(R.id.uvmaster_loading_retry).setOnClickListener(view -> listener.onRetryRequested());
        root.addOnLayoutChangeListener((view, left, top, right, bottom, oldLeft, oldTop, oldRight, oldBottom) -> centerOnWindow());
        content.addView(root);

        // La vue neuve porte le texte de baseline par défaut : on repart de là, sans fondu.
        taglineRes = R.string.loading_tagline;
        render(false);

        View gradient = root.findViewById(R.id.uvmaster_loading_gradient);
        View brand = root.findViewById(R.id.uvmaster_loading_brand);

        if (animateIn && animationsEnabled()) {
            // Le glyphe n'est volontairement pas animé : c'est celui du splash système, il reste exactement où il est.
            brand.setAlpha(0f);
            gradient.animate().alpha(1f).setDuration(GRADIENT_FADE_MS).start();
            brand.animate().alpha(1f).setStartDelay(BRAND_FADE_DELAY_MS).setDuration(BRAND_FADE_MS).start();
        } else {
            gradient.setAlpha(1f);
            brand.setAlpha(1f);
        }
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

    /** @param animate vrai pour un changement d'état en cours d'affichage (fondus) ; faux pour dessiner l'état tel quel. */
    private void render(boolean animate) {
        if (root == null) {
            return;
        }

        LoadingState.Phase phase = state.phase();
        boolean waiting = phase == LoadingState.Phase.LOADING || phase == LoadingState.Phase.SLOW;
        View failed = root.findViewById(R.id.uvmaster_loading_failed);
        boolean wasFailed = failed.getVisibility() == View.VISIBLE;

        arrangeForFailure(phase == LoadingState.Phase.FAILED, animate);

        // Attente : nom, baseline et points. Échec : le message et « Réessayer » les remplacent, à la même place.
        root.findViewById(R.id.uvmaster_loading_brand).setVisibility(waiting ? View.VISIBLE : View.GONE);
        failed.setVisibility(phase == LoadingState.Phase.FAILED ? View.VISIBLE : View.GONE);

        if (phase == LoadingState.Phase.FAILED && !wasFailed && animate && animationsEnabled()) {
            failed.setAlpha(0f);
            failed.animate().alpha(1f).setDuration(FAILED_FADE_MS).start();
        } else {
            failed.setAlpha(1f);
        }

        // La baseline cède la place à « Connexion lente… » : elle ne s'ajoute pas, l'écran reste aussi sobre.
        setTagline(phase == LoadingState.Phase.SLOW ? R.string.loading_slow : R.string.loading_tagline, animate);

        if (waiting) {
            startDots();
        } else {
            stopDots();
        }
    }

    /**
     * En attente, le glyphe est au centre exact de l'écran et le texte tient dans la moitié basse, de hauteur fixe. En échec,
     * « Réessayer » est la seule action possible : sur un petit écran (360 × 640 dp) ou avec une grande police, le bloc
     * d'erreur est plus haut que cette moitié et le bouton serait coupé, voire invisible (une mise en page linéaire écrase
     * la hauteur d'un enfant qui dépasse). La moitié basse prend alors la hauteur de son contenu et le groupe
     * glyphe + message est centré sur l'écran : tout reste visible. La continuité avec le splash n'a plus d'importance ici.
     */
    private void arrangeForFailure(boolean failed, boolean animate) {
        View top = root.findViewById(R.id.uvmaster_loading_top);
        View lower = root.findViewById(R.id.uvmaster_loading_lower);
        LinearLayout.LayoutParams topParams = (LinearLayout.LayoutParams) top.getLayoutParams();
        LinearLayout.LayoutParams lowerParams = (LinearLayout.LayoutParams) lower.getLayoutParams();

        float weight = failed ? 0f : 1f;
        int lowerHeight = failed ? ViewGroup.LayoutParams.WRAP_CONTENT : 0;

        if (topParams.weight == weight && lowerParams.height == lowerHeight) {
            return;
        }

        if (animate && animationsEnabled()) {
            TransitionManager.beginDelayedTransition((ViewGroup) root.findViewById(R.id.uvmaster_loading_content));
        }

        topParams.weight = weight;
        lowerParams.weight = weight;
        lowerParams.height = lowerHeight;
        top.setLayoutParams(topParams);
        lower.setLayoutParams(lowerParams);
    }

    private void setTagline(int res, boolean animate) {
        if (root == null || res == taglineRes) {
            return;
        }

        taglineRes = res;
        TextView tagline = root.findViewById(R.id.uvmaster_loading_tagline);

        tagline.animate().cancel();

        if (!animate || !animationsEnabled()) {
            tagline.setText(res);
            tagline.setAlpha(TAGLINE_ALPHA);

            return;
        }

        tagline.animate().alpha(0f).setDuration(TAGLINE_FADE_MS).withEndAction(() -> {
            tagline.setText(res);
            tagline.animate().alpha(TAGLINE_ALPHA).setDuration(TAGLINE_FADE_MS * 2).start();
        }).start();
    }

    private void startDots() {
        if (root == null || !dotAnimators.isEmpty()) {
            return;
        }

        LinearLayout dots = root.findViewById(R.id.uvmaster_loading_dots);

        for (int i = 0; i < dots.getChildCount(); i++) {
            View dot = dots.getChildAt(i);

            if (!animationsEnabled()) {
                // « Réduire les animations » : points immobiles, à mi-intensité.
                dot.setAlpha(0.6f);

                continue;
            }

            dot.setAlpha(DOT_MIN_ALPHA);
            dot.setScaleX(DOT_MIN_SCALE);
            dot.setScaleY(DOT_MIN_SCALE);

            ValueAnimator pulse = ValueAnimator.ofFloat(0f, 1f);
            pulse.setDuration(DOT_CYCLE_MS);
            pulse.setStartDelay(i * DOT_STAGGER_MS);
            pulse.setRepeatCount(ValueAnimator.INFINITE);
            pulse.setInterpolator(new LinearInterpolator());
            pulse.addUpdateListener(animator -> {
                float t = (float) animator.getAnimatedValue();
                // Montée rapide (40 % du cycle), descente plus lente : 0 → 1 → 0.
                float wave = t < 0.4f ? t / 0.4f : 1f - (t - 0.4f) / 0.6f;
                float scale = DOT_MIN_SCALE + (1.15f - DOT_MIN_SCALE) * wave;

                dot.setAlpha(DOT_MIN_ALPHA + (1f - DOT_MIN_ALPHA) * wave);
                dot.setScaleX(scale);
                dot.setScaleY(scale);
            });

            dotAnimators.add(pulse);
            pulse.start();
        }
    }

    private void stopDots() {
        for (ValueAnimator pulse : dotAnimators) {
            pulse.cancel();
        }

        dotAnimators.clear();
    }

    /** Respecte « Supprimer les animations » d'Android (échelle de durée à 0) : alors tout apparaît tel quel, sans mouvement. */
    private boolean animationsEnabled() {
        try {
            return Settings.Global.getFloat(activity.getContentResolver(), Settings.Global.ANIMATOR_DURATION_SCALE, 1f) != 0f;
        } catch (RuntimeException unavailable) {
            return true;
        }
    }

    private void onTick() {
        state.update(SystemClock.elapsedRealtime());
        render(true);
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
