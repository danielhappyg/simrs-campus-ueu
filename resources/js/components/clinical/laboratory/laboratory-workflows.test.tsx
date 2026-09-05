import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { LaboratoryEncounterPanel } from './laboratory-encounter-panel';
import { LaboratoryMasterPanel } from './laboratory-master-panel';
import { LaboratoryWorklist } from './laboratory-worklist';
import type {
    LaboratoryEncounterProjection,
    LaboratoryMasterProps,
    LaboratoryOrderProjection,
    LaboratoryWorklistProps,
} from './types';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string;
        children?: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: inertia.get },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);
        const setData = (fieldOrData: keyof T | T, value?: T[keyof T]) => {
            if (typeof fieldOrData === 'object') {
                setDataState(fieldOrData);
            } else {
                setDataState((current) => ({
                    ...current,
                    [fieldOrData]: value,
                }));
            }
        };
        const submit = (
            method: 'post' | 'patch',
            url: string,
            options?: { onSuccess?: () => void },
        ) => {
            inertia[method](url, data, options);
            options?.onSuccess?.();
        };

        return {
            data,
            errors: inertia.errors,
            processing: false,
            setData,
            post: (url: string, options?: object) =>
                submit('post', url, options),
            patch: (url: string, options?: object) =>
                submit('patch', url, options),
            reset: (...fields: Array<keyof T>) =>
                setDataState((current) => ({
                    ...current,
                    ...Object.fromEntries(
                        fields.map((field) => [field, initial[field]]),
                    ),
                })),
        };
    },
}));

const examination = {
    public_id: 'exam-cbc',
    code: 'LAB-CBC',
    display_name: 'Darah Lengkap',
    specimen_type: 'Darah EDTA',
    collection_instruction: 'Balik tabung perlahan setelah pengambilan.',
    components: [
        {
            code: 'HB',
            display_name: 'Hemoglobin',
            value_kind: 'NUMERIC' as const,
            unit_text: 'g/dL',
            reference_text: '12–16',
            critical_allowed: true,
        },
    ],
};

const specimen = {
    public_id: 'specimen-01',
    attempt_number: 1,
    label_identifier: 'LAB-260901-0001',
    state: 'ACCEPTED' as const,
    collected_at: '2026-09-01T08:10:00+07:00',
    collector_name: 'Ns. Sinta',
    collection_note: null,
    received_at: '2026-09-01T08:20:00+07:00',
    receiver_name: 'Budi, ATLM',
    assessed_at: '2026-09-01T08:21:00+07:00',
    assessor_name: 'Budi, ATLM',
    rejection_reason_label: null,
    rejection_note: null,
};

const order: LaboratoryOrderProjection = {
    public_id: 'order-01',
    version: 4,
    state: 'SPECIMEN_ACCEPTED',
    priority: 'URGENT',
    ordered_at: '2026-09-01T08:00:00+07:00',
    ordering_physician_public_id: 'physician-01',
    ordering_physician_name: 'dr. Ratna',
    care_setting: 'INPATIENT',
    care_location_label: 'Bangsal Melati',
    encounter_number: 'RI-260901-001',
    encounter_url: '/pemeriksaan/rawat-inap/encounter-01',
    patient: {
        medical_record_number: 'RM-000001',
        display_name: 'Pasien Contoh',
    },
    examination,
    clinical_question: 'Evaluasi anemia akut.',
    cancellation: null,
    specimens: [specimen],
    accepted_specimen_public_id: specimen.public_id,
    result: {
        public_id: 'result-01',
        version: 1,
        state: 'DRAFT',
        author_name: 'Budi, ATLM',
        saved_at: '2026-09-01T08:35:00+07:00',
        verifier_name: null,
        verified_at: null,
        components: [
            {
                ...examination.components[0],
                value: '5.1',
                interpretation: 'CRITICAL',
                note: null,
            },
        ],
        critical_communication: null,
        amendments: [],
        acknowledgement: null,
    },
    actions: {
        cancel_url: null,
        collect_url: null,
        receive_url: null,
        accept_url: null,
        reject_url: null,
        save_result_url: '/laboratory/orders/order-01/results',
        verify_result_url: '/laboratory/orders/order-01/verify',
        amend_result_url: null,
        acknowledge_url: null,
    },
};

