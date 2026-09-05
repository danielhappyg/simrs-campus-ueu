import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import RmRawatJalan from '@/pages/rm/rawat-jalan';
import RmRawatJalanShow from '@/pages/rm/rawat-jalan/show';
import { OutpatientDispositionPanel } from './outpatient-disposition-panel';
import { StructuredDocumentForm } from './structured-document-form';
import StructuredOutpatientEncounterShow from './structured-outpatient-encounter-show';

const submissions: Array<{ url: string; data: Record<string, unknown> }> = [];
type BeforeNavigationEvent = {
    detail: { visit: { method: string } };
    preventDefault: () => void;
};
const inertiaBeforeHandlers: Array<(event: BeforeNavigationEvent) => void> = [];

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...props
        }: AnchorHTMLAttributes<HTMLAnchorElement> & {
            href: string;
            children?: ReactNode;
        }) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        router: {
            get: vi.fn(),
            post: vi.fn(),
            on: (
                event: string,
                handler: (event: BeforeNavigationEvent) => void,
            ) => {
                if (event === 'before') {
                    inertiaBeforeHandlers.push(handler);
                }

                return () => {
                    const index = inertiaBeforeHandlers.indexOf(handler);

                    if (index >= 0) {
                        inertiaBeforeHandlers.splice(index, 1);
                    }
                };
            },
        },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, updateData] = React.useState(initial);
            const dataRef = React.useRef(data);
            const initialData = React.useRef(initial);
            const transformRef = React.useRef<
                ((value: T) => Record<string, unknown>) | undefined
            >(undefined);

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
                errors: {},
                processing: false,
                isDirty:
                    JSON.stringify(data) !==
                    JSON.stringify(initialData.current),
                setData,
                transform: (
                    callback: (value: T) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                },
                post: (url: string) =>
                    submissions.push({
                        url,
                        data: transformRef.current
                            ? transformRef.current(dataRef.current)
                            : dataRef.current,
                    }),
                reset: () => undefined,
            };
        },
    };
});

