import { describe, expect, it, vi } from 'vitest';
import {
    CLINICAL_DRAFT_RECOVERY_HEADERS,
    clinicalDraftRecoveryOptions,
    reportDraftSaveHttpException,
} from '@/lib/clinical-draft-recovery';

describe('clinical draft recovery contract', () => {
    it('marks only the bounded same-tab save request', () => {
        expect(CLINICAL_DRAFT_RECOVERY_HEADERS).toEqual({
            'X-SIMRS-Draft-Recovery': 'same-tab',
        });
    });

    it.each([401, 419])(
        'classifies HTTP %i as reauthentication and suppresses the Inertia overlay',
        (status) => {
            const reportFailure = vi.fn();

            expect(
                reportDraftSaveHttpException({ status }, reportFailure),
            ).toBe(false);
            expect(reportFailure).toHaveBeenCalledWith(
                'REAUTHENTICATION_REQUIRED',
            );
        },
    );

    it('keeps unrelated HTTP failures generic and on the same form', () => {
        const reportFailure = vi.fn();

        expect(
            reportDraftSaveHttpException({ status: 503 }, reportFailure),
        ).toBe(false);
        expect(reportFailure).toHaveBeenCalledWith('GENERIC');
    });

    it('keeps validation, cancellation, and network failures on the shared generic path', () => {
        const reportFailure = vi.fn();
        const options = clinicalDraftRecoveryOptions(reportFailure);

        options.onError();
        options.onCancel();
        options.onNetworkError();

        expect(options.headers).toEqual(CLINICAL_DRAFT_RECOVERY_HEADERS);
        expect(reportFailure).toHaveBeenCalledTimes(3);
        expect(reportFailure).toHaveBeenNthCalledWith(1, 'GENERIC');
        expect(reportFailure).toHaveBeenNthCalledWith(2, 'GENERIC');
        expect(reportFailure).toHaveBeenNthCalledWith(3, 'GENERIC');
    });
});