const encounterProjection: LaboratoryEncounterProjection = {
    definition_version: 'CROSS_SETTING_LABORATORY_SPECIMEN_RESULT_V1',
    encounter: {
        public_id: 'encounter-01',
        care_setting: 'INPATIENT',
        status: 'IN_EXAMINATION',
    },
    examination_options: [examination],
    priority_options: [
        { value: 'ROUTINE', label: 'Rutin' },
        { value: 'URGENT', label: 'Segera' },
    ],
    cancellation_reason_options: [
        { value: 'CLINICAL_CHANGE', label: 'Perubahan kebutuhan klinis' },
    ],
    rejection_reason_options: [],
    orders: [],
    permissions: {
        can_order: true,
        can_cancel_own_order: true,
        can_collect: false,
        can_acknowledge_own_order: false,
    },
    commands: { create_order_url: '/encounters/encounter-01/laboratory' },
};

const worklistProps: LaboratoryWorklistProps = {
    generated_at: '2026-09-01T09:00:00+07:00',
    filters: { q: '', care_setting: '', state: '', priority: '' },
    filter_options: {
        care_settings: [{ value: 'INPATIENT', label: 'Rawat inap' }],
        states: [{ value: 'SPECIMEN_ACCEPTED', label: 'Spesimen diterima' }],
        priorities: [{ value: 'URGENT', label: 'Segera' }],
    },
    orders: [order],
    permissions: {
        can_collect: false,
        can_process_specimen: false,
        can_save_result: false,
        can_verify_result: true,
    },
    rejection_reason_options: [
        { value: 'HEMOLYSED', label: 'Spesimen hemolisis' },
    ],
    interpretation_options: [
        { value: 'NORMAL', label: 'Normal' },
        { value: 'ABNORMAL', label: 'Abnormal' },
        { value: 'CRITICAL', label: 'Kritis' },
    ],
    communication_method_options: [{ value: 'TELEPHONE', label: 'Telepon' }],
    communication_outcome_options: [
        { value: 'COMMUNICATED', label: 'Tersampaikan' },
        { value: 'ESCALATED', label: 'Dieskalasikan' },
    ],
    critical_communication_recipient_options: [
        { value: 'physician-01', label: 'dr. Ratna · dokter pemesan' },
        { value: 'physician-cover', label: 'dr. Maya · dokter jaga' },
    ],
    amendment_reason_options: [{ value: 'CORRECTION', label: 'Koreksi hasil' }],
};

const masterProps: LaboratoryMasterProps = {
    examinations: [
        {
            ...examination,
            state: 'ACTIVE',
            version: 2,
            actions: {
                update_url: '/laboratory/masters/exam-cbc',
                retire_url: '/laboratory/masters/exam-cbc/retire',
            },
        },
    ],
    value_kind_options: [
        { value: 'TEXT', label: 'Teks' },
        { value: 'NUMERIC', label: 'Angka' },
        { value: 'QUALITATIVE', label: 'Kualitatif' },
    ],
    permissions: { can_manage: true },
    commands: { create_url: '/laboratory/masters' },
};

async function expectAccessible(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a'] },
        rules: { 'color-contrast': { enabled: false } },
    });
    expect(result.violations).toHaveLength(0);
}

