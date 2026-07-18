const POSITION_KEY = '__simrs_history_position__';

type HistoryState = Record<string, unknown>;

export type PendingHistoryTraversal = Readonly<{
    fromPosition: number;
    toPosition: number;
}>;

type TraversalControls = {
    stop: () => void;
    go: (delta: number) => void;
};

type RestoreState = PendingHistoryTraversal & {
    notify: boolean;
};

function stateObject(state: unknown): HistoryState {
    return typeof state === 'object' && state !== null && !Array.isArray(state)
        ? (state as HistoryState)
        : {};
}

function historyPosition(state: unknown): number | null {
    const value = stateObject(state)[POSITION_KEY];

    return Number.isInteger(value) && (value as number) >= 0
        ? (value as number)
        : null;
}

function sameTraversal(
    left: PendingHistoryTraversal | null,
    right: PendingHistoryTraversal,
): boolean {
    return (
        left?.fromPosition === right.fromPosition &&
        left.toPosition === right.toPosition
    );
}

export class HistoryTraversalCoordinator {
    private currentPosition: number;
    private guard: ((traversal: PendingHistoryTraversal) => void) | null = null;
    private restoring: RestoreState | null = null;
    private heldTraversal: PendingHistoryTraversal | null = null;
    private bypassTargetPosition: number | null = null;

    constructor(currentState: unknown) {
        this.currentPosition = historyPosition(currentState) ?? 0;
    }

    currentState(state: unknown): HistoryState {
        return {
            ...stateObject(state),
            [POSITION_KEY]: this.currentPosition,
        };
    }

    pushedState(state: unknown): HistoryState {
        this.currentPosition += 1;

        return this.currentState(state);
    }

    registerGuard(
        guard: (traversal: PendingHistoryTraversal) => void,
    ): () => void {
        this.guard = guard;

        return () => {
            if (this.guard === guard) {
                this.guard = null;
                this.heldTraversal = null;
            }
        };
    }

    handlePopState(state: unknown, controls: TraversalControls): void {
        const targetPosition = historyPosition(state);

        // A missing marker represents a navigation created outside this
        // in-session Inertia history. The native beforeunload boundary remains
        // responsible for cross-document exits.
        if (targetPosition === null) {
            return;
        }

        if (this.restoring && targetPosition === this.restoring.fromPosition) {
            controls.stop();
            this.currentPosition = targetPosition;

            const restored = this.restoring;
            this.restoring = null;

            if (restored.notify) {
                this.heldTraversal = {
                    fromPosition: restored.fromPosition,
                    toPosition: restored.toPosition,
                };
                this.guard?.(this.heldTraversal);
            }

            return;
        }

        if (this.bypassTargetPosition === targetPosition) {
            this.bypassTargetPosition = null;
            this.currentPosition = targetPosition;

            return;
        }

        if (!this.guard || targetPosition === this.currentPosition) {
            this.currentPosition = targetPosition;

            return;
        }

        controls.stop();

        const traversal = {
            fromPosition: this.currentPosition,
            toPosition: targetPosition,
        };

        this.restoring = {
            ...traversal,
            notify: this.heldTraversal === null,
        };
        controls.go(traversal.fromPosition - traversal.toPosition);
    }

    cancel(traversal: PendingHistoryTraversal): void {
        if (sameTraversal(this.heldTraversal, traversal)) {
            this.heldTraversal = null;
        }
    }

    continue(
        traversal: PendingHistoryTraversal,
        go: (delta: number) => void,
    ): void {
        if (!sameTraversal(this.heldTraversal, traversal)) {
            return;
        }

        this.heldTraversal = null;
        this.bypassTargetPosition = traversal.toPosition;
        go(traversal.toPosition - this.currentPosition);
    }
}

let coordinator: HistoryTraversalCoordinator | null = null;
let installed = false;

export function installGuardedHistory(): void {
    if (installed || typeof window === 'undefined') {
        return;
    }

    installed = true;

    const browserHistory = window.history;
    const originalPushState = browserHistory.pushState.bind(browserHistory);
    const originalReplaceState =
        browserHistory.replaceState.bind(browserHistory);
    coordinator = new HistoryTraversalCoordinator(browserHistory.state);

    originalReplaceState(
        coordinator.currentState(browserHistory.state),
        '',
        window.location.href,
    );

    browserHistory.pushState = (data, unused, url) => {
        originalPushState(coordinator?.pushedState(data) ?? data, unused, url);
    };
    browserHistory.replaceState = (data, unused, url) => {
        originalReplaceState(
            coordinator?.currentState(data) ?? data,
            unused,
            url,
        );
    };

    window.addEventListener(
        'popstate',
        (event) => {
            coordinator?.handlePopState(event.state, {
                stop: () => event.stopImmediatePropagation(),
                go: (delta) => browserHistory.go(delta),
            });
        },
        true,
    );
}

export function registerDirtyHistoryGuard(
    guard: (traversal: PendingHistoryTraversal) => void,
): () => void {
    installGuardedHistory();

    return coordinator?.registerGuard(guard) ?? (() => undefined);
}

export function cancelHistoryTraversal(
    traversal: PendingHistoryTraversal,
): void {
    coordinator?.cancel(traversal);
}

export function continueHistoryTraversal(
    traversal: PendingHistoryTraversal,
): void {
    coordinator?.continue(traversal, (delta) => window.history.go(delta));
}