describe('structured outpatient documentation', () => {
    it('keeps an unsigned outpatient disposition bound to the current final medical document', async () => {
        submissions.length = 0;
        const user = userEvent.setup();

        render(
            <StructuredOutpatientEncounterShow
                variant="rawat-jalan"
                encounter={{
                    public_id: 'enc-rj-1',
                    status: 'IN_EXAMINATION',
                    clinic_name: 'Klinik Demo',
                    doctor_name: 'Dokter Demo',
                    schedule_label: null,
                    payer_type: 'UMUM',
                    queue_number: 1,
                    registered_at: null,
                    visit_date: null,
                    chief_complaint: null,
                    patient: {
                        public_id: 'patient-1',
                        medical_record_number: 'SIM-001',
                        full_name: 'Pasien Sintetis',
                        date_of_birth: null,
                        sex: 'male',
                        nik: null,
                    },
                }}
                legacyEntries={[]}
                documentation={{
                    definition_version: 'RJ-DOC-v1',
                    source_fingerprint: 'a'.repeat(64),
                    active_drafts: [],
                    versions: [],
                    documents: [
                        {
                            public_id: 'medical-final-1',
                            document_type: 'MEDICAL_ASSESSMENT',
                            document_state: 'FINAL',
                            definition_version: 'RJ-DOC-v1',
                            version: 7,
                            fields: {},
                            author_name: 'Dokter Demo',
                            updated_at: null,
                            finalized_at: null,
                            finalized_by_name: 'Dokter Demo',
                        },
                    ],
                }}
                permissions={{
                    nursing: { can_save_draft: false, can_finalize: false },
                    medical: { can_save_draft: false, can_finalize: false },
                    can_create_lab_order: false,
                    can_sign_disposition: true,
                }}
                actions={{
                    nursing: {
                        save_draft_url: '/nursing',
                        finalize_url: '/nursing/final',
                    },
                    medical: {
                        save_draft_url: '/medical',
                        finalize_url: '/medical/final',
                    },
                    store_lab_order_url: '/lab',
                    sign_disposition_url:
                        '/pemeriksaan/rawat-jalan/enc-rj-1/disposition',
                }}
                disposition={{ current: null, pending_handoff: false }}
                labTestOptions={[]}
            />,
        );

        await user.click(
            screen.getByRole('radio', { name: /Inpatient admission/ }),
        );
        await user.type(
            screen.getByLabelText('Reason for admission'),
            'Perlu observasi lanjutan.',
        );
        await user.type(
            screen.getByLabelText('Receiving unit handoff note'),
            'Mohon pemantauan bangsal.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Sign disposition' }),
        );

        expect(submissions.at(-1)).toEqual({
            url: '/pemeriksaan/rawat-jalan/enc-rj-1/disposition',
            data: {
                disposition_type: 'RAWAT_INAP',
                expected_document_version: 7,
                idempotency_key: expect.any(String),
                payload: {
                    admission_reason: 'Perlu observasi lanjutan.',
                    receiving_unit_handoff_note: 'Mohon pemantauan bangsal.',
                },
            },
        });
    });

    it('sends a correction with the signed version, reason, and type-exact payload', async () => {
        submissions.length = 0;
        const user = userEvent.setup();

        render(
            <OutpatientDispositionPanel
                disposition={{
                    current: {
                        public_id: 'disp-1',
                        version: 3,
                        code: 'RAWAT_INAP',
                        payload: {
                            admission_reason: 'Observasi awal.',
                            receiving_unit_handoff_note: 'Pantau ketat.',
                        },
                        signed_at: null,
                        physician_name: 'Dokter Demo',
                        bound_medical_document_version: 7,
                    },
                    pending_handoff: true,
                }}
                canSign={false}
                signUrl={null}
                canCorrect
                correctUrl="/pemeriksaan/rawat-jalan/enc-rj-1/disposition/corrections"
                medicalDocumentVersion={8}
                medicalDocumentFinal
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Correct disposition' }),
        );
        await user.type(
            screen.getByLabelText('Reason for correction'),
            'Unit penerima diperbarui.',
        );
        const correctionAdmissionReason = document.getElementById(
            'admission-reason',
        ) as HTMLTextAreaElement | null;

        if (!correctionAdmissionReason) {
            throw new Error(
                'Correction admission-reason field was not rendered.',
            );
        }

        await user.clear(correctionAdmissionReason);
        await user.type(correctionAdmissionReason, 'Observasi lanjutan.');
        await user.click(
            screen.getByRole('button', {
                name: 'Save disposition correction',
            }),
        );

        expect(submissions.at(-1)).toEqual({
            url: '/pemeriksaan/rawat-jalan/enc-rj-1/disposition/corrections',
            data: {
                disposition_type: 'RAWAT_INAP',
                expected_document_version: 8,
                idempotency_key: expect.any(String),
                expected_disposition_version: 3,
                correction_reason: 'Unit penerima diperbarui.',
                payload: {
                    admission_reason: 'Observasi lanjutan.',
                    receiving_unit_handoff_note: 'Pantau ketat.',
                },
            },
        });
    });

    it('preserves the shared page as a discriminated dispatcher for IGD and inpatient', () => {
        const source = readFileSync(
            resolve('resources/js/pages/pemeriksaan/rawat-jalan/show.tsx'),
            'utf8',
        );

        expect(source).toContain("props.variant === 'rawat-jalan'");
        expect(source).toContain("'documentation' in props");
        expect(source).toContain('LegacyFreeTextEncounterShow');
    });

    it('isolates the nursing form and posts the versioned draft payload', async () => {
        submissions.length = 0;
        const user = userEvent.setup();

        render(
            <StructuredDocumentForm
                type="NURSING_ASSESSMENT"
                definitionVersion="RJ-DOC-v1"
                permission={{ can_save_draft: true, can_finalize: false }}
                actions={{
                    save_draft_url: '/nursing/draft',
                    finalize_url: '/nursing/finalize',
                }}
                encounterClosed={false}
            />,
        );

        expect(screen.queryByLabelText(/Anamnesis/)).not.toBeInTheDocument();

        await user.type(
            screen.getByLabelText(/Nursing assessment/),
            'Pasien sadar dan kooperatif.',
        );
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(submissions).toEqual([
            {
                url: '/nursing/draft',
                data: {
                    definition_version: 'RJ-DOC-v1',
                    expected_version: 0,
                    fields: {
                        nursing_assessment: 'Pasien sadar dan kooperatif.',
                        additional_notes: '',
                    },
                },
            },
        ]);
    });

    it('keeps SOAP narrative and clinician-selected ICD codes in one versioned medical draft', async () => {
        submissions.length = 0;
        const user = userEvent.setup();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                options: [
                    {
                        code: 'A09',
                        display: 'Infectious gastroenteritis and colitis',
                        system: 'ICD-10',
                    },
                ],
                source: { authority: 'Katalog resmi', dataset: 'ICD-10' },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        const { container } = render(
            <StructuredDocumentForm
                type="MEDICAL_ASSESSMENT"
                definitionVersion="RJ-DOC-v1"
                permission={{ can_save_draft: true, can_finalize: false }}
                actions={{
                    save_draft_url: '/medical/draft',
                    finalize_url: '/medical/finalize',
                }}
                encounterClosed={false}
                terminologyLookupUrl="/pemeriksaan/rawat-jalan/terminology"
            />,
        );

        expect(screen.getByLabelText(/Subjective/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Objective/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Assessment/)).toBeInTheDocument();
        expect(screen.getByLabelText(/Plan/)).toBeInTheDocument();

        expect(
            Array.from(
                container.querySelectorAll<
                    HTMLTextAreaElement | HTMLInputElement
                >('textarea, input'),
            ).map((element) => element.id),
        ).toEqual([
            'MEDICAL_ASSESSMENT-anamnesis',
            'MEDICAL_ASSESSMENT-objective_examination',
            'MEDICAL_ASSESSMENT-clinical_assessment',
            'MEDICAL_ASSESSMENT-diagnosis-text',
            'MEDICAL_ASSESSMENT-primary-icd10',
            'MEDICAL_ASSESSMENT-secondary-icd10',
            'MEDICAL_ASSESSMENT-care_plan',
            'MEDICAL_ASSESSMENT-procedures-icd9cm',
            'MEDICAL_ASSESSMENT-additional_notes',
        ]);

        await user.type(
            screen.getByLabelText(/Clinical diagnosis/),
            'Gastroenteritis akut',
        );
        await user.type(
            screen.getByLabelText('Primary diagnosis (ICD-10)'),
            'A0',
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
        await user.click(screen.getByRole('button', { name: /A09/ }));
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(submissions.at(-1)).toEqual({
            url: '/medical/draft',
            data: expect.objectContaining({
                fields: expect.objectContaining({
                    diagnosis_text: 'Gastroenteritis akut',
                    primary_icd10: {
                        code: 'A09',
                        display: 'Infectious gastroenteritis and colitis',
                    },
                    secondary_icd10: [],
                    procedures_icd9cm: [],
                }),
            }),
        });
        vi.unstubAllGlobals();
    });

    it('retains unsaved clinical text across in-page tabs and guards GET navigation', async () => {
        inertiaBeforeHandlers.length = 0;
        const user = userEvent.setup();
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

        render(
            <StructuredOutpatientEncounterShow
                variant="rawat-jalan"
                encounter={{
                    public_id: 'enc-1',
                    status: 'IN_EXAMINATION',
                    clinic_name: 'Klinik Demo',
                    doctor_name: 'Dokter Demo',
                    schedule_label: null,
                    payer_type: 'UMUM',
                    queue_number: 1,
                    registered_at: '2026-08-24T08:00:00+07:00',
                    visit_date: '2026-08-24',
                    chief_complaint: 'Keluhan sintetis',
                    patient: {
                        public_id: 'patient-1',
                        medical_record_number: 'SIM-001',
                        full_name: 'Pasien Sintetis',
                        date_of_birth: '1990-01-01',
                        sex: 'male',
                        nik: null,
                    },
                    lab_orders: [],
                }}
                legacyEntries={[]}
                documentation={{
                    definition_version: 'RJ-DOC-v1',
                    source_fingerprint: 'a'.repeat(64),
                    documents: [],
                    active_drafts: [],
                    versions: [],
                }}
                permissions={{
                    nursing: {
                        can_save_draft: true,
                        can_finalize: false,
                    },
                    medical: {
                        can_save_draft: false,
                        can_finalize: false,
                    },
                    can_create_lab_order: false,
                }}
                actions={{
                    nursing: {
                        save_draft_url: '/nursing/draft',
                        finalize_url: '/nursing/finalize',
                    },
                    medical: {
                        save_draft_url: '/medical/draft',
                        finalize_url: '/medical/finalize',
                    },
                    store_lab_order_url: '/lab-orders',
                }}
                labTestOptions={[]}
            />,
        );

        const nursingAssessment = screen.getByLabelText(/Nursing assessment/);
        await user.type(nursingAssessment, 'Teks yang belum disimpan');
        await user.click(screen.getByRole('button', { name: 'Order Lab' }));

        expect(nursingAssessment).not.toBeVisible();

        await user.click(screen.getByRole('button', { name: 'Documentation' }));
        expect(nursingAssessment).toBeVisible();
        expect(nursingAssessment).toHaveValue('Teks yang belum disimpan');

        await waitFor(() => expect(inertiaBeforeHandlers).toHaveLength(1));
        const preventDefault = vi.fn();
        inertiaBeforeHandlers[0]({
            detail: { visit: { method: 'get' } },
            preventDefault,
        });

        expect(confirm).toHaveBeenCalledWith(
            'There is unsaved clinical documentation. Leave this page and discard the changes?',
        );
        expect(preventDefault).toHaveBeenCalledOnce();

        const beforeUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(beforeUnload);
        expect(beforeUnload.defaultPrevented).toBe(true);

        const historyForward = vi
            .spyOn(window.history, 'forward')
            .mockImplementation(() => undefined);
        window.dispatchEvent(new PopStateEvent('popstate'));
        expect(historyForward).toHaveBeenCalledOnce();

        historyForward.mockRestore();
        confirm.mockRestore();
    });

    it('keeps a final document read-only and posts only expected version on finalization', async () => {
        submissions.length = 0;
        const user = userEvent.setup();
        const baseDraft = {
            public_id: 'doc-1',
            document_type: 'MEDICAL_ASSESSMENT' as const,
            document_state: 'DRAFT' as const,
            definition_version: 'RJ-DOC-v1',
            version: 3,
            fields: {
                anamnesis: 'Keluhan sintetis',
                objective_examination: 'Keadaan umum baik',
                clinical_assessment: 'Asesmen sintetis',
                care_plan: 'Edukasi',
                additional_notes: '',
            },
            author_name: 'Dokter Demo',
            updated_at: null,
            finalized_at: null,
            finalized_by_name: null,
        };

        const { rerender } = render(
            <StructuredDocumentForm
                key="persisted-draft"
                type="MEDICAL_ASSESSMENT"
                definitionVersion="RJ-DOC-v1"
                draft={baseDraft}
                permission={{ can_save_draft: true, can_finalize: true }}
                actions={{
                    save_draft_url: '/medical/draft',
                    finalize_url: '/medical/finalize',
                }}
                encounterClosed={false}
            />,
        );

        await user.type(
            screen.getByLabelText(/Additional notes/),
            'Perubahan belum disimpan',
        );
        expect(
            screen.getByRole('button', { name: 'Finalize version' }),
        ).toBeDisabled();
        expect(screen.getByText(/There are unsaved changes/)).toBeVisible();

        rerender(
            <StructuredDocumentForm
                type="MEDICAL_ASSESSMENT"
                definitionVersion="RJ-DOC-v1"
                draft={baseDraft}
                permission={{ can_save_draft: true, can_finalize: true }}
                actions={{
                    save_draft_url: '/medical/draft',
                    finalize_url: '/medical/finalize',
                }}
                encounterClosed={false}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );
        expect(submissions.at(-1)).toEqual({
            url: '/medical/finalize',
            data: { expected_version: 3 },
        });

        rerender(
            <StructuredDocumentForm
                type="MEDICAL_ASSESSMENT"
                definitionVersion="RJ-DOC-v1"
                draft={{ ...baseDraft, document_state: 'FINAL' }}
                permission={{ can_save_draft: true, can_finalize: true }}
                actions={{
                    save_draft_url: '/medical/draft',
                    finalize_url: '/medical/finalize',
                }}
                encounterClosed={false}
            />,
        );

        expect(screen.getByLabelText(/Subjective/)).toHaveAttribute('readonly');
        expect(
            screen.queryByRole('button', { name: 'Finalize version' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Final versions are read-only.')).toBeVisible();
    });

    it('removes direct RM closure and exposes review detail navigation', () => {
        const listSource = readFileSync(
            resolve('resources/js/pages/rm/rawat-jalan.tsx'),
            'utf8',
        );
        const detailSource = readFileSync(
            resolve('resources/js/pages/rm/rawat-jalan/show.tsx'),
            'utf8',
        );

        expect(listSource).toContain('Review medical record');
        expect(listSource).toContain('View medical record');
        expect(listSource).not.toContain('window.confirm');
        expect(listSource).not.toContain('/complete');
        expect(detailSource).toContain('DialogContent');
        expect(detailSource).toContain('blockers.length === 0');
        expect(detailSource).toContain('Read-only clinical source');
        expect(detailSource).toContain('expected_version');
        expect(detailSource).toContain('source_fingerprint');
    });

    it('keeps RM sign-off blocked and exposes an accessible safe confirmation', async () => {
        submissions.length = 0;
        const encounter = {
            public_id: 'enc-1',
            status: 'READY_FOR_RM',
            clinic_name: 'Klinik Demo',
            doctor_name: 'Dokter Demo',
            schedule_label: null,
            payer_type: 'UMUM',
            queue_number: 1,
            registered_at: null,
            visit_date: '2026-08-24',
            chief_complaint: 'Keluhan sintetis',
            patient: {
                public_id: 'patient-1',
                medical_record_number: 'SIM-001',
                full_name: 'Pasien Sintetis',
                date_of_birth: '1990-01-01',
                sex: 'male',
                nik: null,
            },
        };
        const actions = {
            save_review_url: '/rm/enc-1/reviews',
            signoff_url: '/rm/enc-1/signoff',
        };
        const baseReview = {
            public_id: 'review-1',
            status: 'INCOMPLETE' as const,
            version: 0,
            source_fingerprint: 'a'.repeat(64),
            checklist_items: [
                {
                    code: 'CHK-RJ-02',
                    label: 'Asesmen keperawatan final',
                    status: 'FAIL' as const,
                    reason: 'Sumber belum lengkap.',
                },
            ],
            reviewer_name: 'RMIK Demo',
            reviewed_at: null,
            signed_off_by_name: null,
            signed_off_at: null,
        };

        const { rerender } = render(
            <RmRawatJalanShow
                encounter={encounter}
                clinicalSources={[]}
                documentVersions={[
                    {
                        public_id: 'version-1',
                        document_type: 'MEDICAL_ASSESSMENT',
                        version: 1,
                        state: 'FINAL',
                        fields: {
                            anamnesis: 'Keluhan sintetis',
                            objective_examination: 'Keadaan umum baik',
                            clinical_assessment: 'Asesmen sintetis',
                            care_plan: 'Edukasi',
                        },
                        actor_name: 'Dokter Demo',
                        created_at: '2026-08-24T08:00:00+07:00',
                        finalized_at: '2026-08-24T08:05:00+07:00',
                    },
                ]}
                review={baseReview}
                blockers={[
                    {
                        code: 'CHK-RJ-02',
                        label: 'Asesmen keperawatan final',
                        reason: 'Sumber belum lengkap.',
                    },
                ]}
                permissions={{ can_save_review: true, can_signoff: true }}
                actions={actions}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: 'Sign off and close encounter',
            }),
        ).toBeDisabled();
        expect(screen.getByText(/every item must be aligned/i)).toBeVisible();
        expect(screen.getByText('Version 1 · Dokter Demo')).toBeVisible();
        expect(screen.getByText('Keluhan sintetis')).toBeVisible();

        rerender(
            <RmRawatJalanShow
                encounter={encounter}
                clinicalSources={[]}
                documentVersions={[]}
                review={{
                    ...baseReview,
                    version: 1,
                    status: 'COMPLETE',
                    checklist_items: [
                        {
                            ...baseReview.checklist_items[0],
                            status: 'PASS',
                            reason: null,
                        },
                    ],
                }}
                blockers={[]}
                permissions={{ can_save_review: true, can_signoff: true }}
                actions={actions}
            />,
        );

        await userEvent.click(
            screen.getByRole('button', { name: 'Save review result' }),
        );

        expect(submissions).toEqual([
            {
                url: '/rm/enc-1/reviews',
                data: {
                    expected_version: 1,
                    source_fingerprint: 'a'.repeat(64),
                },
            },
        ]);
        submissions.length = 0;

        await userEvent.click(
            screen.getByRole('button', {
                name: 'Sign off and close encounter',
            }),
        );

        expect(
            screen.getByRole('dialog', {
                name: 'Confirm medical-record completeness sign-off',
            }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeVisible();

        await userEvent.click(
            screen.getByRole('button', { name: 'Yes, sign off and close' }),
        );

        expect(submissions).toEqual([
            {
                url: '/rm/enc-1/signoff',
                data: {
                    expected_version: 1,
                    source_fingerprint: 'a'.repeat(64),
                },
            },
        ]);
    });

    it('renders a signed-off CLOSED RM record without mutation actions', () => {
        render(
            <RmRawatJalanShow
                encounter={{
                    public_id: 'enc-closed',
                    status: 'CLOSED',
                    clinic_name: 'Klinik Demo',
                    doctor_name: 'Dokter Demo',
                    schedule_label: null,
                    payer_type: 'UMUM',
                    queue_number: 1,
                    registered_at: null,
                    visit_date: '2026-08-24',
                    chief_complaint: 'Keluhan sintetis',
                    patient: {
                        public_id: 'patient-1',
                        medical_record_number: 'SIM-001',
                        full_name: 'Pasien Sintetis',
                        date_of_birth: '1990-01-01',
                        sex: 'male',
                        nik: null,
                    },
                }}
                clinicalSources={[]}
                documentVersions={[]}
                review={{
                    public_id: 'review-closed',
                    status: 'SIGNED_OFF',
                    version: 2,
                    source_fingerprint: 'b'.repeat(64),
                    checklist_items: [],
                    reviewer_name: 'RMIK Demo',
                    reviewed_at: '2026-08-24T09:00:00+07:00',
                    signed_off_by_name: 'RMIK Demo',
                    signed_off_at: '2026-08-24T09:05:00+07:00',
                }}
                blockers={[]}
                permissions={{
                    can_save_review: false,
                    can_signoff: false,
                }}
                actions={{
                    save_review_url: '/rm/enc-closed/reviews',
                    signoff_url: '/rm/enc-closed/signoff',
                }}
            />,
        );

        expect(screen.getByText('Signed off')).toBeVisible();
        expect(screen.getByText(/read-only archive records/i)).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Save review result' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Sign off and close encounter',
            }),
        ).not.toBeInTheDocument();
    });

    it('labels CLOSED rows as read-only RM links', () => {
        render(
            <RmRawatJalan
                encounters={[
                    {
                        public_id: 'enc-closed',
                        status: 'CLOSED',
                        clinic_name: 'Klinik Demo',
                        doctor_name: 'Dokter Demo',
                        payer_type: 'UMUM',
                        admission_mode: 'DATANG_SENDIRI',
                        queue_number: 1,
                        registered_at: null,
                        visit_date: '2026-08-24',
                        entry_count: 2,
                        active_lab_order_count: 0,
                        completeness_status: 'SIGNED_OFF',
                        blocker_count: 0,
                        patient: {
                            public_id: 'patient-1',
                            medical_record_number: 'SIM-001',
                            full_name: 'Pasien Sintetis',
                            date_of_birth: '1990-01-01',
                            sex: 'male',
                        },
                    },
                ]}
                clinics={[]}
                payerOptions={[]}
                filters={{
                    q: '',
                    clinic: '',
                    payer: '',
                    date_from: '',
                    date_to: '',
                }}
            />,
        );

        expect(screen.getByText('Signed off')).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'View medical record' }),
        ).toHaveAttribute('href', '/rm/rawat-jalan/enc-closed');
        expect(
            screen.queryByRole('link', { name: 'Review medical record' }),
        ).not.toBeInTheDocument();
    });
});
