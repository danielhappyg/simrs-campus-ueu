import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InpatientDailyDocumentForm } from './inpatient-daily-document-form';
import { InpatientDischargeCodingSourcePanel } from './inpatient-discharge-coding-source-panel';
import { InpatientDischargeSummaryPanel } from './inpatient-discharge-summary-panel';
import StructuredInpatientEncounterShow from './structured-inpatient-encounter-show';
import type {
    InpatientDailyDocument,
    InpatientDischargeCodingSourceProjection,
    InpatientDischargeSummaryProjection,
    InpatientDocumentationShowProps,
    InpatientPlacementSnapshot,
    InpatientRoutineDischargeProjection,
} from './types';
import { inpatientUnsavedWarning } from './use-unsaved-inpatient-document-guard';

type Submission = { url: string; data: Record<string, unknown> };
type VisitEvent = {
    detail: { visit: { method: string } };
    preventDefault: () => void;
};
type PostOptions = {
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: () => void;
};
type PendingPost = {
    succeed: () => void;
    fail: (errors: Record<string, string>) => void;
};

const inertia = vi.hoisted(() => ({
    submissions: [] as Submission[],
    errors: {} as Record<string, string>,
    beforeHandlers: [] as Array<(event: VisitEvent) => void>,
    deferPosts: false,
    pendingPosts: [] as PendingPost[],
}));

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
            on: (event: string, handler: (event: VisitEvent) => void) => {
                if (event === 'before') {
                    inertia.beforeHandlers.push(handler);
                }

                return () => {
                    const index = inertia.beforeHandlers.indexOf(handler);

                    if (index >= 0) {
                        inertia.beforeHandlers.splice(index, 1);
                    }
                };
            },
        },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, updateData] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const [processing, setProcessing] = React.useState(false);
            const dataRef = React.useRef(data);
            dataRef.current = data;

            const setData = (
                keyOrData: keyof T | T | ((current: T) => T),
                value?: T[keyof T],
            ) => {
                updateData((current) => {
                    const next =
                        typeof keyOrData === 'function'
                            ? keyOrData(current)
                            : typeof keyOrData === 'object'
                              ? keyOrData
                              : { ...current, [keyOrData]: value };
                    dataRef.current = next;

                    return next;
                });
            };

            return {
                data,
                errors,
                processing,
                setData,
                post: (url: string, options?: PostOptions) => {
                    setProcessing(true);
                    inertia.submissions.push({
                        url,
                        data: { ...dataRef.current },
                    });

                    const succeed = () => {
                        setErrors({});
                        setProcessing(false);
                        options?.onSuccess?.();
                    };
                    const fail = (nextErrors: Record<string, string>) => {
                        setErrors(nextErrors);
                        setProcessing(false);
                        options?.onError?.(nextErrors);
                    };

                    if (inertia.deferPosts) {
                        inertia.pendingPosts.push({ succeed, fail });
                    } else if (Object.keys(inertia.errors).length > 0) {
                        fail(inertia.errors);
                    } else {
                        succeed();
                    }
                },
            };
        },
    };
});

const encounterPublicId = '01ENC000000000000000000001';

const placement: InpatientPlacementSnapshot = {
    ward_public_id: '01WARD0000000000000000001',
    ward_code: 'ANGGREK',
    ward_display_name: 'Bangsal Anggrek',
    bed_public_id: '01BED00000000000000000001',
    bed_code: 'ANG-101-A',
    bed_display_name: 'Tempat Tidur A',
    room_label: 'Ruang 101',
    service_class: 'Kelas 1',
};

function dailyDocument(
    overrides: Partial<InpatientDailyDocument> = {},
): InpatientDailyDocument {
    return {
        public_id: '01DOC000000000000000000001',
        document_type: 'NURSING_DAILY',
        state: 'DRAFT',
        definition_version: 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1',
        service_date: '2026-08-30',
        version: 2,
        fields: {
            nursing_observation: 'Pasien sadar dan kooperatif.',
            nursing_intervention: 'Pemantauan berkala dilakukan.',
            nursing_evaluation: 'Kondisi stabil.',
            additional_notes: '',
        },
        author: { public_id: '01USER1', name: 'Perawat Anggrek' },
        is_current_actor_document: true,
        placement_snapshot: placement,
        encounter_status_snapshot: 'REGISTERED',
        created_at: '2026-08-30T08:00:00+07:00',
        updated_at: '2026-08-30T09:00:00+07:00',
        finalized_at: null,
        ...overrides,
    };
}

