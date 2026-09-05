import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const warningMessage =
    'There is unsaved clinical documentation. Leave this page and discard the changes?';

export function useUnsavedChangesGuard(shouldWarn: boolean) {
    useEffect(() => {
        if (!shouldWarn) {
            return;
        }

        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        const removeInertiaGuard = router.on('before', (event) => {
            if (event.detail.visit.method.toLowerCase() !== 'get') {
                return;
            }

            if (!window.confirm(warningMessage)) {
                event.preventDefault();
            }
        });
        let restoringHistory = false;
        const handlePopState = (event: PopStateEvent) => {
            if (restoringHistory) {
                restoringHistory = false;
                event.stopImmediatePropagation();

                return;
            }

            if (window.confirm(warningMessage)) {
                return;
            }

            event.stopImmediatePropagation();
            restoringHistory = true;
            window.history.forward();
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        window.addEventListener('popstate', handlePopState, { capture: true });

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            window.removeEventListener('popstate', handlePopState, {
                capture: true,
            });
            removeInertiaGuard();
        };
    }, [shouldWarn]);
}

export { warningMessage };
