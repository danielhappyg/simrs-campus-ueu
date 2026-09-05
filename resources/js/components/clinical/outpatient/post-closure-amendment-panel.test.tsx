import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PostClosureAmendmentPanel } from './post-closure-amendment-panel';
import type { OutpatientAmendment } from './types';

type Submission = { url: string; data: Record<string, unknown> };
const submissions: Submission[] = [];
const inertiaState: { nextErrors: Record<string, string> } = {
    nextErrors: {},
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, updateData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const dataRef = React.useRef(data);
            const initialRef = React.useRef(initial);
            const setData = (
                keyOrData: keyof T | T | ((current: T) => T),
                value?: T[keyof T],
            ) => {
                const next =
                    typeof keyOrData === 'function'
                        ? keyOrData(dataRef.current)
                        : typeof keyOrData === 'object'
                          ? keyOrData
                          : { ...dataRef.current, [keyOrData]: value };

                dataRef.current = next;
                updateData(next);
            };

            return {
                data,
                errors,
                processing: false,
                isDirty:
                    JSON.stringify(data) !== JSON.stringify(initialRef.current),
                setData,
                post: (
                    url: string,
                    options?: {
                        onSuccess?: () => void;
                        onError?: (errors: Record<string, string>) => void;
                    },
                ) => {
                    submissions.push({ url, data: dataRef.current });
                    setErrors(inertiaState.nextErrors);

                    if (Object.keys(inertiaState.nextErrors).length > 0) {
                        options?.onError?.(inertiaState.nextErrors);
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
        <div role="dialog" aria-label="Finalisasi adendum">
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

const finalDocument = {
    public_id: 'doc-final-1',
    document_type: 'MEDICAL_ASSESSMENT' as const,
    document_state: 'FINAL' as const,
    definition_version: 'RJ-DOC-v1',
    version: 4,
    fields: { anamnesis: 'Isi final yang tidak boleh diubah' },
    author_name: 'Dokter Pemohon',
    updated_at: null,
    finalized_at: '2026-08-30T08:00:00+07:00',
    finalized_by_name: 'Dokter Pemohon',
};

const amendment: OutpatientAmendment = {
    public_id: 'amendment-1',
    state: 'CONSUMED',
    version: 3,
    reason_code: 'OMISSION',
    reason_label: 'Informasi belum tercatat',
    note: 'Alasan administratif terkontrol',
    requested_at: '2026-08-30T09:00:00+07:00',
    requester_name: 'Dokter Pemohon',
    decided_at: '2026-08-30T09:30:00+07:00',
    decider_name: 'Dokter Penilai',
    decision_note: 'Permintaan sesuai.',
    original_document: {
        public_id: 'doc-final-1',
        document_type: 'MEDICAL_ASSESSMENT',
        version: 4,
    },
    addendum: {
        public_id: 'addendum-1',
        state: 'FINAL',
        version: 2,
        definition_version: 'RJ-ADDENDUM-v1',
        fields: { addendum_text: 'Adendum final terpisah.' },
        author_name: 'Dokter Pemohon',
        finalized_by_name: 'Dokter Pemohon',
        created_at: '2026-08-30T10:00:00+07:00',
        finalized_at: '2026-08-30T10:15:00+07:00',
    },
    renewed_review: {
        public_id: 'review-1',
        state: 'DRAFT',
        version: 1,
        definition_version: 'RJ-ADDENDUM-RM-v1',
        source_fingerprint: 'a'.repeat(64),
        reviewed_at: '2026-08-30T11:00:00+07:00',
        reviewer_name: 'Petugas RMIK',
        signed_off_at: null,
        signed_off_by_name: null,
        items: [
            {
                item_code: 'ADDENDUM_FINAL',
                label: 'Adendum sudah final',
                is_blocking: true,
                is_complete: true,
                source_reference: 'addendum-1',
            },
        ],
    },
    current_review_source_fingerprint: 'a'.repeat(64),
    current_review_version: 1,
    permissions: {
        can_decide: false,
        can_write_addendum: false,
        can_finalize_addendum: false,
        can_save_renewed_review: true,
        can_signoff_renewed_review: true,
    },
    actions: {
        decision_url: null,
        save_addendum_url: null,
        finalize_addendum_url: null,
        save_renewed_review_url:
            '/rm/rawat-jalan/amendments/amendment-1/reviews',
        signoff_renewed_review_url:
            '/rm/rawat-jalan/amendments/amendment-1/signoff',
    },
};

describe('post-closure outpatient amendment panel', () => {
    beforeEach(() => {
        submissions.length = 0;
        inertiaState.nextErrors = {};
    });

    it('submits a bounded request against the final source without putting clinical text in the URL', async () => {
        const user = userEvent.setup();

        render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[
                    {
                        value: 'OMISSION',
                        label: 'Informasi belum tercatat',
                        requires_note: false,
                    },
                    {
                        value: 'OTHER',
                        label: 'Alasan lain',
                        requires_note: true,
                    },
                ]}
                amendments={[]}
                canRequest
                storeUrl="/pemeriksaan/rawat-jalan/encounter-1/amendments"
            />,
        );

        expect(
            screen.getByText(/original document remains final and unchanged/i),
        ).toBeVisible();
        await user.selectOptions(
            screen.getByLabelText('Addendum reason'),
            'OTHER',
        );
        await user.type(
            screen.getByLabelText('Reason note'),
            'Perlu tambahan konteks klinis.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Submit addendum request' }),
        );

        expect(submissions).toHaveLength(1);
        expect(submissions[0].url).toBe(
            '/pemeriksaan/rawat-jalan/encounter-1/amendments',
        );
        expect(submissions[0].url).not.toContain('konteks');
        expect(submissions[0].data).toMatchObject({
            original_document_public_id: 'doc-final-1',
            original_document_version: 4,
            reason_code: 'OTHER',
            note: 'Perlu tambahan konteks klinis.',
        });
    });

    it('focuses a stable validation summary after a failed request', async () => {
        const user = userEvent.setup();
        inertiaState.nextErrors = {
            note: 'Catatan alasan wajib diisi.',
        };

        render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[
                    {
                        value: 'OMISSION',
                        label: 'Informasi belum tercatat',
                        requires_note: false,
                    },
                ]}
                amendments={[]}
                canRequest
                storeUrl="/pemeriksaan/rawat-jalan/encounter-1/amendments"
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Submit addendum request' }),
        );

        const summary = await screen.findByRole('alert');
        await waitFor(() => expect(summary).toHaveFocus());
        expect(summary).toHaveTextContent('Catatan alasan wajib diisi.');

        const submit = screen.getByRole('button', {
            name: 'Submit addendum request',
        });
        submit.focus();
        expect(submit).toHaveFocus();
        await user.click(submit);

        await waitFor(() => expect(summary).toHaveFocus());
    });

    it('posts an approve or deny decision only when the server grants the action', async () => {
        const user = userEvent.setup();
        const submittedAmendment: OutpatientAmendment = {
            ...amendment,
            state: 'SUBMITTED',
            version: 1,
            decided_at: null,
            decider_name: null,
            decision_note: null,
            addendum: null,
            renewed_review: null,
            permissions: {
                can_decide: true,
                can_write_addendum: false,
                can_finalize_addendum: false,
                can_save_renewed_review: false,
                can_signoff_renewed_review: false,
            },
            actions: {
                decision_url:
                    '/pemeriksaan/rawat-jalan/amendments/amendment-1/decision',
                save_addendum_url: null,
                finalize_addendum_url: null,
                save_renewed_review_url: null,
                signoff_renewed_review_url: null,
            },
        };

        render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[submittedAmendment]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        const decisionNote = screen.getByLabelText('Decision note (optional)');
        expect(decisionNote).not.toBeRequired();

        await user.selectOptions(screen.getByLabelText('Decision'), 'DENIED');
        expect(
            screen.getByLabelText('Decision note · required when denying'),
        ).toBeRequired();
        await user.click(screen.getByRole('button', { name: 'Save decision' }));
        expect(submissions).toHaveLength(0);

        await user.type(
            screen.getByLabelText('Decision note · required when denying'),
            'Belum memenuhi kriteria.',
        );
        await user.click(screen.getByRole('button', { name: 'Save decision' }));

        expect(submissions).toHaveLength(1);
        expect(submissions[0]).toMatchObject({
            url: '/pemeriksaan/rawat-jalan/amendments/amendment-1/decision',
            data: {
                decision: 'DENIED',
                decision_note: 'Belum memenuhi kriteria.',
                expected_version: 1,
            },
        });
    });

    it('rebases draft and finalize payload versions after each projected addendum change', async () => {
        const user = userEvent.setup();
        const approvedWithoutDraft: OutpatientAmendment = {
            ...amendment,
            state: 'APPROVED',
            version: 2,
            addendum: null,
            renewed_review: null,
            permissions: {
                can_decide: false,
                can_write_addendum: true,
                can_finalize_addendum: false,
                can_save_renewed_review: false,
                can_signoff_renewed_review: false,
            },
            actions: {
                decision_url: null,
                save_addendum_url:
                    '/pemeriksaan/rawat-jalan/amendments/amendment-1/addendum',
                finalize_addendum_url: null,
                save_renewed_review_url: null,
                signoff_renewed_review_url: null,
            },
        };
        const { rerender } = render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[approvedWithoutDraft]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        const initialText = screen.getByLabelText(
            'Addendum content · required',
        );
        expect(initialText).toBeRequired();
        await user.click(
            screen.getByRole('button', { name: 'Save addendum draft' }),
        );
        expect(submissions).toHaveLength(0);

        await user.type(initialText, 'Draf awal.');
        await user.click(
            screen.getByRole('button', { name: 'Save addendum draft' }),
        );
        expect(submissions.at(-1)).toMatchObject({
            data: {
                expected_version: 0,
                fields: { addendum_text: 'Draf awal.' },
            },
        });

        const draftV1: OutpatientAmendment = {
            ...approvedWithoutDraft,
            addendum: {
                public_id: 'addendum-1',
                state: 'DRAFT',
                version: 1,
                definition_version: 'RJ-ADDENDUM-v1',
                fields: { addendum_text: 'Draf awal.' },
                author_name: 'Dokter Pemohon',
                finalized_by_name: null,
                created_at: '2026-08-30T10:00:00+07:00',
                finalized_at: null,
            },
            permissions: {
                ...approvedWithoutDraft.permissions,
                can_finalize_addendum: true,
            },
            actions: {
                ...approvedWithoutDraft.actions,
                finalize_addendum_url:
                    '/pemeriksaan/rawat-jalan/amendments/amendment-1/addendum/finalize',
            },
        };
        rerender(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[draftV1]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        const projectedV1Text = screen.getByLabelText(
            'Addendum content · required',
        );
        expect(projectedV1Text).toHaveValue('Draf awal.');
        expect(
            screen.getByRole('button', { name: 'Finalize addendum' }),
        ).toBeEnabled();
        await user.clear(projectedV1Text);
        await user.type(projectedV1Text, 'Draf diperbarui.');
        await user.click(
            screen.getByRole('button', { name: 'Save addendum draft' }),
        );
        expect(submissions.at(-1)).toMatchObject({
            data: {
                expected_version: 1,
                fields: { addendum_text: 'Draf diperbarui.' },
            },
        });

        const draftV2: OutpatientAmendment = {
            ...draftV1,
            addendum: {
                ...draftV1.addendum!,
                version: 2,
                fields: { addendum_text: 'Draf diperbarui.' },
            },
        };
        rerender(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[draftV2]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Finalize addendum' }),
        ).toBeEnabled();
        await user.click(
            screen.getByRole('button', { name: 'Finalize addendum' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Yes, finalize addendum' }),
        );
        expect(submissions.at(-1)).toMatchObject({
            url: '/pemeriksaan/rawat-jalan/amendments/amendment-1/addendum/finalize',
            data: { expected_version: 2 },
        });
    });

    it('associates an enabled addendum field with its server error and announces it', async () => {
        const user = userEvent.setup();
        inertiaState.nextErrors = {
            'fields.addendum_text': 'Isi adendum belum dapat disimpan.',
        };
        const approvedWithoutDraft: OutpatientAmendment = {
            ...amendment,
            state: 'APPROVED',
            addendum: null,
            renewed_review: null,
            permissions: {
                can_decide: false,
                can_write_addendum: true,
                can_finalize_addendum: false,
                can_save_renewed_review: false,
                can_signoff_renewed_review: false,
            },
            actions: {
                decision_url: null,
                save_addendum_url:
                    '/pemeriksaan/rawat-jalan/amendments/amendment-1/addendum',
                finalize_addendum_url: null,
                save_renewed_review_url: null,
                signoff_renewed_review_url: null,
            },
        };
        const { container } = render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[approvedWithoutDraft]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        const text = screen.getByLabelText('Addendum content · required');
        await user.type(text, 'Teks yang ditolak server.');
        await user.click(
            screen.getByRole('button', { name: 'Save addendum draft' }),
        );

        const summary = await screen.findByRole('alert');
        await waitFor(() => expect(summary).toHaveFocus());
        expect(summary).toHaveTextContent('Isi adendum belum dapat disimpan.');
        expect(text).toHaveAttribute('aria-invalid', 'true');
        expect(text.getAttribute('aria-describedby')).toContain(
            '-addendum-error',
        );
        expect(
            screen.getAllByText('Isi adendum belum dapat disimpan.'),
        ).toHaveLength(2);

        const result = await axe.run(container, {
            rules: {
                'color-contrast': { enabled: false },
            },
        });
        expect(result.violations).toEqual([]);
    });

    it('keeps dormant workflow controls absent when permissions are false', () => {
        const dormantAmendment: OutpatientAmendment = {
            ...amendment,
            state: 'APPROVED',
            addendum: {
                ...amendment.addendum!,
                state: 'DRAFT',
            },
            permissions: {
                can_decide: false,
                can_write_addendum: false,
                can_finalize_addendum: false,
                can_save_renewed_review: false,
                can_signoff_renewed_review: false,
            },
        };

        const { rerender } = render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[dormantAmendment]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Save decision' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save addendum draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Finalize addendum' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save addendum review' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Sign off addendum review',
            }),
        ).not.toBeInTheDocument();

        rerender(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[
                    {
                        ...dormantAmendment,
                        permissions: {
                            can_decide: true,
                            can_write_addendum: true,
                            can_finalize_addendum: true,
                            can_save_renewed_review: true,
                            can_signoff_renewed_review: true,
                        },
                        actions: {
                            decision_url: null,
                            save_addendum_url: null,
                            finalize_addendum_url: null,
                            save_renewed_review_url: null,
                            signoff_renewed_review_url: null,
                        },
                    },
                ]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Save decision' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save addendum draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Finalize addendum' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save addendum review' }),
        ).not.toBeInTheDocument();
    });

    it('covers the future capability contract for RMIK actions without claiming current backend readiness', async () => {
        const user = userEvent.setup();

        render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[amendment]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        expect(
            screen.getByText('Original archive remains intact'),
        ).toBeVisible();
        expect(screen.getByText('Adendum final terpisah.')).toBeVisible();
        expect(
            screen.getByText(
                'The original document remains final, intact, and read-only.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /buka kembali/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /ubah dokumen asli/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save decision' }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Save addendum review' }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Sign off addendum review' }),
        );

        expect(submissions.map((entry) => entry.url)).toEqual([
            '/rm/rawat-jalan/amendments/amendment-1/reviews',
            '/rm/rawat-jalan/amendments/amendment-1/signoff',
        ]);
        expect(submissions[0].data).toMatchObject({
            expected_version: 1,
            source_fingerprint: 'a'.repeat(64),
        });
    });

    it('has no automatically detected WCAG violations in the closed-chain state', async () => {
        const { container } = render(
            <PostClosureAmendmentPanel
                encounterClosed
                originalDocuments={[finalDocument]}
                reasonOptions={[]}
                amendments={[amendment]}
                canRequest={false}
                storeUrl={null}
            />,
        );

        const result = await axe.run(container, {
            rules: {
                // jsdom cannot calculate rendered colour contrast.
                'color-contrast': { enabled: false },
            },
        });

        expect(result.violations).toEqual([]);
    });
});