function pageProps(): InpatientDocumentationShowProps {
    const nursing = dailyDocument();

    return {
        variant: 'rawat-inap',
        encounter: {
            public_id: encounterPublicId,
            status: 'REGISTERED',
            care_setting: 'INPATIENT',
            registered_at: '2026-08-29T21:00:00+07:00',
            patient: {
                public_id: '01PATIENT1',
                medical_record_number: 'RM-260830-001',
                full_name: 'Budi Santoso',
                date_of_birth: '1980-01-01',
                sex: 'LAKI_LAKI',
            },
            placement: {
                ward_public_id: placement.ward_public_id,
                ward_code: placement.ward_code,
                ward_display_name: placement.ward_display_name,
                bed_public_id: placement.bed_public_id,
                bed_code: placement.bed_code,
                bed_display_name: placement.bed_display_name,
                room_label: placement.room_label,
                service_class: placement.service_class,
            },
        },
        legacyEntries: [
            {
                public_id: '01LEGACY1',
                entry_type: 'NURSING_INTAKE',
                body: 'Catatan lama dipertahankan.',
                created_at: '2026-08-29T22:00:00+07:00',
                author_name: 'Perawat Jaga',
            },
        ],
        documentation: {
            available: true,
            unavailable_reason: null,
            definition_version: 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1',
            documents: [
                nursing,
                dailyDocument({
                    public_id: '01DOC000000000000000000002',
                    document_type: 'MEDICAL_DAILY',
                    fields: {
                        subjective: 'Keluhan berkurang.',
                        objective: 'Keadaan umum baik.',
                        assessment: 'Kondisi stabil.',
                        plan: 'Lanjut pemantauan.',
                        additional_notes: '',
                    },
                    author: { public_id: '01USER2', name: 'dr. Citra' },
                    is_current_actor_document: false,
                }),
            ],
            versions: [
                {
                    public_id: '01VERSION1',
                    document_public_id: nursing.public_id,
                    document_type: 'NURSING_DAILY',
                    state: 'DRAFT',
                    encounter_public_id: encounterPublicId,
                    care_setting: 'INPATIENT',
                    definition_version: nursing.definition_version,
                    service_date: nursing.service_date,
                    version: 1,
                    fields: {
                        nursing_observation: 'Pasien sadar.',
                    },
                    actor_name: 'Perawat Anggrek',
                    author_name: 'Perawat Anggrek',
                    placement_snapshot: {
                        ...placement,
                        ward_display_name: 'Nama Bangsal Saat Versi Dibuat',
                    },
                    encounter_status_snapshot: 'REGISTERED',
                    created_at: '2026-08-30T08:00:00+07:00',
                    finalized_at: null,
                },
            ],
        },
        permissions: {
            nursing: {
                can_save_draft: true,
                can_finalize: true,
                editable_document_public_id: nursing.public_id,
            },
            medical: {
                can_save_draft: false,
                can_finalize: false,
                editable_document_public_id: null,
            },
        },
        actions: {
            nursing: {
                save_draft_url: '/inpatient/nursing/draft',
                finalize_url: '/inpatient/nursing/final',
            },
            medical: { save_draft_url: null, finalize_url: null },
        },
        discharge_summary: {
            definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
            summary: null,
            versions: [],
            permission: {
                can_save_draft: false,
                can_finalize: false,
            },
            actions: {
                save_draft_url: null,
                finalize_url: null,
            },
        },
        inpatient_discharge_coding_source: dischargeCodingSourceProjection(),
        inpatient_discharge: routineDischargeProjection(),
        inpatient_summary_addendum: {
            definition_version: 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1',
            available: false,
            reason_options: [],
            can_request: false,
            store_url: null,
            requests: [],
        },
        location_history: {
            history_baseline: 'LEGACY_CURRENT_PLACEMENT',
            history_complete: false,
            current_placement: placement,
            current_sequence: 0,
            events: [],
            transfer: {
                allowed: true,
                url: '/pendaftaran/rawat-inap/episode/bed-transfer',
                expected_location_sequence: 0,
                expected_source_bed_public_id: placement.bed_public_id,
                target_beds: [
                    {
                        ...placement,
                        bed_public_id: '01BED00000000000000000002',
                        bed_code: 'ANG-101-B',
                        bed_display_name: 'Tempat Tidur B',
                    },
                ],
            },
        },
    };
}

function dischargeCodingSourceProjection(
    overrides: Partial<InpatientDischargeCodingSourceProjection> = {},
): InpatientDischargeCodingSourceProjection {
    return {
        definition_version: 'INPATIENT_DISCHARGE_CODING_SOURCE_V1',
        source: null,
        versions: [],
        permission: { can_save_draft: false, can_finalize: false },
        actions: { save_draft_url: null, finalize_url: null },
        ...overrides,
    };
}

function finalDischargeCodingSourceProjection(): InpatientDischargeCodingSourceProjection {
    const fields = {
        principal_diagnosis_statement: 'Pneumonia komunitas.',
        secondary_diagnosis_statements: ['Hipertensi terkontrol.'],
        procedure_attestation: 'NO_PROCEDURE_RECORDED' as const,
        performed_procedure_statements: [],
    };

    return dischargeCodingSourceProjection({
        source: {
            public_id: '01DISCHARGECODINGSOURCE00001',
            state: 'FINAL',
            version: 3,
            assigned_physician: {
                public_id: '01PHYSICIAN000000000000001',
                name: 'dr. Citra',
            },
            fields,
            finalized_at: '2026-08-30T10:05:00+07:00',
        },
        versions: [
            {
                public_id: '01DISCHARGECODINGVERSION0001',
                state: 'FINAL',
                version: 3,
                fields,
                actor_name: 'dr. Citra',
                created_at: '2026-08-30T10:05:00+07:00',
            },
        ],
        permission: { can_save_draft: false, can_finalize: false },
        actions: { save_draft_url: null, finalize_url: null },
    });
}

function draftDischargeCodingSourceProjection(): InpatientDischargeCodingSourceProjection {
    const finalProjection = finalDischargeCodingSourceProjection();

    return dischargeCodingSourceProjection({
        source: {
            ...finalProjection.source!,
            state: 'DRAFT',
            version: 2,
            finalized_at: null,
        },
        versions: [
            {
                ...finalProjection.versions[0],
                state: 'DRAFT',
                version: 2,
                public_id: '01DISCHARGECODINGVERSION0002',
            },
        ],
        permission: { can_save_draft: true, can_finalize: true },
        actions: {
            save_draft_url: '/discharge-coding-source/draft',
            finalize_url: '/discharge-coding-source/finalize',
        },
    });
}

function routineDischargeProjection(
    overrides: Partial<InpatientRoutineDischargeProjection> = {},
): InpatientRoutineDischargeProjection {
    return {
        disposition: {
            code: 'PULANG_ATAS_IZIN_DOKTER',
            label: 'Pulang atas izin dokter',
        },
        record: null,
        permission: { can_execute: false },
        requirements: {
            expected_summary_version: null,
            current_location_sequence: 0,
            source_bed_public_id: null,
        },
        actions: { execute_url: null },
        ...overrides,
    };
}

