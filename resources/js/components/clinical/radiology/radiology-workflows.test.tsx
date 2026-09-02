import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RadiologyEncounterPanel } from './radiology-encounter-panel';
import { RadiologyMasterPanel } from './radiology-master-panel';
import { RadiologyWorklist } from './radiology-worklist';
import type {
    RadiologyEncounterProjection,
    RadiologyMasterProps,
    RadiologyOrderProjection,
    RadiologyWorklistProps,
} from './types';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    errors: {} as Record<string, string>,
    fail: false,
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
            options?: {
                onSuccess?: () => void;
                onError?: (errors: Record<string, string>) => void;
            },
        ) => {
            inertia[method](url, data, options);

            if (inertia.fail) {
                options?.onError?.(inertia.errors);
            } else {
                options?.onSuccess?.();
            }
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

const order: RadiologyOrderProjection = {
    public_id: 'order-01',
    version: 2,
    state: 'REPORTED_VERIFIED',
    ordered_at: '2026-08-31T08:00:00+07:00',
    performed_at: '2026-08-31T09:00:00+07:00',
    ordering_physician_name: 'dr. Ratna',
    performer_name: 'Adi, Radiografer',
    care_setting: 'INPATIENT',
    care_location_label: 'Bangsal Melati',
    encounter_number: 'RI-260831-001',
    encounter_url: '/pemeriksaan/rawat-inap/encounter-01',
    patient: {
        medical_record_number: 'RM-000001',
        display_name: 'Pasien Contoh',
    },
    examination: {
        public_id: 'exam-thorax',
        code: 'RAD-THX',
        display_name: 'Radiografi Thoraks',
        preparation_instruction: 'Lepaskan benda logam pada area dada.',
    },
    clinical_question: 'Evaluasi infiltrat paru.',
    cancellation: null,
    report: {
        public_id: 'report-01',
        version: 3,
        state: 'VERIFIED',
        examination: 'Radiografi thoraks PA.',
        findings: 'Tampak infiltrat basal kanan.',
        impression: 'Pneumonia basal kanan.',
        recommendation: 'Korelasi klinis.',
        radiologist_name: 'dr. Maya, Sp.Rad',
        verified_at: '2026-08-31T10:00:00+07:00',
        amendments: [],
        acknowledgement: {
            public_id: 'ack-old',
            physician_name: 'dr. Ratna',
            acknowledged_at: '2026-08-31T10:10:00+07:00',
            is_current: false,
        },
    },
    actions: {
        cancel_url: null,
        perform_url: null,
        save_report_url: null,
        verify_report_url: null,
        amend_report_url: '/radiology/orders/order-01/amendments',
        acknowledge_url: '/radiology/orders/order-01/acknowledgements',
    },
};

const encounterProjection: RadiologyEncounterProjection = {
    definition_version: 'CROSS_SETTING_RADIOLOGY_ORDER_REPORT_V1',
    encounter: {
        public_id: 'encounter-01',
        care_setting: 'INPATIENT',
        status: 'IN_EXAMINATION',
    },
    examination_options: [order.examination],
    cancellation_reason_options: [
        { value: 'CLINICAL_CHANGE', label: 'Perubahan kebutuhan klinis' },
    ],
    orders: [order],
    permissions: {
        can_order: true,
        can_cancel_own_order: true,
        can_acknowledge_own_order: true,
    },
    commands: { create_order_url: '/encounters/encounter-01/radiology' },
};

const worklistProps: RadiologyWorklistProps = {
    generated_at: '2026-08-31T10:30:00+07:00',
    filters: { q: '', care_setting: '', state: '' },
    filter_options: {
        care_settings: [{ value: 'INPATIENT', label: 'Rawat inap' }],
        states: [{ value: 'ORDERED', label: 'Menunggu pemeriksaan' }],
    },
    orders: [
        {
            ...order,
            state: 'ORDERED',
            version: 1,
            report: null,
            actions: {
                ...order.actions,
                perform_url: '/radiology/orders/order-01/perform',
                amend_report_url: null,
                acknowledge_url: null,
            },
        },
    ],
    permissions: { can_perform: true, can_report: false },
    amendment_reason_options: [
        { value: 'CLARIFICATION', label: 'Klarifikasi' },
    ],
};

const masterProps: RadiologyMasterProps = {
    examinations: [
        {
            public_id: 'exam-thorax',
            code: 'RAD-THX',
            display_name: 'Radiografi Thoraks',
            preparation_instruction: 'Lepaskan benda logam.',
            state: 'ACTIVE',
            version: 2,
            actions: {
                update_url: '/radiology/masters/exam-thorax',
                retire_url: '/radiology/masters/exam-thorax/retire',
            },
        },
    ],
    permissions: { can_manage: true },
    commands: { create_url: '/radiology/masters' },
};

async function expectAccessible(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a'] },
        rules: { 'color-contrast': { enabled: false } },
    });
    expect(result.violations).toHaveLength(0);
}

