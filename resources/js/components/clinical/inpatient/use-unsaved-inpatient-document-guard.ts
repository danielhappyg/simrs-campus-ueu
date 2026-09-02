import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export const inpatientUnsavedWarning =
    'Ada dokumentasi harian yang belum disimpan. Tinggalkan halaman dan buang perubahan?';

export function useUnsavedInpatientDocumentGuard(shouldWarn: boolean) {
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

            if (!window.confirm(inpatientUnsavedWarning)) {
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

            if (window.confirm(inpatientUnsavedWarning)) {
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