describe('laboratory frontend contracts', () => {
    beforeEach(() => {
        inertia.get.mockReset();
        inertia.post.mockReset();
        inertia.patch.mockReset();
        inertia.errors = {};
    });

    it('creates a governed order with a stable retry key and accessible controls', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <LaboratoryEncounterPanel projection={encounterProjection} />,
        );

        await user.selectOptions(
            screen.getByLabelText('Examination'),
            'exam-cbc',
        );
        await user.selectOptions(screen.getByLabelText('Priority'), 'URGENT');
        await user.type(
            screen.getByLabelText('Clinical question'),
            'Evaluasi anemia.',
        );
        await user.click(screen.getByRole('button', { name: 'Save request' }));

        const firstKey = (
            inertia.post.mock.calls.at(-1)?.[1] as { idempotency_key: string }
        ).idempotency_key;
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/encounters/encounter-01/laboratory',
            expect.objectContaining({
                examination_public_id: 'exam-cbc',
                priority: 'URGENT',
            }),
            expect.objectContaining({ errorBag: 'laboratoryOrder' }),
        );

        await user.click(screen.getByRole('button', { name: 'Save request' }));
        expect(
            (inertia.post.mock.calls.at(-1)?.[1] as { idempotency_key: string })
                .idempotency_key,
        ).toBe(firstKey);
        expect(
            screen
                .getAllByRole('button')
                .every((button) => button.className.includes('min-h-11')),
        ).toBe(true);
        await expectAccessible(container);
    });

    it('requires critical communication details in the verifier task', async () => {
        const user = userEvent.setup();
        const { container } = render(<LaboratoryWorklist {...worklistProps} />);

        expect(screen.getByText('Result draft')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Verify result' }));
        expect(
            screen.getByText(/require a communication record/i),
        ).toBeInTheDocument();
        await user.type(
            screen.getByLabelText('Communication time'),
            '2026-09-01T08:40',
        );
        await user.selectOptions(screen.getByLabelText('Metode'), 'TELEPHONE');
        await user.selectOptions(
            screen.getByLabelText('Communication outcome'),
            'COMMUNICATED',
        );
        await user.click(
            screen.getAllByRole('button', { name: 'Verify result' }).at(-1)!,
        );

        expect(inertia.post).toHaveBeenLastCalledWith(
            '/laboratory/orders/order-01/verify',
            expect.objectContaining({
                critical_communication: expect.objectContaining({
                    communication_method: 'TELEPHONE',
                    recipient_user_public_id: 'physician-01',
                    outcome: 'COMMUNICATED',
                }),
            }),
            expect.objectContaining({
                errorBag: 'laboratoryVerify.order-01',
            }),
        );
        await expectAccessible(container);
    });

    it('uses a bounded covering-physician recipient for an escalated critical handoff', async () => {
        const user = userEvent.setup();
        const { container } = render(<LaboratoryWorklist {...worklistProps} />);

        await user.click(screen.getByRole('button', { name: 'Verify result' }));
        await user.type(
            screen.getByLabelText('Communication time'),
            '2026-09-01T08:42',
        );
        await user.selectOptions(screen.getByLabelText('Metode'), 'TELEPHONE');
        await user.selectOptions(
            screen.getByLabelText('Communication outcome'),
            'ESCALATED',
        );
        expect(
            screen.getByLabelText('Escalation recipient physician'),
        ).toBeRequired();
        await user.selectOptions(
            screen.getByLabelText('Escalation recipient physician'),
            'physician-cover',
        );
        expect(
            screen.getByLabelText(
                'Communication note (required for escalation)',
            ),
        ).toBeRequired();
        expect(
            screen.getByText(/Explain the escalation reason/i),
        ).toBeInTheDocument();
        await user.type(
            screen.getByLabelText(
                'Communication note (required for escalation)',
            ),
            'Dokter pemesan tidak tersedia; diteruskan ke dokter jaga.',
        );
        await user.click(
            screen.getAllByRole('button', { name: 'Verify result' }).at(-1)!,
        );

        const payload = inertia.post.mock.calls.at(-1)?.[1] as {
            critical_communication: Record<string, string>;
        };
        expect(payload.critical_communication).toEqual({
            communicated_at: '2026-09-01T08:42',
            communication_method: 'TELEPHONE',
            recipient_user_public_id: 'physician-cover',
            outcome: 'ESCALATED',
            note: 'Dokter pemesan tidak tersedia; diteruskan ke dokter jaga.',
        });
        await expectAccessible(container);
    });

    it('offers accept or attributable rejection only for a received specimen', async () => {
        const user = userEvent.setup();
        const receivedOrder: LaboratoryOrderProjection = {
            ...order,
            version: 2,
            state: 'ORDERED',
            result: null,
            specimens: [{ ...specimen, state: 'RECEIVED' }],
            accepted_specimen_public_id: null,
            actions: {
                ...order.actions,
                save_result_url: null,
                verify_result_url: null,
                accept_url: '/laboratory/specimens/specimen-01/accept',
                reject_url: '/laboratory/specimens/specimen-01/reject',
            },
        };

        render(
            <LaboratoryWorklist
                {...worklistProps}
                orders={[receivedOrder]}
                permissions={{
                    can_collect: false,
                    can_process_specimen: true,
                    can_save_result: false,
                    can_verify_result: false,
                }}
            />,
        );

        await user.click(
            screen.getByRole('button', {
                name: 'Assess specimen suitability',
            }),
        );
        await user.selectOptions(
            screen.getByLabelText('Rejection reason'),
            'HEMOLYSED',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Reject and request recollection',
            }),
        );

        expect(inertia.post).toHaveBeenLastCalledWith(
            '/laboratory/specimens/specimen-01/reject',
            expect.objectContaining({
                expected_order_version: 2,
                reason_code: 'HEMOLYSED',
            }),
            expect.objectContaining({
                errorBag: 'laboratoryReject.order-01',
            }),
        );
        expect(inertia.post.mock.calls.at(-1)?.[1]).not.toHaveProperty(
            'specimen_public_id',
        );
    });

    it('keeps Draft result content out of an encounter projection without a released result', () => {
        render(
            <LaboratoryEncounterPanel
                projection={{
                    ...encounterProjection,
                    orders: [order],
                    permissions: {
                        ...encounterProjection.permissions,
                        can_order: false,
                    },
                    commands: { create_order_url: null },
                }}
            />,
        );

        expect(screen.queryByText('5.1')).not.toBeInTheDocument();
        expect(screen.getByText(/LAB-260901-0001/)).toBeInTheDocument();
    });

    it('shows the signed base result, ordered amendment history, communication, and acknowledgement evidence', () => {
        const releasedOrder: LaboratoryOrderProjection = {
            ...order,
            state: 'REPORTED_VERIFIED',
            result: {
                ...order.result!,
                version: 8,
                state: 'VERIFIED',
                author_name: 'Budi, ATLM',
                saved_at: '2026-09-01T08:35:00+07:00',
                verifier_name: 'Sari, Verifikator',
                verified_at: '2026-09-01T08:45:00+07:00',
                components: [
                    {
                        ...examination.components[0],
                        value: '5.1',
                        interpretation: 'ABNORMAL',
                        note: null,
                    },
                ],
                amendments: [
                    {
                        public_id: 'amendment-01',
                        version: 4,
                        reason_label: 'Kesalahan teknis',
                        signer_name: 'Sari, Verifikator',
                        signed_at: '2026-09-01T09:00:00+07:00',
                        critical_communication: null,
                        components: [
                            {
                                ...examination.components[0],
                                value: '5.4',
                                interpretation: 'ABNORMAL',
                                note: 'Koreksi pertama',
                            },
                        ],
                    },
                    {
                        public_id: 'amendment-02',
                        version: 5,
                        reason_label: 'Koreksi transkripsi',
                        signer_name: 'Dewi, Verifikator',
                        signed_at: '2026-09-01T09:10:00+07:00',
                        critical_communication: {
                            communicated_at: '2026-09-01T09:09:00+07:00',
                            method_label: 'Telepon adendum',
                            recipient_physician_name: 'dr. Ratna',
                            outcome_label: 'Tersampaikan',
                            note: 'Dibacakan ulang.',
                        },
                        components: [
                            {
                                ...examination.components[0],
                                value: '5.6',
                                interpretation: 'CRITICAL',
                                note: 'Koreksi kedua',
                            },
                        ],
                    },
                    {
                        public_id: 'amendment-03',
                        version: 6,
                        reason_label: 'Koreksi lanjutan nonkritis',
                        signer_name: 'Ayu, Verifikator',
                        signed_at: '2026-09-01T09:20:00+07:00',
                        critical_communication: null,
                        components: [
                            {
                                ...examination.components[0],
                                value: '12.6',
                                interpretation: 'NORMAL',
                                note: 'Hasil pengukuran ulang',
                            },
                        ],
                    },
                    {
                        public_id: 'amendment-04',
                        version: 7,
                        reason_label: 'Nilai kritis baru',
                        signer_name: 'Maya, Verifikator',
                        signed_at: '2026-09-01T09:30:00+07:00',
                        critical_communication: {
                            communicated_at: '2026-09-01T09:29:00+07:00',
                            method_label: 'Kanal internal aman',
                            recipient_physician_name: 'dr. Maya',
                            outcome_label: 'Dieskalasikan',
                            note: 'Dokter pemesan tidak tersedia.',
                        },
                        components: [
                            {
                                ...examination.components[0],
                                value: '5.2',
                                interpretation: 'CRITICAL',
                                note: 'Kritis setelah pengukuran ulang',
                            },
                        ],
                    },
                    {
                        public_id: 'amendment-05',
                        version: 8,
                        reason_label: 'Perubahan nilai kritis',
                        signer_name: 'Dewi, Verifikator',
                        signed_at: '2026-09-01T09:40:00+07:00',
                        critical_communication: {
                            communicated_at: '2026-09-01T09:39:00+07:00',
                            method_label: 'Telepon',
                            recipient_physician_name: 'dr. Ratna',
                            outcome_label: 'Tersampaikan',
                            note: 'Nilai kritis versi terbaru dibacakan ulang.',
                        },
                        components: [
                            {
                                ...examination.components[0],
                                value: '5.0',
                                interpretation: 'CRITICAL',
                                note: 'Perubahan kritis terbaru',
                            },
                        ],
                    },
                ],
                critical_communication: null,
                acknowledgement: {
                    public_id: 'ack-01',
                    physician_name: 'dr. Ratna',
                    acknowledged_at: '2026-09-01T09:05:00+07:00',
                    is_current: false,
                },
            },
        };
        const encounter = render(
            <LaboratoryEncounterPanel
                projection={{
                    ...encounterProjection,
                    orders: [releasedOrder],
                    permissions: {
                        ...encounterProjection.permissions,
                        can_order: false,
                    },
                    commands: { create_order_url: null },
                }}
            />,
        );

        expect(screen.getByText('Initial verified result')).toBeInTheDocument();
        expect(
            screen.getByLabelText('Initial verified result'),
        ).toHaveTextContent(
            /Budi, ATLM.*08[.:]35.*Sari, Verifikator.*08[.:]45/,
        );
        expect(
            screen.getByText('Verified amendment history'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Verified amendment history'),
        ).toHaveTextContent(/Kesalahan teknis.*09[.:]00.*5\.4/);
        expect(
            screen.getByLabelText('Verified amendment history'),
        ).toHaveTextContent(/Koreksi transkripsi.*09[.:]10.*5\.6/);
        expect(
            screen.getByLabelText('Verified amendment history'),
        ).toHaveTextContent(/Koreksi lanjutan nonkritis.*09[.:]20.*12\.6/);
        expect(
            screen.getByLabelText(
                'Critical-value communication for amendment version 7',
            ),
        ).toHaveTextContent(
            /09[.:]29.*Kanal internal aman.*dr. Maya.*Dieskalasikan.*Dokter pemesan tidak tersedia/,
        );
        expect(
            screen.getByLabelText(
                'Critical-value communication for amendment version 8',
            ),
        ).toHaveTextContent(
            /09[.:]39.*Telepon.*dr. Ratna.*Tersampaikan.*versi terbaru dibacakan ulang/,
        );
        expect(screen.getByText('Kesalahan teknis')).toBeInTheDocument();
        expect(screen.getByText('Koreksi transkripsi')).toBeInTheDocument();
        expect(screen.getByText(/Drafted by Budi, ATLM/)).toBeInTheDocument();
        expect(
            screen.getByText(/Verified by Sari, Verifikator/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Signed by Sari/)).toBeInTheDocument();
        expect(screen.getAllByText(/Signed by Dewi/)).toHaveLength(2);
        expect(screen.getByText('Dibacakan ulang.')).toBeInTheDocument();
        expect(
            screen.getByLabelText(
                'Critical-value communication for amendment version 5',
            ),
        ).toHaveTextContent(
            /09[.:]09.*Telepon adendum.*dr. Ratna.*Tersampaikan.*Dibacakan ulang/,
        );
        expect(
            screen.getAllByText('Critical-value communication for amendment'),
        ).toHaveLength(2);
        expect(
            screen.getByText(
                'Critical-value communication for amendment · still applies to the current result',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Current critical-value communication'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Previous acknowledgement is no longer current'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Ordering physician acknowledgement'),
        ).toHaveTextContent(/dr. Ratna.*09[.:]05/);
        expect(screen.getByText(/^5\.1/)).toBeInTheDocument();
        expect(screen.getByText(/^5\.4/)).toBeInTheDocument();
        expect(screen.getByText(/^5\.6/)).toBeInTheDocument();
        expect(screen.getByText(/^12\.6/)).toBeInTheDocument();
        encounter.unmount();

        render(
            <LaboratoryWorklist
                {...worklistProps}
                orders={[
                    {
                        ...releasedOrder,
                        result: {
                            ...releasedOrder.result!,
                            version: 5,
                            amendments: releasedOrder.result!.amendments.slice(
                                0,
                                2,
                            ),
                            acknowledgement: {
                                ...releasedOrder.result!.acknowledgement!,
                                acknowledged_at: '2026-09-01T09:15:00+07:00',
                                is_current: true,
                            },
                        },
                    },
                ]}
            />,
        );
        expect(
            screen.getByText('Acknowledged by ordering physician'),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Ordering physician acknowledgement'),
        ).toHaveTextContent(/dr. Ratna.*09[.:]15/);
        expect(
            screen.getByText(
                'Critical-value communication for amendment · still applies to the current result',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText(
                'Critical-value communication for amendment version 5',
            ),
        ).toHaveTextContent(
            /09[.:]09.*Telepon adendum.*dr. Ratna.*Tersampaikan.*Dibacakan ulang/,
        );
        expect(
            screen.getByText(/Signed by Dewi, Verifikator/),
        ).toBeInTheDocument();
    });

    it('shows an active legacy worklist row as a read-only archive without governed progress or actions', async () => {
        const legacyActiveOrder: LaboratoryOrderProjection = {
            ...order,
            source: 'LEGACY_READ_ONLY',
            state: 'ORDERED',
            specimens: [],
            accepted_specimen_public_id: null,
            result: null,
            actions: {
                ...order.actions,
                collect_url: '/must-not-be-used/collect',
                receive_url: '/must-not-be-used/receive',
                accept_url: '/must-not-be-used/accept',
                reject_url: '/must-not-be-used/reject',
                save_result_url: '/must-not-be-used/result',
                verify_result_url: '/must-not-be-used/verify',
                amend_result_url: '/must-not-be-used/amend',
            },
        };
        const { container } = render(
            <LaboratoryWorklist
                {...worklistProps}
                orders={[legacyActiveOrder]}
                permissions={{
                    can_collect: true,
                    can_process_specimen: true,
                    can_save_result: true,
                    can_verify_result: true,
                }}
            />,
        );

        expect(screen.getByText('Read-only archive')).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Laboratory examination workflow'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Collect specimen' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Draft result' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Verify result' }),
        ).not.toBeInTheDocument();
        expect(inertia.post).not.toHaveBeenCalled();
        await expectAccessible(container);
    });

    it('shows a completed legacy physician result while suppressing acknowledgement prompts and actions', async () => {
        const legacyCompletedOrder: LaboratoryOrderProjection = {
            ...order,
            source: 'LEGACY_READ_ONLY',
            state: 'REPORTED_VERIFIED',
            specimens: [],
            accepted_specimen_public_id: null,
            result: {
                public_id: 'legacy-result-01',
                version: 1,
                state: 'VERIFIED',
                author_name: 'Petugas Arsip',
                saved_at: '2024-02-01T09:30:00+07:00',
                verifier_name: 'Petugas Arsip',
                verified_at: '2024-02-01T09:30:00+07:00',
                components: [
                    {
                        code: 'LEGACY_RESULT',
                        display_name: 'Hasil tersimpan',
                        value_kind: 'TEXT',
                        value: 'Hemoglobin 12,8 g/dL',
                        unit_text: null,
                        reference_text: null,
                        interpretation: 'NORMAL',
                        note: null,
                    },
                ],
                critical_communication: null,
                amendments: [],
                acknowledgement: null,
            },
            actions: {
                ...order.actions,
                acknowledge_url: '/must-not-be-used/acknowledge',
            },
        };
        const { container } = render(
            <LaboratoryEncounterPanel
                projection={{
                    ...encounterProjection,
                    orders: [legacyCompletedOrder],
                    permissions: {
                        can_order: false,
                        can_cancel_own_order: true,
                        can_collect: true,
                        can_acknowledge_own_order: true,
                    },
                    commands: { create_order_url: null },
                }}
            />,
        );

        expect(screen.getByText('Read-only archive')).toBeInTheDocument();
        expect(screen.getByText('Hemoglobin 12,8 g/dL')).toBeInTheDocument();
        expect(
            screen.getByText(/Drafted by Petugas Arsip/),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText('Laboratory examination workflow'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Awaiting ordering physician acknowledgement'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Mark as acknowledged' }),
        ).not.toBeInTheDocument();
        await expectAccessible(container);
    });

    it('sends exact critical communication evidence when an amendment introduces CRITICAL', async () => {
        const user = userEvent.setup();
        const amendableOrder: LaboratoryOrderProjection = {
            ...order,
            state: 'REPORTED_VERIFIED',
            result: {
                ...order.result!,
                version: 2,
                state: 'VERIFIED',
                verifier_name: 'Sari, Verifikator',
                verified_at: '2026-09-01T08:45:00+07:00',
                components: [
                    {
                        ...order.result!.components[0],
                        value: '10.2',
                        interpretation: 'ABNORMAL',
                    },
                ],
            },
            actions: {
                ...order.actions,
                save_result_url: null,
                verify_result_url: null,
                amend_result_url:
                    '/laboratory/orders/order-01/results/amendments',
            },
        };
        const { container } = render(
            <LaboratoryWorklist {...worklistProps} orders={[amendableOrder]} />,
        );

        await user.click(screen.getByRole('button', { name: 'Add addendum' }));
        await user.selectOptions(
            screen.getByLabelText('Correction reason'),
            'CORRECTION',
        );
        await user.selectOptions(
            screen.getByLabelText('Interpretasi manual'),
            'CRITICAL',
        );
        expect(
            screen.getByText(/Every addendum with a critical value/i),
        ).toBeInTheDocument();
        await user.type(
            screen.getByLabelText('Addendum communication time'),
            '2026-09-01T09:20',
        );
        await user.selectOptions(
            screen.getByLabelText('Addendum communication method'),
            'TELEPHONE',
        );
        await user.selectOptions(
            screen.getByLabelText('Addendum communication outcome'),
            'COMMUNICATED',
        );
        await user.type(
            screen.getByLabelText('Addendum communication notes (optional)'),
            'Read-back terkonfirmasi.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Save verified addendum',
            }),
        );

        expect(inertia.post).toHaveBeenLastCalledWith(
            '/laboratory/orders/order-01/results/amendments',
            expect.any(Object),
            expect.objectContaining({
                errorBag: 'laboratoryAmendment.order-01',
            }),
        );
        const payload = inertia.post.mock.calls.at(-1)?.[1] as {
            critical_communication: Record<string, string>;
        };
        expect(payload.critical_communication).toEqual({
            communicated_at: '2026-09-01T09:20',
            communication_method: 'TELEPHONE',
            recipient_user_public_id: 'physician-01',
            outcome: 'COMMUNICATED',
            note: 'Read-back terkonfirmasi.',
        });
        await expectAccessible(container);
    });

    it('requires a fresh exact-version handoff for a CRITICAL to changed-CRITICAL amendment', async () => {
        const user = userEvent.setup();
        const criticalOrder: LaboratoryOrderProjection = {
            ...order,
            state: 'REPORTED_VERIFIED',
            result: {
                ...order.result!,
                version: 3,
                state: 'VERIFIED',
                verifier_name: 'Sari, Verifikator',
                verified_at: '2026-09-01T08:45:00+07:00',
                critical_communication: {
                    communicated_at: '2026-09-01T08:44:00+07:00',
                    method_label: 'Telepon',
                    recipient_physician_name: 'dr. Ratna',
                    outcome_label: 'Tersampaikan',
                    note: 'Komunikasi versi sebelumnya.',
                },
                components: [
                    {
                        ...order.result!.components[0],
                        value: '5.1',
                        interpretation: 'CRITICAL',
                    },
                ],
            },
            actions: {
                ...order.actions,
                save_result_url: null,
                verify_result_url: null,
                amend_result_url:
                    '/laboratory/orders/order-01/results/amendments',
            },
        };
        const { container } = render(
            <LaboratoryWorklist {...worklistProps} orders={[criticalOrder]} />,
        );

        await user.click(screen.getByRole('button', { name: 'Add addendum' }));
        expect(
            screen.getByText(/Every addendum with a critical value/i),
        ).toBeInTheDocument();
        expect(
            screen.getByLabelText('Addendum communication time'),
        ).toHaveValue('');
        expect(
            screen.getByLabelText('Addendum communication notes (optional)'),
        ).toHaveValue('');
        await user.selectOptions(
            screen.getByLabelText('Correction reason'),
            'CORRECTION',
        );
        await user.clear(screen.getByLabelText('Result value (g/dL)'));
        await user.type(screen.getByLabelText('Result value (g/dL)'), '4.9');
        await user.type(
            screen.getByLabelText('Addendum communication time'),
            '2026-09-01T09:30',
        );
        await user.selectOptions(
            screen.getByLabelText('Addendum communication method'),
            'TELEPHONE',
        );
        await user.selectOptions(
            screen.getByLabelText('Addendum communication outcome'),
            'COMMUNICATED',
        );
        await user.type(
            screen.getByLabelText('Addendum communication notes (optional)'),
            'Nilai kritis versi baru dibacakan ulang.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Save verified addendum',
            }),
        );

        const payload = inertia.post.mock.calls.at(-1)?.[1] as {
            expected_result_version: number;
            critical_communication: Record<string, string>;
        };
        expect(payload.expected_result_version).toBe(3);
        expect(payload.critical_communication).toEqual({
            communicated_at: '2026-09-01T09:30',
            communication_method: 'TELEPHONE',
            recipient_user_public_id: 'physician-01',
            outcome: 'COMMUNICATED',
            note: 'Nilai kritis versi baru dibacakan ulang.',
        });
        await expectAccessible(container);
    });

    it('manages versioned component definitions with accessible 44px actions', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <LaboratoryMasterPanel {...masterProps} />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Add examination' }),
        );
        await user.click(screen.getByRole('button', { name: 'Add component' }));
        expect(
            screen.getByText('Result components (2/12)'),
        ).toBeInTheDocument();
        expect(
            screen
                .getAllByRole('button')
                .every((button) => button.className.includes('min-h-11')),
        ).toBe(true);
        await expectAccessible(container);
    });

    it('focuses the error summary after a failed operation', () => {
        inertia.errors = { reason_code: 'Alasan wajib dipilih.' };
        render(<LaboratoryWorklist {...worklistProps} />);

        expect(screen.getByRole('alert')).toHaveFocus();
    });
});