function dischargeSummaryProjection(
    overrides: Partial<InpatientDischargeSummaryProjection> = {},
): InpatientDischargeSummaryProjection {
    const fields = {
        admission_reason: 'Pneumonia komunitas.',
        significant_findings: 'Infiltrat paru kanan, saturasi membaik.',
        care_and_treatment_summary: 'Antibiotik dan terapi suportif.',
        condition_at_discharge: 'Stabil dan dapat beraktivitas ringan.',
        follow_up_plan: 'Kontrol poliklinik dalam tujuh hari.',
    };

    return {
        definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
        summary: {
            public_id: '01DISCHARGESUMMARY000000001',
            state: 'DRAFT',
            version: 2,
            definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
            fields,
            assigned_physician: {
                public_id: '01PHYSICIAN000000000000001',
                name: 'dr. Citra',
            },
            created_at: '2026-08-30T08:00:00+07:00',
            updated_at: '2026-08-30T09:00:00+07:00',
            finalized_at: null,
        },
        versions: [
            {
                public_id: '01DISCHARGEVERSION000000001',
                state: 'DRAFT',
                version: 1,
                definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
                fields: { ...fields, condition_at_discharge: '' },
                actor_name: 'dr. Citra',
                created_at: '2026-08-30T08:00:00+07:00',
                finalized_at: null,
            },
            {
                public_id: '01DISCHARGEVERSION000000002',
                state: 'DRAFT',
                version: 2,
                definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
                fields,
                actor_name: 'dr. Citra',
                created_at: '2026-08-30T09:00:00+07:00',
                finalized_at: null,
            },
        ],
        permission: { can_save_draft: true, can_finalize: true },
        actions: {
            save_draft_url: '/discharge-summary/draft',
            finalize_url: '/discharge-summary/finalize',
        },
        ...overrides,
    };
}

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: { 'color-contrast': { enabled: false } },
    });

    expect(
        result.violations,
        result.violations
            .map(
                (violation) =>
                    `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
            )
            .join('\n'),
    ).toHaveLength(0);
}

describe('structured inpatient longitudinal documentation', () => {
    beforeEach(() => {
        inertia.submissions.length = 0;
        inertia.beforeHandlers.length = 0;
        inertia.pendingPosts.length = 0;
        inertia.deferPosts = false;
        inertia.errors = {};
    });

    it('submits only the nursing fields, version contract, and a lowercase retry-stable idempotency key', async () => {
        inertia.errors = { expected_version: 'Versi dokumen sudah berubah.' };
        const user = userEvent.setup();

        render(
            <InpatientDailyDocumentForm
                type="NURSING_DAILY"
                definitionVersion="INPATIENT_LONGITUDINAL_DOCUMENTATION_V1"
                permission={{
                    can_save_draft: true,
                    can_finalize: false,
                    editable_document_public_id: null,
                }}
                actions={{
                    save_draft_url: '/nursing/draft',
                    finalize_url: null,
                }}
            />,
        );

        expect(screen.queryByLabelText('Subjective')).not.toBeInTheDocument();
        await user.type(
            screen.getByLabelText('Nursing observation'),
            'Pasien tenang.',
        );
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        const summary = screen.getByRole('alert');
        expect(summary).toHaveFocus();
        expect(summary).toHaveTextContent('Versi dokumen sudah berubah.');
        expect(inertia.submissions).toHaveLength(1);
        const first = inertia.submissions[0];
        expect(first.url).toBe('/nursing/draft');
        expect(first.data).toMatchObject({
            definition_version: 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1',
            expected_version: 0,
            fields: {
                nursing_observation: 'Pasien tenang.',
                nursing_intervention: '',
                nursing_evaluation: '',
                additional_notes: '',
            },
        });
        expect(first.data.idempotency_key).toMatch(
            /^inpatient-draft-[a-z0-9-]+$/,
        );

        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(inertia.submissions[1].data.idempotency_key).toBe(
            first.data.idempotency_key,
        );

        await user.type(screen.getByLabelText('Nursing evaluation'), 'Stabil.');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(inertia.submissions[2].data.idempotency_key).not.toBe(
            first.data.idempotency_key,
        );
    });

    it('focuses the required-final summary and finalizes only a complete stored draft', async () => {
        const user = userEvent.setup();
        const incomplete = dailyDocument({
            fields: {
                nursing_observation: 'Pasien sadar.',
                nursing_intervention: '',
                nursing_evaluation: '',
                additional_notes: '',
            },
        });
        const firstRender = render(
            <InpatientDailyDocumentForm
                type="NURSING_DAILY"
                definitionVersion={incomplete.definition_version}
                document={incomplete}
                permission={{
                    can_save_draft: true,
                    can_finalize: true,
                    editable_document_public_id: incomplete.public_id,
                }}
                actions={{
                    save_draft_url: '/nursing/draft',
                    finalize_url: '/nursing/final',
                }}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );
        const summary = screen.getByRole('alert');
        expect(summary).toHaveFocus();
        expect(summary).toHaveTextContent(
            'Nursing intervention is required before finalization.',
        );
        expect(summary).toHaveTextContent(
            'Nursing evaluation is required before finalization.',
        );
        expect(inertia.submissions).toHaveLength(0);

        firstRender.unmount();
        render(
            <InpatientDailyDocumentForm
                type="NURSING_DAILY"
                definitionVersion={incomplete.definition_version}
                document={dailyDocument()}
                permission={{
                    can_save_draft: true,
                    can_finalize: true,
                    editable_document_public_id: incomplete.public_id,
                }}
                actions={{
                    save_draft_url: '/nursing/draft',
                    finalize_url: '/nursing/final',
                }}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0].url).toBe('/nursing/final');
        expect(inertia.submissions[0].data).toEqual({
            definition_version: 'INPATIENT_LONGITUDINAL_DOCUMENTATION_V1',
            expected_version: 2,
            idempotency_key: expect.stringMatching(
                /^inpatient-final-[a-z0-9-]+$/,
            ),
        });
    });

    it('requires permission and a non-null URL, and keeps Final content read-only', () => {
        const { rerender } = render(
            <InpatientDailyDocumentForm
                type="MEDICAL_DAILY"
                definitionVersion="INPATIENT_LONGITUDINAL_DOCUMENTATION_V1"
                permission={{
                    can_save_draft: true,
                    can_finalize: true,
                    editable_document_public_id: null,
                }}
                actions={{ save_draft_url: null, finalize_url: null }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Finalize version' }),
        ).not.toBeInTheDocument();

        const finalMedical = dailyDocument({
            document_type: 'MEDICAL_DAILY',
            state: 'FINAL',
            fields: {
                subjective: 'Keluhan membaik.',
                objective: 'Keadaan umum baik.',
                assessment: 'Stabil.',
                plan: 'Pemantauan.',
                additional_notes: '',
            },
        });
        rerender(
            <InpatientDailyDocumentForm
                type="MEDICAL_DAILY"
                definitionVersion={finalMedical.definition_version}
                document={finalMedical}
                permission={{
                    can_save_draft: true,
                    can_finalize: true,
                    editable_document_public_id: finalMedical.public_id,
                }}
                actions={{
                    save_draft_url: '/medical/draft',
                    finalize_url: '/medical/final',
                }}
            />,
        );

        expect(screen.getByLabelText('Subjective')).toHaveAttribute('readonly');
        expect(
            screen.getByText(
                'The final document and all its versions are read-only.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
    });

    it('retains edits across accessible tabs, guards navigation, and exposes immutable placement history', async () => {
        const user = userEvent.setup();
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { container } = render(
            <StructuredInpatientEncounterShow {...pageProps()} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Budi Santoso', level: 1 }),
        ).toBeVisible();
        expect(
            screen.getByRole('tablist', {
                name: 'Inpatient episode sections',
            }),
        ).toBeVisible();
        const observation = screen.getByLabelText('Nursing observation');
        await user.type(observation, ' Perubahan belum disimpan.');

        await user.click(
            screen.getByRole('tab', { name: /Document version history/ }),
        );
        expect(observation).not.toBeVisible();
        expect(
            screen.getByText('Nama Bangsal Saat Versi Dibuat'),
        ).toBeInTheDocument();

        const versionsTab = screen.getByRole('tab', {
            name: /Document version history/,
        });
        versionsTab.focus();
        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('tab', { name: 'Legacy notes' })).toHaveFocus();
        expect(screen.getByText('Catatan lama dipertahankan.')).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: /Discharge|Order|Prescription/,
            }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('tab', { name: 'Daily documentation' }),
        );
        expect(observation).toHaveValue(
            'Pasien sadar dan kooperatif. Perubahan belum disimpan.',
        );

        await waitFor(() => expect(inertia.beforeHandlers).toHaveLength(1));
        const preventDefault = vi.fn();
        inertia.beforeHandlers[0]({
            detail: { visit: { method: 'get' } },
            preventDefault,
        });
        expect(confirm).toHaveBeenCalledWith(inpatientUnsavedWarning);
        expect(preventDefault).toHaveBeenCalledOnce();

        const beforeUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(beforeUnload);
        expect(beforeUnload.defaultPrevented).toBe(true);
        await expectNoWcag21Violations(container);
        confirm.mockRestore();
    });

    it('shows other actors documents as read-only attributable heads', () => {
        render(<StructuredInpatientEncounterShow {...pageProps()} />);

        const otherHeading = screen.getByRole('heading', {
            name: 'Daily notes by other healthcare professionals',
        });
        const otherSection = otherHeading.closest('section');
        expect(otherSection).not.toBeNull();
        expect(
            within(otherSection!).getByText('dr. Citra · 2026-08-30'),
        ).toBeVisible();
        expect(
            within(otherSection!).getByText('Draft · Version 2'),
        ).toBeVisible();
    });

    it('saves only the discharge-summary Draft contract with a fresh idempotency key', async () => {
        const user = userEvent.setup();
        const projection = dischargeSummaryProjection({
            summary: null,
            versions: [],
            permission: { can_save_draft: true, can_finalize: false },
            actions: {
                save_draft_url: '/discharge-summary/draft',
                finalize_url: null,
            },
        });
        render(
            <InpatientDischargeSummaryPanel
                projection={projection}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.type(
            screen.getByLabelText('Reason for Admission'),
            'Demam dan sesak.',
        );
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toEqual({
            url: '/discharge-summary/draft',
            data: {
                definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
                expected_version: 0,
                fields: {
                    admission_reason: 'Demam dan sesak.',
                    significant_findings: '',
                    care_and_treatment_summary: '',
                    condition_at_discharge: '',
                    follow_up_plan: '',
                },
                idempotency_key: expect.stringMatching(
                    /^inpatient-discharge-draft-[a-z0-9-]+$/,
                ),
            },
        });
    });

    it('confirms Final and reuses the stored Draft without replacement fields', async () => {
        const user = userEvent.setup();
        render(
            <InpatientDischargeSummaryPanel
                projection={dischargeSummaryProjection()}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );
        expect(
            screen.getByRole('dialog', {
                name: 'Finalize the discharge summary?',
            }),
        ).toBeVisible();
        expect(inertia.submissions).toHaveLength(0);

        await user.click(screen.getByRole('button', { name: 'Yes, finalize' }));

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toEqual({
            url: '/discharge-summary/finalize',
            data: {
                definition_version: 'ROUTINE_INPATIENT_DISCHARGE_SUMMARY_V1',
                expected_version: 2,
                idempotency_key: expect.stringMatching(
                    /^inpatient-discharge-final-[a-z0-9-]+$/,
                ),
            },
        });
        expect(inertia.submissions[0].data).not.toHaveProperty('fields');
    });

    it('synchronizes both mutation versions after projection updates while preserving genuine unsaved edits', async () => {
        const user = userEvent.setup();
        const initial = dischargeSummaryProjection();
        const cleanRender = render(
            <InpatientDischargeSummaryPanel
                projection={initial}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );
        const next = dischargeSummaryProjection();
        next.summary = {
            ...next.summary!,
            version: 3,
            fields: {
                ...next.summary!.fields,
                admission_reason: 'Alasan masuk terbaru dari server.',
            },
        };
        cleanRender.rerender(
            <InpatientDischargeSummaryPanel
                projection={next}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await waitFor(() =>
            expect(screen.getByLabelText('Reason for Admission')).toHaveValue(
                'Alasan masuk terbaru dari server.',
            ),
        );
        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );
        await user.click(screen.getByRole('button', { name: 'Yes, finalize' }));
        expect(inertia.submissions[0].data.expected_version).toBe(3);

        cleanRender.unmount();
        inertia.submissions.length = 0;
        const dirtyRender = render(
            <InpatientDischargeSummaryPanel
                projection={initial}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );
        const admissionReason = screen.getByLabelText('Reason for Admission');
        await user.clear(admissionReason);
        await user.type(admissionReason, 'Perubahan lokal belum disimpan.');
        const newer = dischargeSummaryProjection();
        newer.summary = {
            ...newer.summary!,
            version: 4,
            fields: {
                ...newer.summary!.fields,
                admission_reason: 'Isi server yang tidak boleh menimpa lokal.',
            },
        };
        dirtyRender.rerender(
            <InpatientDischargeSummaryPanel
                projection={newer}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await waitFor(() =>
            expect(screen.getByLabelText('Reason for Admission')).toHaveValue(
                'Perubahan lokal belum disimpan.',
            ),
        );
        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        expect(inertia.submissions[0].data).toMatchObject({
            expected_version: 4,
            fields: {
                admission_reason: 'Perubahan lokal belum disimpan.',
            },
        });
    });

    it('locks narrative editing during Draft processing and marks only the submitted snapshot as saved', async () => {
        inertia.deferPosts = true;
        const user = userEvent.setup();
        render(
            <InpatientDischargeSummaryPanel
                projection={dischargeSummaryProjection()}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );
        const admissionReason = screen.getByLabelText('Reason for Admission');
        await user.clear(admissionReason);
        await user.type(admissionReason, 'Snapshot yang dikirim.');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() =>
            expect(admissionReason).toHaveAttribute('readonly'),
        );
        expect(
            screen.getByRole('button', { name: 'Menyimpan…' }),
        ).toBeDisabled();
        await user.type(admissionReason, ' Tidak boleh masuk.');
        expect(admissionReason).toHaveValue('Snapshot yang dikirim.');
        expect(inertia.submissions[0].data).toMatchObject({
            fields: { admission_reason: 'Snapshot yang dikirim.' },
        });

        act(() => inertia.pendingPosts[0].succeed());
        await waitFor(() =>
            expect(admissionReason).not.toHaveAttribute('readonly'),
        );
        expect(
            screen.queryByText('Save draft changes before finalizing.'),
        ).not.toBeInTheDocument();
    });

    it('focuses an accessible discharge-summary domain error', async () => {
        inertia.errors = {
            discharge_summary:
                'Ringkasan pulang telah berubah. Muat ulang sebelum melanjutkan.',
        };
        const user = userEvent.setup();
        const { container } = render(
            <InpatientDischargeSummaryPanel
                projection={dischargeSummaryProjection()}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Save draft' }));
        const error = screen.getByRole('alert');
        expect(error).toHaveFocus();
        expect(error).toHaveTextContent(
            'Ringkasan pulang telah berubah. Muat ulang sebelum melanjutkan.',
        );
        await expectNoWcag21Violations(container);
    });

    it('requires complete stored content and keeps terminal Final content read-only with ordered history', async () => {
        const user = userEvent.setup();
        const incomplete = dischargeSummaryProjection();
        incomplete.summary!.fields.follow_up_plan = '';
        const firstRender = render(
            <InpatientDischargeSummaryPanel
                projection={incomplete}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Finalize version' }),
        );
        const summary = screen.getByRole('alert');
        expect(summary).toHaveFocus();
        expect(summary).toHaveTextContent(
            'Follow-up Plan is required before finalization.',
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(inertia.submissions).toHaveLength(0);

        firstRender.unmount();
        const finalProjection = dischargeSummaryProjection();
        finalProjection.summary = {
            ...finalProjection.summary!,
            state: 'FINAL',
            version: 3,
            finalized_at: '2026-08-30T10:00:00+07:00',
        };
        finalProjection.permission = {
            can_save_draft: false,
            can_finalize: false,
        };
        finalProjection.actions = {
            save_draft_url: null,
            finalize_url: null,
        };
        render(
            <InpatientDischargeSummaryPanel
                projection={finalProjection}
                routineDischargeProjection={routineDischargeProjection()}
                disabledByUnsavedDocument={false}
            />,
        );

        expect(screen.getByLabelText('Reason for Admission')).toHaveAttribute(
            'readonly',
        );
        expect(
            screen.getByText(
                'The final discharge summary and all its versions are read-only.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();
        const versionHeadings = screen.getAllByRole('heading', {
            name: /Version \d · Draft/,
        });
        expect(versionHeadings.map((heading) => heading.textContent)).toEqual([
            'Version 1 · Draft',
            'Version 2 · Draft',
        ]);
    });

    it('requires an explicit procedure statement and saves the exact diagnosis-source Draft contract', async () => {
        const user = userEvent.setup();
        const projection = dischargeCodingSourceProjection({
            permission: { can_save_draft: true, can_finalize: false },
            actions: {
                save_draft_url: '/discharge-coding-source/draft',
                finalize_url: null,
            },
        });
        const { container } = render(
            <InpatientDischargeCodingSourcePanel projection={projection} />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Save diagnosis and procedure draft',
            }),
        );
        const error = screen.getByRole('alert');
        expect(error).toHaveFocus();
        expect(error).toHaveTextContent(
            'Select a procedure statement before saving.',
        );
        expect(inertia.submissions).toHaveLength(0);

        await user.type(
            screen.getByLabelText('Primary diagnosis'),
            'Pneumonia komunitas.',
        );
        await user.click(
            screen.getByRole('radio', {
                name: 'No procedures performed',
            }),
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Add secondary diagnosis',
            }),
        );
        await user.type(
            screen.getByLabelText('Secondary diagnosis 1'),
            'Hipertensi terkontrol.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Save diagnosis and procedure draft',
            }),
        );

        expect(inertia.submissions).toEqual([
            {
                url: '/discharge-coding-source/draft',
                data: {
                    definition_version: 'INPATIENT_DISCHARGE_CODING_SOURCE_V1',
                    expected_version: 0,
                    fields: {
                        principal_diagnosis_statement: 'Pneumonia komunitas.',
                        secondary_diagnosis_statements: [
                            'Hipertensi terkontrol.',
                        ],
                        procedure_attestation: 'NO_PROCEDURE_RECORDED',
                        performed_procedure_statements: [],
                    },
                    idempotency_key: expect.stringMatching(
                        /^inpatient-discharge-coding-draft-[a-z0-9-]+$/,
                    ),
                },
            },
        ]);
        await expectNoWcag21Violations(container);
    });

    it('requires performed procedure text and locks diagnosis-source editing while Draft saves', async () => {
        inertia.deferPosts = true;
        const user = userEvent.setup();
        render(
            <InpatientDischargeCodingSourcePanel
                projection={draftDischargeCodingSourceProjection()}
            />,
        );

        await user.click(
            screen.getByRole('radio', {
                name: 'Procedures were performed',
            }),
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Save diagnosis and procedure draft',
            }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Enter every performed procedure, or select no procedures performed.',
        );
        expect(inertia.submissions).toHaveLength(0);

        const procedure = screen.getByLabelText('Procedure 1');
        await user.type(procedure, 'Bronkoskopi diagnostik.');
        await user.click(
            screen.getByRole('button', {
                name: 'Save diagnosis and procedure draft',
            }),
        );

        await waitFor(() => expect(procedure).toHaveAttribute('readonly'));
        expect(
            screen.getByRole('button', { name: 'Menyimpan…' }),
        ).toBeDisabled();
        await user.type(procedure, ' Tidak boleh berubah.');
        expect(procedure).toHaveValue('Bronkoskopi diagnostik.');
        expect(inertia.submissions[0].data).toMatchObject({
            expected_version: 2,
            fields: {
                procedure_attestation: 'PROCEDURES_RECORDED',
                performed_procedure_statements: ['Bronkoskopi diagnostik.'],
            },
        });

        act(() => inertia.pendingPosts[0].succeed());
        await waitFor(() => expect(procedure).not.toHaveAttribute('readonly'));
    });

    it('focuses an accessible diagnosis-source domain error', async () => {
        inertia.errors = {
            discharge_coding_source:
                'Diagnosis dan prosedur akhir telah berubah. Muat ulang sebelum melanjutkan.',
        };
        const user = userEvent.setup();
        const { container } = render(
            <InpatientDischargeCodingSourcePanel
                projection={draftDischargeCodingSourceProjection()}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Save diagnosis and procedure draft',
            }),
        );

        const error = screen.getByRole('alert');
        expect(error).toHaveFocus();
        expect(error).toHaveTextContent(
            'Diagnosis dan prosedur akhir telah berubah. Muat ulang sebelum melanjutkan.',
        );
        await expectNoWcag21Violations(container);
    });

    it('finalizes only the stored diagnosis-source Draft and keeps Final history immutable', async () => {
        const user = userEvent.setup();
        const view = render(
            <InpatientDischargeCodingSourcePanel
                projection={draftDischargeCodingSourceProjection()}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Finalize diagnoses and procedures',
            }),
        );
        const dialog = screen.getByRole('dialog', {
            name: 'Finalize diagnoses and procedures?',
        });
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Yes, finalize',
            }),
        );

        expect(inertia.submissions).toEqual([
            {
                url: '/discharge-coding-source/finalize',
                data: {
                    definition_version: 'INPATIENT_DISCHARGE_CODING_SOURCE_V1',
                    expected_version: 2,
                    idempotency_key: expect.stringMatching(
                        /^inpatient-discharge-coding-final-[a-z0-9-]+$/,
                    ),
                },
            },
        ]);
        expect(inertia.submissions[0].data).not.toHaveProperty('fields');

        view.rerender(
            <InpatientDischargeCodingSourcePanel
                projection={finalDischargeCodingSourceProjection()}
            />,
        );
        expect(screen.getByLabelText('Primary diagnosis')).toHaveAttribute(
            'readonly',
        );
        expect(
            screen.getByText('Final diagnoses and procedures are read-only.'),
        ).toBeVisible();
        expect(
            screen.getByText('Diagnosis and procedure version history'),
        ).toBeVisible();
        expect(screen.getByText('Version 3 · Final')).toBeVisible();
    });

    it('shows both Final prerequisites and submits the exact atomic discharge contract with processing locked', async () => {
        inertia.deferPosts = true;
        const user = userEvent.setup();
        const draftSummary = dischargeSummaryProjection();
        const routineDischarge = routineDischargeProjection({
            permission: { can_execute: true },
            requirements: {
                expected_summary_version: 3,
                current_location_sequence: 2,
                source_bed_public_id: placement.bed_public_id,
            },
            actions: { execute_url: '/discharge-summary/execute' },
        });
        const view = render(
            <InpatientDischargeSummaryPanel
                projection={draftSummary}
                codingSourceProjection={finalDischargeCodingSourceProjection()}
                routineDischargeProjection={routineDischarge}
                disabledByUnsavedDocument={false}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Episode Completion' }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', {
                name: 'Complete Episode and Release Bed',
            }),
        ).toBeDisabled();
        expect(screen.getByText('Not final')).toBeVisible();

        const finalSummary = dischargeSummaryProjection();
        finalSummary.summary = {
            ...finalSummary.summary!,
            state: 'FINAL',
            version: 3,
            finalized_at: '2026-08-30T10:00:00+07:00',
        };
        finalSummary.permission = {
            can_save_draft: false,
            can_finalize: false,
        };
        finalSummary.actions = {
            save_draft_url: null,
            finalize_url: null,
        };
        view.rerender(
            <InpatientDischargeSummaryPanel
                projection={finalSummary}
                codingSourceProjection={finalDischargeCodingSourceProjection()}
                routineDischargeProjection={routineDischarge}
                disabledByUnsavedDocument={false}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Episode Completion' }),
        ).toBeVisible();
        expect(screen.getByText('Pulang atas izin dokter')).toBeVisible();
        await user.click(
            screen.getByRole('button', {
                name: 'Complete Episode and Release Bed',
            }),
        );
        expect(
            screen.getByRole('dialog', {
                name: 'Complete the inpatient episode?',
            }),
        ).toBeVisible();
        await user.click(
            screen.getByRole('button', { name: 'Yes, complete episode' }),
        );

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toEqual({
            url: '/discharge-summary/execute',
            data: {
                expected_summary_version: 3,
                expected_location_sequence: 2,
                source_bed_public_id: placement.bed_public_id,
                idempotency_key: expect.stringMatching(
                    /^inpatient-routine-discharge-[a-z0-9-]+$/,
                ),
            },
        });
        expect(inertia.submissions[0].data).not.toHaveProperty(
            'disposition_code',
        );
        expect(
            screen.getByRole('button', { name: 'Completing episode…' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Review again' }),
        ).toBeDisabled();

        act(() => inertia.pendingPosts[0].succeed());
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );

        view.rerender(
            <InpatientDischargeSummaryPanel
                projection={finalSummary}
                codingSourceProjection={finalDischargeCodingSourceProjection()}
                routineDischargeProjection={routineDischargeProjection({
                    record: {
                        public_id: '01DISCHARGE000000000000001',
                        disposition_code: 'PULANG_ATAS_IZIN_DOKTER',
                        disposition_label: 'Pulang atas izin dokter',
                        discharge_summary_public_id:
                            finalSummary.summary!.public_id,
                        discharge_summary_version: 3,
                        location_sequence: 2,
                        source_bed_public_id: placement.bed_public_id,
                        source_bed_code: placement.bed_code,
                        encounter_status_before: 'IN_EXAMINATION',
                        encounter_status_after: 'READY_FOR_RM',
                        discharged_at: '2026-08-31T11:00:00+07:00',
                    },
                    requirements: {
                        expected_summary_version: null,
                        current_location_sequence: 2,
                        source_bed_public_id: placement.bed_public_id,
                    },
                })}
                disabledByUnsavedDocument={false}
            />,
        );
        await waitFor(() => expect(screen.getByRole('status')).toHaveFocus());
    });

    it('focuses an accessible routine-discharge domain error', async () => {
        inertia.errors = {
            inpatient_discharge:
                'Penempatan telah berubah. Muat ulang sebelum melanjutkan.',
        };
        const user = userEvent.setup();
        const finalSummary = dischargeSummaryProjection();
        finalSummary.summary = {
            ...finalSummary.summary!,
            state: 'FINAL',
            version: 3,
            finalized_at: '2026-08-30T10:00:00+07:00',
        };
        finalSummary.permission = {
            can_save_draft: false,
            can_finalize: false,
        };
        finalSummary.actions = {
            save_draft_url: null,
            finalize_url: null,
        };
        const { container } = render(
            <InpatientDischargeSummaryPanel
                projection={finalSummary}
                codingSourceProjection={finalDischargeCodingSourceProjection()}
                routineDischargeProjection={routineDischargeProjection({
                    permission: { can_execute: true },
                    requirements: {
                        expected_summary_version: 3,
                        current_location_sequence: 2,
                        source_bed_public_id: placement.bed_public_id,
                    },
                    actions: { execute_url: '/discharge-summary/execute' },
                })}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Complete Episode and Release Bed',
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Yes, complete episode' }),
        );

        const error = screen.getByRole('alert');
        expect(error).toHaveFocus();
        expect(error).toHaveTextContent(
            'Penempatan telah berubah. Muat ulang sebelum melanjutkan.',
        );
        await expectNoWcag21Violations(container);
    });

    it('blocks routine discharge while another page document is dirty and renders the terminal fact as Siap RM', async () => {
        const user = userEvent.setup();
        const props = pageProps();
        props.discharge_summary = dischargeSummaryProjection();
        props.discharge_summary.summary = {
            ...props.discharge_summary.summary!,
            state: 'FINAL',
            version: 3,
            finalized_at: '2026-08-30T10:00:00+07:00',
        };
        props.discharge_summary.permission = {
            can_save_draft: false,
            can_finalize: false,
        };
        props.discharge_summary.actions = {
            save_draft_url: null,
            finalize_url: null,
        };
        props.inpatient_discharge_coding_source =
            finalDischargeCodingSourceProjection();
        props.inpatient_discharge = routineDischargeProjection({
            permission: { can_execute: true },
            requirements: {
                expected_summary_version: 3,
                current_location_sequence: 2,
                source_bed_public_id: placement.bed_public_id,
            },
            actions: { execute_url: '/discharge-summary/execute' },
        });
        const view = render(<StructuredInpatientEncounterShow {...props} />);

        await user.type(
            screen.getByLabelText('Nursing observation'),
            ' Perubahan belum disimpan.',
        );
        await user.click(
            screen.getByRole('tab', { name: 'Discharge summary' }),
        );
        expect(
            screen.getByRole('button', {
                name: 'Complete Episode and Release Bed',
            }),
        ).toBeDisabled();
        expect(
            screen.getByText(
                'Save or discard document changes before completing episode.',
            ),
        ).toBeVisible();

        const terminalProps = pageProps();
        terminalProps.encounter.status = 'READY_FOR_RM';
        terminalProps.discharge_summary = props.discharge_summary;
        terminalProps.inpatient_discharge = routineDischargeProjection({
            record: {
                public_id: '01DISCHARGE000000000000001',
                disposition_code: 'PULANG_ATAS_IZIN_DOKTER',
                disposition_label: 'Pulang atas izin dokter',
                discharge_summary_public_id:
                    props.discharge_summary.summary!.public_id,
                discharge_summary_version: 3,
                location_sequence: 2,
                source_bed_public_id: placement.bed_public_id,
                source_bed_code: placement.bed_code,
                encounter_status_before: 'IN_EXAMINATION',
                encounter_status_after: 'READY_FOR_RM',
                discharged_at: '2026-08-31T11:00:00+07:00',
            },
            requirements: {
                expected_summary_version: null,
                current_location_sequence: 2,
                source_bed_public_id: placement.bed_public_id,
            },
        });
        view.unmount();
        const { container } = render(
            <StructuredInpatientEncounterShow {...terminalProps} />,
        );
        expect(screen.getByText('Ready for medical records')).toBeVisible();
        expect(screen.getByLabelText('Last placement')).toBeInTheDocument();

        await user.click(
            screen.getByRole('tab', { name: 'Discharge summary' }),
        );
        const terminal = screen.getByRole('status');
        expect(terminal).toHaveTextContent(
            'Episode complete · Ready for medical records',
        );
        expect(terminal).toHaveTextContent('Pulang atas izin dokter');
        expect(terminal).toHaveTextContent('Bed released');
        expect(terminal).toHaveTextContent(placement.bed_code);
        expect(
            screen.queryByRole('button', {
                name: 'Complete Episode and Release Bed',
            }),
        ).not.toBeInTheDocument();
        await expectNoWcag21Violations(container);
    });

    it('includes unsaved diagnosis-source edits in the page navigation guard', async () => {
        const user = userEvent.setup();
        const confirm = vi
            .spyOn(window, 'confirm')
            .mockImplementation(() => false);
        const props = pageProps();
        props.discharge_summary = dischargeSummaryProjection();
        props.inpatient_discharge_coding_source =
            draftDischargeCodingSourceProjection();

        render(<StructuredInpatientEncounterShow {...props} />);
        await user.click(
            screen.getByRole('tab', { name: 'Discharge summary' }),
        );
        const principal = screen.getByLabelText('Primary diagnosis');
        await user.type(principal, ' Perubahan belum disimpan.');

        await waitFor(() => expect(inertia.beforeHandlers).toHaveLength(1));
        const preventDefault = vi.fn();
        inertia.beforeHandlers[0]({
            detail: { visit: { method: 'get' } },
            preventDefault,
        });
        expect(confirm).toHaveBeenCalledWith(inpatientUnsavedWarning);
        expect(preventDefault).toHaveBeenCalledOnce();

        const beforeUnload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(beforeUnload);
        expect(beforeUnload.defaultPrevented).toBe(true);
        confirm.mockRestore();
    });

    it('integrates the accessible discharge-summary tab and its honest workflow boundary', async () => {
        const user = userEvent.setup();
        const props = pageProps();
        props.discharge_summary = dischargeSummaryProjection();
        const { container } = render(
            <StructuredInpatientEncounterShow {...props} />,
        );

        await user.click(
            screen.getByRole('tab', { name: 'Discharge summary' }),
        );
        expect(
            screen.getByRole('heading', { name: 'Discharge Summary' }),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Finalization completes the discharge summary. Episode status and bed use remain unchanged until the episode is completed.',
            ),
        ).toBeVisible();
        await expectNoWcag21Violations(container);
    });

    it('keeps an encounter without managed placement as legacy read-only detail', async () => {
        const user = userEvent.setup();
        const props = pageProps();
        props.encounter.placement = null;
        props.documentation = {
            ...props.documentation,
            available: false,
            unavailable_reason:
                'Managed ward and bed placement is not available for this legacy episode.',
            documents: [],
            versions: [],
        };
        props.permissions = {
            nursing: {
                can_save_draft: false,
                can_finalize: false,
                editable_document_public_id: null,
            },
            medical: {
                can_save_draft: false,
                can_finalize: false,
                editable_document_public_id: null,
            },
        };
        props.actions = {
            nursing: { save_draft_url: null, finalize_url: null },
            medical: { save_draft_url: null, finalize_url: null },
        };

        render(<StructuredInpatientEncounterShow {...props} />);

        expect(
            screen.getByText(
                'Managed ward and bed placement is not available for this legacy episode.',
            ),
        ).toBeVisible();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save draft' }),
        ).not.toBeInTheDocument();

        await user.click(screen.getByRole('tab', { name: 'Legacy notes' }));
        expect(screen.getByText('Catatan lama dipertahankan.')).toBeVisible();
    });
});
