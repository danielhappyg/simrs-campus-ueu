import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EmergencyDispositionPanel } from './emergency-disposition-panel';
import { EmergencyDocumentationPanel } from './emergency-document-panel';
import { EmergencyDiagnosticAssignmentHistory } from './emergency-follow-up-history';
import { EmergencyTriagePanel } from './emergency-triage-panel';
import { EmergencyTriageVocabularyMaster } from './emergency-triage-vocabulary-master';
import {
    EmergencyTriageWorklist,
    EmergencyWorklist,
} from './emergency-worklist';
import type {
    EmergencyDispositionProjection,
    EmergencyDocumentProjection,
    EmergencyFollowUpProjection,
    EmergencyTriageAssessment,
    EmergencyTriageProjection,
    EmergencyTriageVocabularyMasterProps,
    EmergencyWorklistProps,
} from './types';

type Submission = { url: string; data: Record<string, unknown> };

const inertia = vi.hoisted(() => ({
    submissions: [] as Submission[],
    gets: [] as Array<{ url: string; data: Record<string, unknown> }>,
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
            get: (url: string, data: Record<string, unknown>) =>
                inertia.gets.push({ url, data }),
        },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, updateData] = React.useState(initial);
            const dataRef = React.useRef(data);
            const transformRef = React.useRef<
                (current: T) => Record<string, unknown>
            >((current) => current);
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
                errors: {},
                processing: false,
                setData,
                transform: (
                    callback: (current: T) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                },
                post: (url: string, options?: { onSuccess?: () => void }) => {
                    inertia.submissions.push({
                        url,
                        data: transformRef.current(dataRef.current),
                    });
                    options?.onSuccess?.();
                },
            };
        },
    };
});

const category = {
    code: 'MERAH' as const,
    rank: 1,
    display_name: 'MERAH',
    text_cue: 'Prioritas segera',
    colour_token: 'critical',
    guidance_text: null,
};

const triageProjection = (canWrite = true): EmergencyTriageProjection => ({
    definition_version: 'STRUCTURED_EMERGENCY_TRIAGE_DISPOSITION_V1',
    vocabulary: {
        public_id: 'triage-vocabulary-01',
        version: 2,
        state: 'ACTIVE',
        effective_at: '2026-09-01T08:00:00+07:00',
        categories: [
            category,
            {
                ...category,
                code: 'KUNING',
                rank: 2,
                display_name: 'KUNING',
                text_cue: 'Prioritas mendesak',
            },
            {
                ...category,
                code: 'HIJAU',
                rank: 3,
                display_name: 'HIJAU',
                text_cue: 'Prioritas rendah',
            },
            {
                ...category,
                code: 'HITAM',
                rank: 4,
                display_name: 'HITAM',
                text_cue: 'Kategori hitam',
            },
        ],
    },
    assessments: [],
    current: null,
    permission: { can_write: canWrite },
    actions: {
        finalize_initial_url: canWrite ? '/triage/finalize' : null,
        reassess_url: null,
    },
});

const finalDocumentProjection = (): EmergencyDocumentProjection => ({
    definition_version: 'EMERGENCY_DOCUMENT_V1',
    current: { nursing: null, medical: null },
    versions: [],
    permissions: {
        nursing: { can_save_draft: true, can_finalize: false },
        medical: { can_save_draft: false, can_finalize: false },
    },
    actions: {
        nursing: {
            save_draft_url: '/emergency/documents/nursing/draft',
            finalize_url: null,
        },
        medical: { save_draft_url: null, finalize_url: null },
    },
});

const followUpProjection: EmergencyFollowUpProjection = {
    unresolved_diagnostics: [],
    diagnostic_assignment_history: [],
    physician_options: [{ value: 'physician-02', label: 'dr. Bima' }],
    unresolved_diagnostic_count: 0,
    permission: { can_propose: false, can_accept: false },
    all_assignments_accepted: true,
};