describe('radiology frontend contracts', () => {
    beforeEach(() => {
        inertia.get.mockReset();
        inertia.post.mockReset();
        inertia.patch.mockReset();
        inertia.errors = {};
        inertia.fail = false;
    });

    it('orders from an encounter, exposes stale acknowledgement, and keeps a retry key stable', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <RadiologyEncounterPanel projection={encounterProjection} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Radiologi' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('list', { name: 'Alur hasil radiologi' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/tidak current karena ada adendum baru/i),
        ).toBeInTheDocument();

        await user.selectOptions(
            screen.getByLabelText('Pemeriksaan'),
            'exam-thorax',
        );
        await user.type(
            screen.getByLabelText('Pertanyaan klinis'),
            'Evaluasi efusi.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan permintaan' }),
        );

        const firstPayload = inertia.post.mock.calls.at(-1)?.[1] as {
            idempotency_key: string;
        };
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/encounters/encounter-01/radiology',
            expect.objectContaining({
                examination_public_id: 'exam-thorax',
                clinical_question: 'Evaluasi efusi.',
            }),
            expect.objectContaining({ errorBag: 'radiologyOrder' }),
        );

        await user.click(
            screen.getByRole('button', { name: 'Simpan permintaan' }),
        );
        expect(
            (inertia.post.mock.calls.at(-1)?.[1] as { idempotency_key: string })
                .idempotency_key,
        ).toBe(firstPayload.idempotency_key);
        expect(
            screen
                .getAllByRole('button')
                .every((button) => button.className.includes('min-h-11')),
        ).toBe(true);
        await expectAccessible(container);
    });

    it('uses the supplied setting-correct encounter link and performs an ordered study', async () => {
        const user = userEvent.setup();
        const { container } = render(<RadiologyWorklist {...worklistProps} />);

        expect(
            screen.getByRole('link', { name: /Buka episode/i }),
        ).toHaveAttribute('href', '/pemeriksaan/rawat-inap/encounter-01');
        expect(screen.getByRole('link', { name: 'Radiologi' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('link', { name: 'Laboratorium' }),
        ).toHaveAttribute('href', '/pemeriksaan/laboratorium');
        await user.click(
            screen.getByRole('button', {
                name: /Catat pemeriksaan selesai/i,
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/orders/order-01/perform',
            expect.objectContaining({ expected_version: 1 }),
            expect.objectContaining({
                errorBag: 'radiologyPerform.order-01',
            }),
        );
        expect(
            screen.queryByText(/PACS|DICOM|tagihan|klaim|farmasi/i),
        ).not.toBeInTheDocument();
        await expectAccessible(container);
    });

    it('saves and verifies a Draft report, then appends a verified amendment', async () => {
        const user = userEvent.setup();
        const draftOrder: RadiologyOrderProjection = {
            ...order,
            state: 'PERFORMED',
            report: {
                ...order.report!,
                state: 'DRAFT',
                version: 1,
                verified_at: null,
                acknowledgement: null,
            },
            actions: {
                ...order.actions,
                save_report_url: '/radiology/orders/order-01/report',
                verify_report_url: '/radiology/orders/order-01/report/verify',
                amend_report_url: null,
                acknowledge_url: null,
            },
        };
        const draftView = render(
            <RadiologyWorklist
                {...worklistProps}
                orders={[draftOrder]}
                permissions={{ can_perform: false, can_report: true }}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /Susun laporan/i }),
        );
        await user.clear(screen.getByLabelText('Temuan'));
        await user.type(screen.getByLabelText('Temuan'), 'Temuan terbaru.');
        await user.click(screen.getByRole('button', { name: 'Simpan Draft' }));
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/orders/order-01/report',
            expect.objectContaining({
                expected_version: 1,
                fields: {
                    findings: 'Temuan terbaru.',
                    impression: 'Pneumonia basal kanan.',
                    recommendation: 'Korelasi klinis.',
                },
            }),
            expect.objectContaining({ errorBag: 'radiologyReport.order-01' }),
        );
        expect(
            screen.queryByRole('textbox', { name: 'Pemeriksaan' }),
        ).not.toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Verifikasi laporan' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/orders/order-01/report/verify',
            expect.objectContaining({ expected_version: 1 }),
            expect.objectContaining({ errorBag: 'radiologyVerify.order-01' }),
        );
        draftView.unmount();

        render(
            <RadiologyWorklist
                {...worklistProps}
                orders={[order]}
                permissions={{ can_perform: false, can_report: true }}
            />,
        );
        await user.click(
            screen.getByRole('button', { name: 'Tambah adendum' }),
        );
        await user.selectOptions(
            screen.getByLabelText('Alasan adendum'),
            'CLARIFICATION',
        );
        await user.type(
            screen.getByLabelText('Pernyataan koreksi'),
            'Klarifikasi lokasi temuan.',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Simpan adendum terverifikasi',
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/orders/order-01/amendments',
            expect.objectContaining({
                expected_report_version: 3,
                reason: 'CLARIFICATION',
                amended_statement: 'Klarifikasi lokasi temuan.',
            }),
            expect.objectContaining({
                errorBag: 'radiologyAmendment.order-01',
            }),
        );
    });

    it('creates, updates, and retires a managed examination only when URLs are present', async () => {
        const user = userEvent.setup();
        const { container } = render(<RadiologyMasterPanel {...masterProps} />);

        expect(
            screen.getByRole('link', { name: 'Pemeriksaan Radiologi' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            screen.getByRole('link', { name: 'Bangsal & Tempat Tidur' }),
        ).toHaveAttribute('href', '/manajemen-data/bangsal');

        await user.click(
            screen.getByRole('button', { name: 'Tambah pemeriksaan' }),
        );
        await user.type(screen.getByLabelText('Kode permanen'), 'rad-usg');
        await user.type(
            screen.getByLabelText('Nama pemeriksaan'),
            'USG Abdomen',
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan pemeriksaan' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/masters',
            expect.objectContaining({
                code: 'RAD-USG',
                display_name: 'USG Abdomen',
            }),
            expect.objectContaining({ errorBag: 'radiologyMasterCreate' }),
        );

        await user.click(screen.getByRole('button', { name: 'Ubah' }));
        const nameFields = screen.getAllByLabelText('Nama pemeriksaan');
        const updateRegion = nameFields.at(-1)?.closest('form');
        expect(updateRegion).not.toBeNull();
        await user.clear(
            within(updateRegion!).getByLabelText('Nama pemeriksaan'),
        );
        await user.type(
            within(updateRegion!).getByLabelText('Nama pemeriksaan'),
            'Foto Thoraks',
        );
        await user.click(
            within(updateRegion!).getByRole('button', {
                name: 'Simpan perubahan',
            }),
        );
        expect(inertia.patch).toHaveBeenCalledWith(
            '/radiology/masters/exam-thorax',
            expect.objectContaining({
                expected_version: 2,
                display_name: 'Foto Thoraks',
            }),
            expect.objectContaining({
                errorBag: 'radiologyMasterUpdate.exam-thorax',
            }),
        );

        await user.click(screen.getByRole('button', { name: 'Nonaktifkan' }));
        await user.click(
            screen.getByRole('button', { name: 'Ya, nonaktifkan' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/radiology/masters/exam-thorax/retire',
            expect.objectContaining({ expected_version: 2 }),
            expect.objectContaining({
                errorBag: 'radiologyMasterRetire.exam-thorax',
            }),
        );
        await expectAccessible(container);
    });

    it('does not render mutation controls when permissions or URLs are absent', () => {
        render(
            <RadiologyEncounterPanel
                projection={{
                    ...encounterProjection,
                    permissions: {
                        can_order: false,
                        can_cancel_own_order: false,
                        can_acknowledge_own_order: false,
                    },
                    commands: { create_order_url: null },
                    orders: [
                        {
                            ...order,
                            actions: {
                                cancel_url: null,
                                perform_url: null,
                                save_report_url: null,
                                verify_report_url: null,
                                amend_report_url: null,
                                acknowledge_url: null,
                            },
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.queryByRole('button', { name: 'Simpan permintaan' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Tandai sudah diketahui' }),
        ).not.toBeInTheDocument();
    });
});
