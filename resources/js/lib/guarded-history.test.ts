import { describe, expect, it, vi } from 'vitest';
import { HistoryTraversalCoordinator } from '@/lib/guarded-history';
import type { PendingHistoryTraversal } from '@/lib/guarded-history';

function state(position: number) {
    return { __simrs_history_position__: position, page: `page-${position}` };
}

function controls() {
    return {
        stop: vi.fn(),
        go: vi.fn(),
    };
}

describe('HistoryTraversalCoordinator', () => {
    it('marks current, pushed, and replaced entries without storing form data', () => {
        const coordinator = new HistoryTraversalCoordinator(null);

        expect(coordinator.currentState({ page: 'initial' })).toEqual({
            page: 'initial',
            __simrs_history_position__: 0,
        });
        expect(coordinator.pushedState({ page: 'nursing' })).toEqual({
            page: 'nursing',
            __simrs_history_position__: 1,
        });
        expect(coordinator.currentState({ page: 'nursing-refreshed' })).toEqual(
            {
                page: 'nursing-refreshed',
                __simrs_history_position__: 1,
            },
        );
        expect(coordinator.pushedState({ page: 'medical' })).toEqual({
            page: 'medical',
            __simrs_history_position__: 2,
        });
    });

    it('restores a dirty back traversal before exposing it to Inertia', () => {
        const coordinator = new HistoryTraversalCoordinator(state(2));
        const observed: PendingHistoryTraversal[] = [];
        coordinator.registerGuard((traversal) => observed.push(traversal));

        const intercepted = controls();
        coordinator.handlePopState(state(1), intercepted);

        expect(intercepted.stop).toHaveBeenCalledOnce();
        expect(intercepted.go).toHaveBeenCalledWith(1);
        expect(observed).toHaveLength(0);

        const restored = controls();
        coordinator.handlePopState(state(2), restored);

        expect(restored.stop).toHaveBeenCalledOnce();
        expect(observed).toEqual([{ fromPosition: 2, toPosition: 1 }]);

        const replay = vi.fn();
        coordinator.continue(observed[0], replay);
        expect(replay).toHaveBeenCalledWith(-1);

        const allowed = controls();
        coordinator.handlePopState(state(1), allowed);
        expect(allowed.stop).not.toHaveBeenCalled();
        expect(allowed.go).not.toHaveBeenCalled();
    });

    it('handles forward traversal and permits a fresh choice after stay', () => {
        const coordinator = new HistoryTraversalCoordinator(state(1));
        const observed: PendingHistoryTraversal[] = [];
        coordinator.registerGuard((traversal) => observed.push(traversal));

        coordinator.handlePopState(state(2), controls());
        const firstRestore = controls();
        coordinator.handlePopState(state(1), firstRestore);

        expect(firstRestore.stop).toHaveBeenCalledOnce();
        expect(observed[0]).toEqual({
            fromPosition: 1,
            toPosition: 2,
        });

        coordinator.cancel(observed[0]);

        const secondAttempt = controls();
        coordinator.handlePopState(state(2), secondAttempt);
        expect(secondAttempt.go).toHaveBeenCalledWith(-1);

        coordinator.handlePopState(state(1), controls());
        expect(observed).toHaveLength(2);

        const replay = vi.fn();
        coordinator.continue(observed[1], replay);
        expect(replay).toHaveBeenCalledWith(1);
    });

    it('does not intercept unmarked or unguarded history entries', () => {
        const coordinator = new HistoryTraversalCoordinator(state(1));

        const unguarded = controls();
        coordinator.handlePopState(state(0), unguarded);
        expect(unguarded.stop).not.toHaveBeenCalled();

        coordinator.registerGuard(vi.fn());
        const unmarked = controls();
        coordinator.handlePopState({ page: 'outside-session' }, unmarked);
        expect(unmarked.stop).not.toHaveBeenCalled();
        expect(unmarked.go).not.toHaveBeenCalled();
    });
});