const dispositionProjection = (): EmergencyDispositionProjection => ({
    current: null,
    history: [],
    handoff: null,
    correction_intents: [],
    bed_options: [],
    permissions: {
        can_sign: true,
        can_correct: false,
        can_handoff: false,
        can_compensate: false,
    },
    requirements: {
        initial_triage_final: true,
        nursing_final: true,
        medical_final: true,
        diagnostic_follow_up_resolved: true,
    },
    actions: {
        sign_url: '/emergency/disposition',
        correct_url: null,
        create_correction_intent_url: null,
        handoff_url: null,
        compensate_url: null,
    },
});

const worklist: EmergencyWorklistProps = {
    encounters: [
        {
            public_id: 'encounter-01',
            status: 'IN_EXAMINATION',
            registered_at: '2026-09-01T08:00:00+07:00',
            queue_number: 1,
            payer_type: 'UMUM',
            chief_complaint: 'Sesak napas',
            waiting_minutes: 12,
            patient: {
                public_id: 'patient-01',
                medical_record_number: 'RM-000001',
                full_name: 'Pasien Contoh',
                date_of_birth: '1990-01-01',
                sex: 'LAKI_LAKI',
            },
            triage: {
                current_category: 'MERAH',
                current_category_label: 'MERAH',
                last_assessed_at: '2026-09-01T08:05:00+07:00',
                reassessment_count: 1,
            },
        },
    ],
    filters: { q: '', date_from: '', date_to: '', payer: '' },
    canOpen: true,
    payerOptions: [{ value: 'UMUM', label: 'Umum' }],
};

const vocabularyMasterProps: EmergencyTriageVocabularyMasterProps = {
    vocabularies: [],
    permissions: { can_manage: true },
    commands: { create_url: '/emergency/triage-vocabularies' },
};

beforeEach(() => {
    inertia.submissions = [];
    inertia.gets = [];
});

