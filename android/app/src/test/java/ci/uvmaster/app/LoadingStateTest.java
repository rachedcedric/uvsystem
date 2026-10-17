package ci.uvmaster.app;

import static org.junit.Assert.assertEquals;
import static org.junit.Assert.assertFalse;
import static org.junit.Assert.assertTrue;

import org.junit.Test;

public class LoadingStateTest {

    private static final long T0 = 100_000;

    @Test
    public void startsLoadingAndVisible() {
        LoadingState state = new LoadingState(T0);

        assertEquals(LoadingState.Phase.LOADING, state.phase());
        assertTrue(state.isVisible());
    }

    @Test
    public void becomesSlowAfterFourSecondsAndFailedAfterTwelve() {
        LoadingState state = new LoadingState(T0);

        assertEquals(LoadingState.Phase.LOADING, state.update(T0 + 3_999));
        assertEquals(LoadingState.Phase.SLOW, state.update(T0 + 4_000));
        assertEquals(LoadingState.Phase.SLOW, state.update(T0 + 11_999));
        assertEquals(LoadingState.Phase.FAILED, state.update(T0 + 12_000));
        assertEquals(LoadingState.Phase.FAILED, state.update(T0 + 60_000));
        assertTrue(state.isVisible());
    }

    @Test
    public void readyHidesFromAnyPhaseAndIsNotCancelledByTime() {
        LoadingState loading = new LoadingState(T0);
        loading.ready();
        assertFalse(loading.isVisible());
        assertEquals(LoadingState.Phase.READY, loading.update(T0 + 30_000));

        LoadingState failed = new LoadingState(T0);
        failed.update(T0 + 13_000);
        failed.ready();
        assertEquals(LoadingState.Phase.READY, failed.phase());
        assertFalse(failed.isVisible());
    }

    @Test
    public void readyIsIdempotent() {
        LoadingState state = new LoadingState(T0);

        state.ready();
        state.ready();

        assertEquals(LoadingState.Phase.READY, state.phase());
    }

    @Test
    public void restartResetsTheClockFromFailedAndFromReady() {
        LoadingState state = new LoadingState(T0);
        state.update(T0 + 13_000);

        state.restart(T0 + 13_000);
        assertEquals(LoadingState.Phase.LOADING, state.phase());
        assertEquals(LoadingState.Phase.LOADING, state.update(T0 + 13_000 + 3_999));
        assertEquals(LoadingState.Phase.SLOW, state.update(T0 + 13_000 + 4_000));

        state.ready();
        state.restart(T0 + 40_000);
        assertTrue(state.isVisible());
        assertEquals(LoadingState.Phase.LOADING, state.update(T0 + 40_001));
    }

    @Test
    public void delayUntilNextChangeFollowsThePhase() {
        LoadingState state = new LoadingState(T0);

        assertEquals(4_000, state.msUntilNextChange(T0));
        assertEquals(1_000, state.msUntilNextChange(T0 + 3_000));

        state.update(T0 + 4_000);
        assertEquals(8_000, state.msUntilNextChange(T0 + 4_000));

        state.update(T0 + 12_000);
        assertEquals(-1, state.msUntilNextChange(T0 + 12_000));

        state.ready();
        assertEquals(-1, state.msUntilNextChange(T0 + 12_001));
    }
}
