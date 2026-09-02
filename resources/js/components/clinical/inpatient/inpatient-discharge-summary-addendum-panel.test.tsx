import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InpatientDischargeSummaryAddendumPanel } from './inpatient-discharge-summary-addendum-panel';
import type {
    InpatientSummaryAddendumProjection,
    InpatientSummaryCorrectionRequest,
} from './types';

type Submission = { url: string; data: Record<string, unknown> };
const submissions: Submission[] = [];
const inertia = { errors: {} as Record<string, string> };

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const dataRef = React.useRef(data);
            const setData = (next: T | ((current: T) => T)) => {
                const resolved =
                    typeof next === 'function' ? next(dataRef.current) : next;
                dataRef.current = resolved;
                setState(resolved);
            };

            return {
                data,
                errors,
                processing: false,
                setData,
                post: (
                    url: string,
                    options?: {
                        onSuccess?: () => void;
                        onError?: (errors: Record<string, string>) => void;
                    },
                ) => {
                    submissions.push({ url, data: dataRef.current });
                    setErrors(inertia.errors);

                    if (Object.keys(inertia.errors).length > 0) {
                        options?.onError?.(inertia.errors);
                    } else {
                        options?.onSuccess?.();
                    }
                },
            };
        },
    };
});

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({ children }: { children: ReactNode }) => <>{children}</>,
    DialogClose: ({ children }: { children: ReactNode }) => <>{children}</>,
    DialogContent: ({ children }: { children: ReactNode }) => (
        <div role="dialog" aria-label="Konfirmasi koreksi ringkasan pulang">
            {children}
        </div>
    ),
    DialogDescription: ({ children }: { children: ReactNode }) => (
        <p>{children}</p>
    ),
    DialogFooter: ({ children }: { children: ReactNode }) => <>{children}</>,
    DialogHeader: ({ children }: { children: ReactNode }) => <>{children}</>,
    DialogTitle: ({ children }: { children: ReactNode }) => <h2>{children}</h2>,
}));

const fields = {
    admission_reason: '',
    significant_findings: 'Hasil kultur diterima setelah pasien pulang.',
    care_and_treatment_summary: '',
    condition_at_discharge: '',
    follow_up_plan: 'Kontrol lebih awal bila demam berulang.',
};

function correction(
    overrides: Partial<InpatientSummaryCorrectionRequest> = {},
): InpatientSummaryCorrectionRequest {
    return {
        public_id: 'correction-1',
        state: 'APPROVED',
        version: 2,
        reason_code: 'MISSING_INFORMATION',
        reason_label: 'Informasi belum lengkap',
        note: 'Hasil penunjang diterima setelah penutupan.',
        requested_at: '2026-08-31T08:00:00+07:00',
        requester_name: 'dr. Citra',
        decided_at: '2026-08-31T08:30:00+07:00',
        decider_name: 'dr. Bima',
        decision_note: 'Koreksi sesuai sumber.',
        baseline: {
            discharge_summary_version: 3,
            coding_source_version: 2,
            coding_version: 4,
            review_version: 5,
            fingerprint: 'a'.repeat(64),
        },
        addendum: null,
        renewed_review: null,
        current_review_version: 0,
        current_review_source_fingerprint: null,
        permissions: {
            can_decide: false,
            can_write_addendum: false,
            can_finalize_addendum: false,
            can_save_renewed_review: false,
            can_signoff_renewed_review: false,
        },
        actions: {
            decision_url: null,
            save_addendum_url: null,
            finalize_addendum_url: null,
            save_renewed_review_url: null,
            signoff_renewed_review_url: null,
        },
        ...overrides,
    };
}

function projection(
    requests: InpatientSummaryCorrectionRequest[] = [],
): InpatientSummaryAddendumProjection {
    return {
        definition_version: 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1',
        available: true,
        reason_options: [
            {
                value: 'MISSING_INFORMATION',
                label: 'Informasi belum lengkap',
                requires_note: false,
            },
            { value: 'OTHER', label: 'Lainnya', requires_note: true },
        ],
        can_request: false,
        store_url: null,
        requests,
    };
}