describe('structured emergency frontend', () => {
    it('keeps the triage vocabulary lifecycle admin-only and submits four fixed categories', async () => {
        const user = userEvent.setup();
        const { container, rerender } = render(
            <EmergencyTriageVocabularyMaster
                {...vocabularyMasterProps}
                permissions={{ can_manage: false }}
                commands={{ create_url: null }}
            />,
        );
        expect(container).toBeEmptyDOMElement();

        rerender(
            <EmergencyTriageVocabularyMaster {...vocabularyMasterProps} />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Add vocabulary' }),
        );
        await user.type(
            screen.getByRole('textbox', { name: 'Permanent code' }),
            'igd_triage_v2',
        );
        expect(
            screen.getAllByText(/The code and rank \d are fixed/),
        ).toHaveLength(4);
        await user.click(
            screen.getByRole('button', { name: 'Save vocabulary' }),
        );

        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/triage-vocabularies',
            data: {
                code: 'IGD_TRIAGE_V2',
                display_name: 'Emergency triage categories',
                categories: [
                    { code: 'MERAH', rank: 1 },
                    { code: 'KUNING', rank: 2 },
                    { code: 'HIJAU', rank: 3 },
                    { code: 'HITAM', rank: 4 },
                ],
            },
        });
        expect((await axe.run(container)).violations).toEqual([]);
    });

    it('renders the dedicated IGD worklist with text-plus-colour triage and accessible controls', async () => {
        const { container } = render(<EmergencyWorklist {...worklist} />);
        expect(
            screen.getByRole('heading', { name: 'Emergency Department' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/MERAH · Immediate priority/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Open emergency episode Pasien Contoh',
            }),
        ).toHaveAttribute('href', '/pemeriksaan/igd/encounter-01');
        expect((await axe.run(container)).violations).toEqual([]);
    });

    it('keeps triage on its dedicated worklist and detail route', () => {
        render(<EmergencyTriageWorklist {...worklist} />);
        expect(
            screen.getByRole('heading', { name: 'Emergency triage worklist' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Open emergency episode Pasien Contoh',
            }),
        ).toHaveAttribute('href', '/pemeriksaan/triage/encounter-01');
    });

    it('submits a complete manual initial triage without deriving the category from vital signs', async () => {
        const user = userEvent.setup();
        render(<EmergencyTriagePanel projection={triageProjection()} />);
        await user.click(screen.getByRole('radio', { name: /MERAH/ }));
        await user.type(
            screen.getByLabelText('Presenting concern'),
            'Sesak sejak satu jam.',
        );
        await user.type(
            screen.getByLabelText('Clinical basis for category'),
            'Penilaian manual berdasarkan ABCDE.',
        );
        await user.type(
            screen.getByLabelText('Condition on arrival'),
            'Datang dibantu keluarga.',
        );

        for (const label of [
            'A · Airway',
            'B · Breathing',
            'C · Circulation',
            'D · Disability / neurological status',
            'E · Exposure / complete examination',
        ]) {
            await user.selectOptions(
                screen.getByLabelText(new RegExp(`^${label}`)),
                'ASSESSED_NO_CONCERN',
            );
        }

        const vitalLabels = [
            'Respiratory rate',
            'Pulse',
            'Systolic pressure',
            'Diastolic pressure',
            'Oxygen saturation',
            'Temperature',
            'Pain score',
            'Weight',
        ];
        vitalLabels.forEach((label, index) =>
            fireEvent.change(screen.getByLabelText(new RegExp(`^${label}`)), {
                target: {
                    value: index === 5 ? '37.2' : index === 7 ? '60' : '20',
                },
            }),
        );
        fireEvent.submit(
            screen
                .getByRole('button', { name: 'Finalize initial assessment' })
                .closest('form')!,
        );
        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0]).toMatchObject({
            url: '/triage/finalize',
            data: {
                category_code: 'MERAH',
                vocabulary_public_id: 'triage-vocabulary-01',
                vocabulary_version: 2,
            },
        });
    });

    it('exposes only the nurse Draft action and keeps the physician document read-only', async () => {
        const user = userEvent.setup();
        render(
            <EmergencyDocumentationPanel
                projection={finalDocumentProjection()}
            />,
        );
        const nursing = screen.getByRole('region', {
            name: 'Emergency Department nursing documentation',
        });
        const textareas = within(nursing).getAllByRole('textbox');

        for (const textarea of textareas) {
            await user.type(textarea, 'Bukti dokumentasi terstruktur.');
        }

        await user.click(
            within(nursing).getByRole('button', { name: 'Save draft' }),
        );
        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/documents/nursing/draft',
        });
        expect(
            screen.queryByRole('button', { name: /Finalize document/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'This document is displayed as read-only evidence.',
            ),
        ).toBeInTheDocument();
    });

    it('presents exactly five dispositions and submits the selected physician-signed branch', async () => {
        const user = userEvent.setup();
        render(
            <EmergencyDispositionPanel
                disposition={dispositionProjection()}
                followUp={followUpProjection}
            />,
        );
        expect(screen.getAllByRole('radio')).toHaveLength(5);
        const form = screen
            .getByRole('button', { name: 'Sign disposition' })
            .closest('form');
        expect(form).not.toBeNull();

        for (const textbox of within(form!).getAllByRole('textbox')) {
            await user.type(textbox, 'Informasi klinis lengkap.');
        }

        await user.click(
            screen.getByRole('button', { name: 'Sign disposition' }),
        );
        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/disposition',
            data: { disposition_type: 'PULANG' },
        });
    });

    it('binds each follow-up proposal to one unresolved diagnostic order', async () => {
        const user = userEvent.setup();
        const followUp: EmergencyFollowUpProjection = {
            unresolved_diagnostics: [
                {
                    order_type: 'LABORATORY',
                    order_public_id: 'lab-order-01',
                    label: 'Darah Lengkap',
                    fingerprint: 'a'.repeat(64),
                    current: null,
                    history: [],
                    actions: {
                        propose_url: '/follow-up/lab-order-01/propose',
                        accept_url: null,
                    },
                },
            ],
            diagnostic_assignment_history: [],
            physician_options: [{ value: 'physician-02', label: 'dr. Bima' }],
            unresolved_diagnostic_count: 1,
            permission: { can_propose: true, can_accept: false },
            all_assignments_accepted: false,
        };
        render(
            <EmergencyDispositionPanel
                disposition={dispositionProjection()}
                followUp={followUp}
            />,
        );
        await user.type(
            screen.getByLabelText('Assignment reason'),
            'Dokter jaga berikutnya.',
        );
        await user.type(
            screen.getByLabelText('Handoff note'),
            'Pantau hasil dan tindak lanjuti.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Propose responsible physician',
            }),
        );
        expect(inertia.submissions[0]).toMatchObject({
            url: '/follow-up/lab-order-01/propose',
            data: {
                order_type: 'LABORATORY',
                order_public_id: 'lab-order-01',
                expected_result_fingerprint: 'a'.repeat(64),
                assignee_physician_public_id: 'physician-02',
            },
        });
    });

    it('accepts only the exact proposed assignment fingerprint for its order', async () => {
        const user = userEvent.setup();
        const proposal = {
            public_id: 'proposal-01',
            version: 1,
            state: 'PROPOSED' as const,
            assignee: { public_id: 'physician-02', name: 'dr. Bima' },
            proposed_by: { public_id: 'physician-01', name: 'dr. Ratna' },
            reason: 'Pergantian jaga.',
            effective_at: '2026-09-01T12:00:00+07:00',
            handoff_note: 'Pantau hasil.',
            proposed_at: '2026-09-01T11:50:00+07:00',
            accepted_at: null,
            accepted_by_name: null,
            fingerprint: 'b'.repeat(64),
        };
        const followUp: EmergencyFollowUpProjection = {
            unresolved_diagnostics: [
                {
                    order_type: 'RADIOLOGY',
                    order_public_id: 'rad-order-01',
                    label: 'Foto toraks',
                    fingerprint: 'c'.repeat(64),
                    current: proposal,
                    history: [proposal],
                    actions: {
                        propose_url: null,
                        accept_url: '/follow-up/proposal-01/accept',
                    },
                },
            ],
            diagnostic_assignment_history: [],
            physician_options: [],
            unresolved_diagnostic_count: 1,
            permission: { can_propose: false, can_accept: true },
            all_assignments_accepted: false,
        };
        render(
            <EmergencyDispositionPanel
                disposition={dispositionProjection()}
                followUp={followUp}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Accept assignment' }),
        );
        expect(inertia.submissions[0]).toMatchObject({
            url: '/follow-up/proposal-01/accept',
            data: {
                order_type: 'RADIOLOGY',
                order_public_id: 'rad-order-01',
                proposal_public_id: 'proposal-01',
                expected_proposal_fingerprint: 'b'.repeat(64),
                expected_result_fingerprint: 'c'.repeat(64),
            },
        });
    });

    it('keeps resolved diagnostic assignment chains visible as read-only evidence', async () => {
        const superseded = {
            public_id: 'proposal-history-01',
            version: 1,
            state: 'SUPERSEDED' as const,
            assignee: { public_id: 'physician-02', name: 'dr. Bima' },
            proposed_by: { public_id: 'physician-01', name: 'dr. Ratna' },
            reason: 'Pergantian jaga pertama.',
            effective_at: '2026-09-01T12:00:00+07:00',
            handoff_note: 'Pantau hasil laboratorium.',
            proposed_at: '2026-09-01T11:50:00+07:00',
            accepted_at: null,
            accepted_by_name: null,
            fingerprint: 'e'.repeat(64),
        };
        const accepted = {
            ...superseded,
            public_id: 'proposal-history-02',
            version: 2,
            state: 'ACCEPTED' as const,
            assignee: { public_id: 'physician-03', name: 'dr. Citra' },
            reason: 'Penanggung jawab akhir.',
            accepted_at: '2026-09-01T12:05:00+07:00',
            accepted_by_name: 'dr. Citra',
            fingerprint: 'f'.repeat(64),
        };
        const { container } = render(
            <EmergencyDiagnosticAssignmentHistory
                items={[
                    {
                        order_type: 'LABORATORY',
                        order_public_id: 'lab-resolved-01',
                        label: 'Darah Lengkap',
                        current: accepted,
                        history: [superseded, accepted],
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Diagnostic-result responsibility history',
            }),
        ).toBeInTheDocument();
        await userEvent.click(screen.getByText(/Darah Lengkap/));
        expect(
            screen.getByText('Pergantian jaga pertama.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Penanggung jawab akhir.')).toBeInTheDocument();
        expect(screen.getByText(/Accepted by dr. Citra/)).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        expect((await axe.run(container)).violations).toEqual([]);
    });

    it('lets only the registrar-facing action place a RAWAT_INAP decision into a managed bed', async () => {
        const user = userEvent.setup();
        const projection = dispositionProjection();
        projection.current = {
            public_id: 'disposition-rawat-inap',
            version: 1,
            code: 'RAWAT_INAP',
            label: 'Rawat inap',
            details: {
                admission_reason: 'Observasi lanjutan.',
                receiving_unit_handoff_note: 'Pantau ketat.',
            },
            physician: { public_id: 'physician-01', name: 'dr. Ratna' },
            signed_at: '2026-09-01T10:00:00+07:00',
            correction_reason: null,
            supersedes_public_id: null,
        };
        projection.history = [projection.current];
        projection.permissions = {
            can_sign: false,
            can_correct: false,
            can_handoff: true,
            can_compensate: false,
        };
        projection.actions = {
            sign_url: null,
            correct_url: null,
            create_correction_intent_url: null,
            handoff_url: '/emergency/handoff',
            compensate_url: null,
        };
        projection.bed_options = [
            {
                public_id: 'bed-01',
                code: 'MEL-101-A',
                display_name: 'Tempat tidur A',
                ward_public_id: 'ward-01',
                ward_code: 'MEL',
                ward_display_name: 'Bangsal Melati',
                room_label: '101',
                service_class: 'Kelas 1',
            },
        ];
        render(
            <EmergencyDispositionPanel
                disposition={projection}
                followUp={followUpProjection}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Place patient' }));
        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/handoff',
            data: {
                disposition_public_id: 'disposition-rawat-inap',
                expected_disposition_version: 1,
                bed_public_id: 'bed-01',
            },
        });
    });

    it('creates a physician correction intent after an executed inpatient handoff', async () => {
        const user = userEvent.setup();
        const projection = dispositionProjection();
        projection.current = {
            public_id: 'disposition-01',
            version: 1,
            code: 'RAWAT_INAP',
            label: 'Rawat inap',
            details: {
                admission_reason: 'Perlu pemantauan.',
                receiving_unit_handoff_note: 'Lanjutkan observasi.',
            },
            physician: { public_id: 'physician-01', name: 'dr. Ratna' },
            signed_at: '2026-09-01T09:00:00+07:00',
            correction_reason: null,
            supersedes_public_id: null,
        };
        projection.history = [projection.current];
        projection.handoff = {
            public_id: 'handoff-01',
            state: 'COMPLETED',
            target_encounter_public_id: 'inpatient-01',
            target_encounter_url: '/pemeriksaan/rawat-inap/inpatient-01',
            bed_code: 'BED-01',
            ward_display_name: 'Bangsal Melati',
            registrar_name: 'Petugas Pendaftaran',
            completed_at: '2026-09-01T09:15:00+07:00',
            compensated_at: null,
        };
        projection.permissions.can_sign = false;
        projection.permissions.can_correct = true;
        projection.actions.sign_url = null;
        projection.actions.correct_url = null;
        projection.actions.create_correction_intent_url =
            '/emergency/correction-intents';
        render(
            <EmergencyDispositionPanel
                disposition={projection}
                followUp={followUpProjection}
            />,
        );
        await user.click(screen.getByRole('radio', { name: /Discharge/ }));
        const intentForm = screen
            .getByRole('button', { name: 'Submit correction intent' })
            .closest('form')!;

        for (const textbox of within(intentForm).getAllByRole('textbox')) {
            await user.type(textbox, 'Informasi koreksi lengkap.');
        }

        await user.click(
            screen.getByRole('button', { name: 'Submit correction intent' }),
        );
        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/correction-intents',
            data: {
                expected_disposition_version: 1,
                replacement_type: 'PULANG',
                reason: 'Informasi koreksi lengkap.',
            },
        });
    });

    it('lets the physician revoke a pending correction intent with its exact fingerprint', async () => {
        const user = userEvent.setup();
        const projection = dispositionProjection();
        projection.current = {
            public_id: 'disposition-01',
            version: 1,
            code: 'RAWAT_INAP',
            label: 'Rawat inap',
            details: {},
            physician: { public_id: 'physician-01', name: 'dr. Ratna' },
            signed_at: '2026-09-01T09:00:00+07:00',
            correction_reason: null,
            supersedes_public_id: null,
        };
        projection.handoff = {
            public_id: 'handoff-01',
            state: 'COMPLETED',
            target_encounter_public_id: 'inpatient-01',
            target_encounter_url: '/pemeriksaan/rawat-inap/inpatient-01',
            bed_code: 'BED-01',
            ward_display_name: 'Bangsal Melati',
            registrar_name: 'Petugas Pendaftaran',
            completed_at: '2026-09-01T09:15:00+07:00',
            compensated_at: null,
        };
        projection.correction_intents = [
            {
                public_id: 'intent-01',
                state: 'PENDING',
                replacement_code: 'PULANG',
                replacement_label: 'Pulang',
                reason: 'Kondisi sudah stabil.',
                physician_name: 'dr. Ratna',
                expires_at: '2026-09-01T11:00:00+07:00',
                created_at: '2026-09-01T10:00:00+07:00',
                fingerprint: 'd'.repeat(64),
                actions: { revoke_url: '/emergency/correction-intents/revoke' },
            },
        ];

        render(
            <EmergencyDispositionPanel
                disposition={projection}
                followUp={followUpProjection}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Revoke correction intent' }),
        );
        await user.type(
            screen.getByRole('textbox', { name: 'Revocation reason' }),
            'Rencana koreksi dibatalkan oleh dokter.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Revoke correction intent' }),
        );

        expect(inertia.submissions[0]).toMatchObject({
            url: '/emergency/correction-intents/revoke',
            data: {
                expected_intent_fingerprint: 'd'.repeat(64),
                reason: 'Rencana koreksi dibatalkan oleh dokter.',
            },
        });
    });

    it('renders the triage timeline as read-only evidence for roles without write permission', () => {
        const assessment: EmergencyTriageAssessment = {
            public_id: 'assessment-01',
            version: 1,
            kind: 'INITIAL',
            category,
            vocabulary_public_id: 'triage-vocabulary-01',
            vocabulary_version: 2,
            observed_at: '2026-09-01T08:05:00+07:00',
            recorded_at: '2026-09-01T08:06:00+07:00',
            assessor: { public_id: 'nurse-01', name: 'Ns. Sinta' },
            late_entry_reason: null,
            reassessment_reason: null,
            presenting_concern: 'Sesak',
            clinical_basis: 'Penilaian ABCDE manual',
            arrival_condition: 'Sadar',
            abcde: {
                airway: { state: 'ASSESSED_NO_CONCERN', note: null },
                breathing: { state: 'ASSESSED_CONCERN', note: 'Sesak' },
                circulation: { state: 'ASSESSED_NO_CONCERN', note: null },
                disability: { state: 'ASSESSED_NO_CONCERN', note: null },
                exposure: { state: 'ASSESSED_NO_CONCERN', note: null },
            },
            consciousness: 'ALERT',
            vitals: {
                respiratory_rate: 24,
                pulse: 100,
                systolic_pressure: 120,
                diastolic_pressure: 80,
                oxygen_saturation: 92,
                temperature: 37,
                pain_score: 2,
                weight: null,
            },
            unobtainable_fields: ['weight'],
            unobtainable_reason: 'Tidak aman ditimbang',
            trauma: false,
            trauma_note: null,
            isolation_precaution: false,
            isolation_note: null,
            handoff_note: null,
        };
        const projection = triageProjection(false);
        projection.assessments = [assessment];
        projection.current = assessment;
        render(<EmergencyTriagePanel projection={projection} />);
        expect(
            screen.queryByRole('button', { name: /asesmen ulang/i }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Ns. Sinta')).toBeInTheDocument();
        expect(screen.getByText('Penilaian ABCDE manual')).toBeInTheDocument();
    });
});
