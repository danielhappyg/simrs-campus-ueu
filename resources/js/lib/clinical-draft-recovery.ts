import type { HttpExceptionResponse } from '@inertiajs/core';

export const CLINICAL_DRAFT_RECOVERY_HEADERS = {
    'X-SIMRS-Draft-Recovery': 'same-tab',
} as const;

export type DraftSaveFailure = 'GENERIC' | 'REAUTHENTICATION_REQUIRED';

export function reportDraftSaveHttpException(
    response: Pick<HttpExceptionResponse, 'status'>,
    reportFailure: (failure: DraftSaveFailure) => void,
): false {
    reportFailure(
        response.status === 401 || response.status === 419
            ? 'REAUTHENTICATION_REQUIRED'
            : 'GENERIC',
    );

    // Stop Inertia from replacing the dirty form with its HTTP exception
    // overlay. The server response is deliberately generic and no-store.
    return false;
}

export function clinicalDraftRecoveryOptions(
    reportFailure: (failure: DraftSaveFailure) => void,
) {
    return {
        headers: CLINICAL_DRAFT_RECOVERY_HEADERS,
        onError: () => reportFailure('GENERIC'),
        onCancel: () => reportFailure('GENERIC'),
        onHttpException: (response: Pick<HttpExceptionResponse, 'status'>) =>
            reportDraftSaveHttpException(response, reportFailure),
        onNetworkError: () => reportFailure('GENERIC'),
    };
}