describe('inpatient discharge summary addendum panel', () => {
    beforeEach(() => {
        submissions.length = 0;
        inertia.errors = {};
    });

    it('submits a bounded request with a retry-stable idempotency key and focuses server errors', async () => {
        const user = userEvent.setup();
        inertia.errors = { note: 'Catatan alasan wajib diisi.' };
        render(
            <InpatientDischargeSummaryAddendumPanel
                projection={{
                    ...projection(),
                    can_request: true,
                    store_url: '/pemeriksaan/rawat-inap/episode-1/koreksi',
                }}
            />,
        );

        await user.selectOptions(
            screen.getByLabelText('Alasan koreksi'),
            'OTHER',
        );
        await user.type(
            screen.getByLabelText(/Catatan alasan/),
            'Perlu koreksi terkontrol.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Ajukan koreksi ringkasan pulang',
            }),
        );

        const alert = screen.getByRole('alert');
        await waitFor(() => expect(alert).toHaveFocus());
        expect(submissions[0]).toMatchObject({
            url: '/pemeriksaan/rawat-inap/episode-1/koreksi',
            data: { reason_code: 'OTHER', note: 'Perlu koreksi terkontrol.' },
        });
        const firstKey = submissions[0].data.idempotency_key;
        await user.click(
            screen.getByRole('button', {
                name: 'Ajukan koreksi ringkasan pulang',
            }),
        );
        expect(submissions[1].data.idempotency_key).toBe(firstKey);
    });

    it('allows only a projected different physician to decide the request', async () => {
        const user = userEvent.setup();
        render(
            <InpatientDischargeSummaryAddendumPanel
                projection={projection([
                    correction({
                        state: 'SUBMITTED',
                        version: 1,
                        decided_at: null,
                        decider_name: null,
                        decision_note: null,
                        permissions: {
                            ...correction().permissions,
                            can_decide: true,
                        },
                        actions: {
                            ...correction().actions,
                            decision_url: '/koreksi/correction-1/keputusan',
                        },
                    }),
                ])}
            />,
        );

        await user.selectOptions(screen.getByLabelText('Keputusan'), 'DENIED');
        await user.type(
            screen.getByLabelText(/Catatan keputusan/),
            'Tidak didukung sumber.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan keputusan' }),
        );
        expect(submissions[0]).toMatchObject({
            url: '/koreksi/correction-1/keputusan',
            data: {
                decision: 'DENIED',
                decision_note: 'Tidak didukung sumber.',
                expected_version: 1,
            },
        });
    });

    it('saves structured requester-only fields and confirms Final against the stored version', async () => {
        const user = userEvent.setup();
        const writable = correction({
            permissions: {
                ...correction().permissions,
                can_write_addendum: true,
            },
            actions: {
                ...correction().actions,
                save_addendum_url: '/koreksi/correction-1/addendum/draf',
            },
        });
        const { rerender } = render(
            <InpatientDischargeSummaryAddendumPanel
                projection={projection([writable])}
            />,
        );

        await user.type(
            screen.getByLabelText('Temuan penting'),
            'Kultur positif.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan draf addendum' }),
        );
        expect(submissions[0]).toMatchObject({
            url: '/koreksi/correction-1/addendum/draf',
            data: {
                expected_version: 0,
                fields: { significant_findings: 'Kultur positif.' },
            },
        });

        rerender(
            <InpatientDischargeSummaryAddendumPanel
                projection={projection([
                    correction({
                        addendum: {
                            public_id: 'addendum-1',
                            state: 'DRAFT',
                            version: 1,
                            definition_version:
                                'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1',
                            fields,
                            author_name: 'dr. Citra',
                            finalized_by_name: null,
                            created_at: '2026-08-31T09:00:00+07:00',
                            finalized_at: null,
                        },
                        permissions: {
                            ...correction().permissions,
                            can_write_addendum: true,
                            can_finalize_addendum: true,
                        },
                        actions: {
                            ...correction().actions,
                            save_addendum_url:
                                '/koreksi/correction-1/addendum/draf',
                            finalize_addendum_url:
                                '/koreksi/correction-1/addendum/final',
                        },
                    }),
                ])}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Tetapkan addendum Final' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Ya, tetapkan Final' }),
        );
        expect(submissions[1]).toMatchObject({
            url: '/koreksi/correction-1/addendum/final',
            data: { expected_version: 1 },
        });
    });

    it('posts RMIK review and sign-off using the projected fingerprint without exposing edit controls', async () => {
        const user = userEvent.setup();
        render(
            <InpatientDischargeSummaryAddendumPanel
                projection={projection([
                    correction({
                        addendum: {
                            public_id: 'addendum-1',
                            state: 'FINAL',
                            version: 2,
                            definition_version:
                                'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1',
                            fields,
                            author_name: 'dr. Citra',
                            finalized_by_name: 'dr. Citra',
                            created_at: '2026-08-31T09:00:00+07:00',
                            finalized_at: '2026-08-31T09:30:00+07:00',
                        },
                        renewed_review: {
                            public_id: 'review-1',
                            state: 'DRAFT',
                            version: 1,
                            definition_version:
                                'INPATIENT_SUMMARY_ADDENDUM_REVIEW_V1',
                            source_fingerprint: 'b'.repeat(64),
                            reviewed_at: '2026-08-31T10:00:00+07:00',
                            reviewer_name: 'RMIK Test',
                            signed_off_at: null,
                            signed_off_by_name: null,
                            items: [
                                {
                                    item_code: 'ADDENDUM_FINAL',
                                    label: 'Addendum Final',
                                    is_blocking: true,
                                    is_complete: true,
                                    source_reference: 'addendum-1',
                                },
                            ],
                        },
                        current_review_version: 1,
                        current_review_source_fingerprint: 'b'.repeat(64),
                        permissions: {
                            ...correction().permissions,
                            can_save_renewed_review: true,
                            can_signoff_renewed_review: true,
                        },
                        actions: {
                            ...correction().actions,
                            save_renewed_review_url:
                                '/rm/koreksi/correction-1/review',
                            signoff_renewed_review_url:
                                '/rm/koreksi/correction-1/signoff',
                        },
                    }),
                ])}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Simpan draf addendum' }),
        ).not.toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Simpan hasil review koreksi' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Sign-off koreksi RM' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Ya, sign-off koreksi RM' }),
        );

        expect(submissions).toEqual([
            expect.objectContaining({
                url: '/rm/koreksi/correction-1/review',
                data: expect.objectContaining({
                    expected_version: 1,
                    source_fingerprint: 'b'.repeat(64),
                }),
            }),
            expect.objectContaining({
                url: '/rm/koreksi/correction-1/signoff',
                data: expect.objectContaining({
                    expected_version: 1,
                    source_fingerprint: 'b'.repeat(64),
                }),
            }),
        ]);
    });

    it('has no focused automated accessibility violations', async () => {
        const { container } = render(
            <InpatientDischargeSummaryAddendumPanel
                projection={projection([correction()])}
            />,
        );
        const result = await axe.run(container, {
            rules: { 'color-contrast': { enabled: false } },
        });
        expect(result.violations).toEqual([]);
    });
});
