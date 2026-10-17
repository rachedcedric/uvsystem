package ci.uvmaster.app;

/**
 * Phases de l'écran de chargement natif et seuils de temps. Aucune dépendance Android : la logique est testable sur
 * la JVM (voir LoadingStateTest). L'horloge est fournie par l'appelant (SystemClock.elapsedRealtime en production).
 */
final class LoadingState {

    enum Phase {
        /** Chargement normal : glyphe et progression. */
        LOADING,
        /** Plus de 4 s : « Connexion lente… » s'ajoute. */
        SLOW,
        /** Plus de 12 s sans succès : message d'erreur et bouton Réessayer. */
        FAILED,
        /** Le web a signalé que le contenu est prêt : l'écran de chargement disparaît. */
        READY
    }

    static final long SLOW_AFTER_MS = 4_000;
    static final long FAILED_AFTER_MS = 12_000;

    private Phase phase = Phase.LOADING;
    private long startedAtMs;

    LoadingState(long nowMs) {
        this.startedAtMs = nowMs;
    }

    Phase phase() {
        return phase;
    }

    boolean isVisible() {
        return phase != Phase.READY;
    }

    /** Fait avancer la phase selon l'heure. Sans effet une fois READY : un signal « prêt » n'est jamais annulé par le temps. */
    Phase update(long nowMs) {
        if (phase == Phase.READY) {
            return phase;
        }

        long elapsed = nowMs - startedAtMs;

        if (elapsed >= FAILED_AFTER_MS) {
            phase = Phase.FAILED;
        } else if (elapsed >= SLOW_AFTER_MS) {
            phase = Phase.SLOW;
        } else {
            phase = Phase.LOADING;
        }

        return phase;
    }

    /** Le contenu utile (ou la page d'erreur locale) est prêt. Valable depuis n'importe quelle phase, y compris FAILED. */
    void ready() {
        phase = Phase.READY;
    }

    /** Nouvelle tentative : tout repart de zéro, y compris depuis READY (la page d'erreur relance un chargement). */
    void restart(long nowMs) {
        startedAtMs = nowMs;
        phase = Phase.LOADING;
    }

    /** Délai avant le prochain changement de phase, ou -1 s'il n'y en a plus (FAILED ou READY). */
    long msUntilNextChange(long nowMs) {
        long elapsed = nowMs - startedAtMs;

        switch (phase) {
            case LOADING:
                return Math.max(0, SLOW_AFTER_MS - elapsed);
            case SLOW:
                return Math.max(0, FAILED_AFTER_MS - elapsed);
            default:
                return -1;
        }
    }
}
