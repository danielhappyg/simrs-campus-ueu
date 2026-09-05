import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PharmacyMasterPanel } from './pharmacy-master-panel';
import { PharmacyPrescriptionWorkflow } from './pharmacy-prescription-workflow';
import { PharmacyStockCard } from './pharmacy-stock-card';
import { PharmacyWorklist } from './pharmacy-worklist';
import type {
    PharmacyMasterProps,
    PharmacyPrescriptionProjection,
    PharmacyStockCardProps,
    PharmacyWorklistProps,
} from './types';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
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
        const [transformer, setTransformer] = useState<(value: T) => T>(
            () => (value: T) => value,
        );

        return {
            data,
            errors: inertia.errors,
            processing: false,
            setData: (fieldOrData: keyof T | T, value?: T[keyof T]) => {
                if (typeof fieldOrData === 'object') {
                    setDataState(fieldOrData);
                } else {
                    setDataState((current) => ({
                        ...current,
                        [fieldOrData]: value,
                    }));
                }
            },
            transform: (callback: (value: T) => T) =>
                setTransformer(() => callback),
            post: (url: string, options?: { onSuccess?: () => void }) => {
                inertia.post(url, transformer(data), options);
                options?.onSuccess?.();
            },
        };
    },
}));

const medicine = {
    public_id: 'medicine-01',
    code: 'MED-001',
    generic_display_name: 'Parasetamol',
    brand_display_name: null,
    strength_text: '500 mg',
    dosage_form: 'Tablet',
    base_issue_unit: 'tablet',
    route_choices: ['ORAL'],
    state: 'ACTIVE' as const,
    version: 2,
};

const prescription: PharmacyPrescriptionProjection = {
    public_id: 'rx-01',
    version: 2,
    state: 'ORDERED',
    fingerprint: 'rx-fingerprint',
    encounter: {
        public_id: 'encounter-01',
        care_setting: 'OUTPATIENT',
        status: 'IN_EXAMINATION',
        location_label: 'Poli Umum',
        registered_at: '2026-09-01T08:00:00+07:00',
        patient: {
            public_id: 'patient-01',
            medical_record_number: 'RM-000001',
            full_name: 'Pasien Contoh',
            date_of_birth: '1990-01-01',
            sex: 'F',
        },
    },
    depot: {
        public_id: 'depot-01',
        code: 'DEP-RJ',
        display_name: 'Depo Rawat Jalan',
        eligible_care_settings: ['OUTPATIENT'],
        state: 'ACTIVE',
        version: 1,
    },
    ordering_physician: { public_id: 'physician-01', name: 'dr. Ratna' },
    clinical_note: 'Nyeri dan demam dua hari.',
    items: [
        {
            public_id: 'item-01',
            medicine,
            dose_text: '500 mg',
            route: 'ORAL',
            frequency_text: '3 kali sehari',
            duration_text: '3 hari',
            requested_quantity: 9,
            verified_quantity: null,
            handed_over_quantity: 0,
            returned_quantity: 0,
            remaining_quantity: 9,
            clinical_instruction: 'Sesudah makan.',
        },
    ],
    verification: null,
    preparation: null,
    handovers: [],
    returns: [],
    history: [
        {
            public_id: 'event-01',
            state: 'ORDERED',
            version: 2,
            actor_name: 'dr. Ratna',
            reason: null,
            occurred_at: '2026-09-01T08:05:00+07:00',
            fingerprint: 'event-fingerprint',
        },
    ],
    control_totals: {
        ordered_quantity: 9,
        verified_quantity: 0,
        handed_over_quantity: 0,
        returned_to_stock_quantity: 0,
        quarantined_return_quantity: 0,
        non_returnable_quantity: 0,
        gross_charge_source_rupiah: 0,
        reversed_charge_source_rupiah: 0,
        net_charge_source_rupiah: 0,
    },
    actions: {
        save_draft_url: null,
        order_url: null,
        replace_url: null,
        cancel_url: null,
        verify_url: '/apotek/resep/rx-01/verifikasi',
        refuse_url: '/apotek/resep/rx-01/tolak',
        prepare_url: null,
        handover_url: null,
        close_unfilled_url: null,
        return_url: null,
    },
};

const permissions: PharmacyWorklistProps['permissions'] = {
    can_verify: true,
    can_prepare: false,
    can_handover: false,
    can_return: false,
};

async function expectAccessible(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a'] },
    });
    expect(result.violations).toHaveLength(0);
}

function expectFortyFourPixelTargets(container: HTMLElement) {
    const targets = container.querySelectorAll<HTMLElement>(
        'button, a[href], input, select, textarea, summary',
    );

    expect(targets.length).toBeGreaterThan(0);

    for (const target of targets) {
        const touchClass =
            target instanceof HTMLInputElement && target.type === 'checkbox'
                ? target.closest('label')?.className
                : target.className;

        expect(touchClass, target.outerHTML).toMatch(/min-h-(?:11|20)/);
    }
}

describe('pharmacy frontend contracts', () => {
    beforeEach(() => {
        inertia.get.mockReset();
        inertia.post.mockReset();
        inertia.errors = {};
    });

    it('submits the exact manual verification checklist and allergy vocabulary', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <PharmacyPrescriptionWorkflow
                prescription={prescription}
                permissions={permissions}
            />,
        );

        await user.selectOptions(
            screen.getByLabelText('Manual allergy-review result'),
            'REVIEWED_NO_CONFLICT',
        );
        await user.click(screen.getByText('Patient identity confirmed'));
        await user.click(screen.getByText('Care context confirmed'));
        await user.click(screen.getByText('Medication is legible'));
        await user.click(screen.getByText('Directions are legible'));
        await user.click(
            screen.getByRole('button', { name: 'Save verification' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/apotek/resep/rx-01/verifikasi',
            expect.objectContaining({
                manual_allergy_review: 'REVIEWED_NO_CONFLICT',
                checklist: {
                    identity_confirmed: true,
                    context_confirmed: true,
                    medicine_readable: true,
                    instruction_readable: true,
                },
                item_decisions: [
                    {
                        item_public_id: 'item-01',
                        verified_quantity: 9,
                        reason_code: '',
                    },
                ],
            }),
            expect.objectContaining({ errorBag: 'pharmacyVerification.rx-01' }),
        );
        expect(
            screen
                .getAllByRole('button')
                .every((button) => button.className.includes('min-h-11')),
        ).toBe(true);
        expect(screen.getAllByRole('columnheader').length).toBeGreaterThan(2);
        expect(
            screen
                .getAllByRole('columnheader')
                .every((header) => header.getAttribute('scope') === 'col'),
        ).toBe(true);
        expect(
            screen.getByText('Medication list for prescription rx-01'),
        ).toHaveClass('sr-only');
        await expectAccessible(container);
    });

    it('renders a keyboard-readable cross-setting queue with text status', async () => {
        const user = userEvent.setup();
        const props: PharmacyWorklistProps = {
            generated_at: '2026-09-01T09:00:00+07:00',
            prescriptions: [prescription],
            filters: { q: '', care_setting: '', state: '', depot: '' },
            filter_options: {
                care_settings: [{ value: 'OUTPATIENT', label: 'Rawat jalan' }],
                states: [{ value: 'ORDERED', label: 'Menunggu verifikasi' }],
                depots: [{ value: 'depot-01', label: 'Depo Rawat Jalan' }],
            },
            permissions,
        };
        const { container } = render(<PharmacyWorklist {...props} />);

        expect(
            screen.getByRole('heading', {
                name: 'Queue, verification & dispensing',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('Awaiting verification').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getByRole('link', {
                name: 'Open prescription Pasien Contoh',
            }),
        ).toHaveAttribute('href', '/apotek/resep/rx-01');
        await user.tab();
        expect(
            screen.getByRole('link', { name: 'Prescription queue' }),
        ).toHaveFocus();
        await user.tab();
        expect(
            screen.getByRole('link', { name: 'Prescription history' }),
        ).toHaveFocus();
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('focuses the operation error summary', () => {
        inertia.errors = {
            manual_allergy_review: 'Peninjauan alergi wajib dipilih.',
        };
        render(
            <PharmacyPrescriptionWorkflow
                prescription={prescription}
                permissions={permissions}
            />,
        );
        expect(screen.getByRole('alert')).toHaveFocus();
    });

    it('preserves explicit zero quantities for partial FEFO preparation', async () => {
        const user = userEvent.setup();
        const verified: PharmacyPrescriptionProjection = {
            ...prescription,
            state: 'VERIFIED',
            actions: {
                ...prescription.actions,
                verify_url: null,
                prepare_url: '/apotek/resep/rx-01/siapkan',
            },
        };
        const { container } = render(
            <PharmacyPrescriptionWorkflow
                prescription={verified}
                permissions={{
                    ...permissions,
                    can_verify: false,
                    can_prepare: true,
                }}
            />,
        );

        await user.clear(screen.getByLabelText('Quantity'));
        await user.type(screen.getByLabelText('Quantity'), '0');
        await user.click(
            screen.getByRole('button', { name: 'Prepare with FEFO' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/apotek/resep/rx-01/siapkan',
            expect.objectContaining({ item_quantities: { 'item-01': 0 } }),
            expect.objectContaining({ errorBag: 'pharmacyPreparation.rx-01' }),
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('requires deliberate handover confirmation and submits a partial reason', async () => {
        const user = userEvent.setup();
        const prepared: PharmacyPrescriptionProjection = {
            ...prescription,
            state: 'PREPARED',
            preparation: {
                public_id: 'prep-01',
                version: 1,
                technician_name: 'Teknisi Farmasi',
                note: null,
                allocations: [
                    {
                        public_id: 'allocation-01',
                        prescription_item_public_id: 'item-01',
                        medicine_label: 'Parasetamol 500 mg',
                        lot_public_id: 'lot-01',
                        lot_code: 'LOT-01',
                        expiry_date: '2027-01-01',
                        no_expiry_reason: null,
                        quantity: 4,
                    },
                ],
                prepared_at: '2026-09-01T09:00:00+07:00',
                fingerprint: 'prep-fingerprint',
            },
            actions: {
                ...prescription.actions,
                verify_url: null,
                handover_url: '/apotek/penyiapan/prep-01/serahkan',
            },
        };
        const { container } = render(
            <PharmacyPrescriptionWorkflow
                prescription={prepared}
                permissions={{
                    ...permissions,
                    can_verify: false,
                    can_handover: true,
                }}
            />,
        );

        const submit = screen.getByRole('button', {
            name: 'Hand over medication',
        });
        expect(submit).toBeDisabled();
        await user.type(
            screen.getByLabelText('Reason for partial handover'),
            'Stok belum mencukupi',
        );
        await user.click(screen.getByText('I confirm the final handover'));
        await user.click(submit);

        expect(inertia.post).toHaveBeenCalledWith(
            '/apotek/penyiapan/prep-01/serahkan',
            expect.objectContaining({
                expected_preparation_fingerprint: 'prep-fingerprint',
                partial_reason: 'Stok belum mencukupi',
                confirm_handover: true,
            }),
            expect.objectContaining({ errorBag: 'pharmacyHandover.rx-01' }),
        );
        expect(
            screen.getByText('Medication-lot allocations to hand over'),
        ).toHaveClass('sr-only');
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('supports terminal remainder closure and confirmed condition-specific returns', async () => {
        const user = userEvent.setup();
        const partiallyHandedOver: PharmacyPrescriptionProjection = {
            ...prescription,
            state: 'PARTIALLY_HANDED_OVER',
            handovers: [
                {
                    public_id: 'handover-01',
                    sequence: 1,
                    pharmacist_name: 'Apt. Sari',
                    unfilled_quantity: 5,
                    partial_reason: 'Stok terbatas',
                    items: [
                        {
                            public_id: 'handover-item-01',
                            prescription_item_public_id: 'item-01',
                            medicine_label: 'Parasetamol 500 mg',
                            lot_code: 'LOT-01',
                            quantity: 4,
                            returnable_quantity: 2,
                        },
                    ],
                    handed_over_at: '2026-09-01T09:10:00+07:00',
                    fingerprint: 'handover-fingerprint',
                },
            ],
            actions: {
                ...prescription.actions,
                verify_url: null,
                close_unfilled_url: '/apotek/resep/rx-01/tutup-sisa',
                return_url: '/apotek/resep/rx-01/retur',
            },
        };
        const { container } = render(
            <PharmacyPrescriptionWorkflow
                prescription={partiallyHandedOver}
                permissions={{
                    ...permissions,
                    can_verify: false,
                    can_handover: true,
                    can_return: true,
                }}
            />,
        );

        await user.selectOptions(
            screen.getByLabelText('Closure reason'),
            'STOCK_UNAVAILABLE',
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Close prescription remainder',
            }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/apotek/resep/rx-01/tutup-sisa',
            expect.objectContaining({ reason_code: 'STOCK_UNAVAILABLE' }),
            expect.objectContaining({
                errorBag: 'pharmacyCloseUnfilled.rx-01',
            }),
        );

        await user.click(screen.getByRole('button', { name: 'Record return' }));
        const returnSubmit = screen.getByRole('button', {
            name: 'Save return',
        });
        expect(returnSubmit).toBeDisabled();
        await user.clear(
            screen.getByLabelText('Quantity', {
                selector: '#return-quantity-handover-item-01',
            }),
        );
        await user.type(
            screen.getByLabelText('Quantity', {
                selector: '#return-quantity-handover-item-01',
            }),
            '1',
        );
        await user.selectOptions(
            screen.getByLabelText('Condition'),
            'QUARANTINE',
        );
        await user.selectOptions(
            screen.getByLabelText('Return reason'),
            'DAMAGED_PACKAGE',
        );
        await user.click(
            screen.getByText('I confirm the return quantity and condition'),
        );
        await user.click(returnSubmit);
        expect(inertia.post).toHaveBeenLastCalledWith(
            '/apotek/resep/rx-01/retur',
            expect.objectContaining({
                handover_public_id: 'handover-01',
                confirm_return: true,
                items: [
                    {
                        handover_item_public_id: 'handover-item-01',
                        condition: 'QUARANTINE',
                        quantity: 1,
                    },
                ],
            }),
            expect.objectContaining({ errorBag: 'pharmacyReturn.rx-01' }),
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('maps master and opening-lot fields to the backend vocabulary', async () => {
        const user = userEvent.setup();
        const masterProps: PharmacyMasterProps = {
            medicines: [
                {
                    ...medicine,
                    standard_acquisition_value_rupiah: 400,
                    teaching_sale_value_rupiah: 1000,
                    actions: {
                        update_url: '/obat/medicine-01',
                        retire_url: '/obat/medicine-01/pensiun',
                    },
                },
            ],
            depots: [
                {
                    public_id: 'depot-01',
                    code: 'DEP-RJ',
                    display_name: 'Depo Rawat Jalan',
                    eligible_care_settings: ['OUTPATIENT'],
                    state: 'ACTIVE',
                    version: 1,
                    actions: {
                        update_url: '/depo/depot-01',
                        retire_url: '/depo/depot-01/pensiun',
                    },
                },
            ],
            lots: [],
            route_options: [{ value: 'ORAL', label: 'Oral' }],
            dosage_form_options: [{ value: 'TABLET', label: 'Tablet' }],
            care_setting_options: [
                { value: 'OUTPATIENT', label: 'Rawat jalan' },
            ],
            permissions: {
                can_manage_medicines: true,
                can_manage_depots: true,
                can_manage_inventory: true,
            },
            commands: {
                create_medicine_url: '/obat',
                create_depot_url: '/depo',
                open_lot_url: '/lot',
            },
        };
        const { container } = render(<PharmacyMasterPanel {...masterProps} />);
        await user.click(
            screen.getByRole('button', { name: 'Opening lot balance' }),
        );
        await user.type(screen.getByLabelText('Lot code'), 'LOT-01');
        await user.type(
            screen.getByLabelText('Received date'),
            '2026-09-01T08:30',
        );
        await user.type(screen.getByLabelText('Expiry date'), '2027-09-01');
        await user.clear(screen.getByLabelText('Available quantity'));
        await user.type(screen.getByLabelText('Available quantity'), '12');
        await user.type(screen.getByLabelText('Source reference'), 'PO-001');
        await user.click(
            screen.getByRole('button', { name: 'Save opening balance' }),
        );
        expect(inertia.post).toHaveBeenCalledWith(
            '/lot',
            expect.objectContaining({
                medicine_public_id: 'medicine-01',
                depot_public_id: 'depot-01',
                lot_code: 'LOT-01',
                received_at: '2026-09-01T08:30',
                expiry_date: '2027-09-01',
                opening_quantity: 12,
                source_reference: 'PO-001',
            }),
            expect.objectContaining({ errorBag: 'pharmacyLot.open' }),
        );
        expect(
            screen.getByText('Medication master list and active versions'),
        ).toHaveClass('sr-only');
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('shows stock reconciliation warnings and requires a reason before quarantine', async () => {
        const user = userEvent.setup();
        const props: PharmacyStockCardProps = {
            generated_at: '2026-09-01T10:00:00+07:00',
            filters: { q: '', medicine: '', depot: '', state: '' },
            filter_options: { medicines: [], depots: [], states: [] },
            lots: [
                {
                    public_id: 'lot-01',
                    state: 'ACTIVE',
                    medicine,
                    depot: {
                        public_id: 'depot-01',
                        code: 'DEP-RJ',
                        display_name: 'Depo Rawat Jalan',
                        eligible_care_settings: ['OUTPATIENT'],
                        state: 'ACTIVE',
                        version: 1,
                    },
                    lot_code: 'LOT-01',
                    opened_at: '2026-09-01T08:00:00+07:00',
                    expiry_date: '2027-09-01',
                    no_expiry_reason: null,
                    available_quantity: 12,
                    quarantined_quantity: 0,
                    acquisition_value_rupiah: 400,
                    source_reference: 'PO-001',
                    fingerprint: 'lot-fingerprint',
                    actions: {
                        quarantine_url: '/lot/lot-01/karantina',
                        release_url: null,
                        correct_url: '/lot/lot-01/koreksi',
                    },
                    movements: [],
                    reconciled: false,
                },
            ],
        };
        const { container } = render(<PharmacyStockCard {...props} />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Reconciliation needed: balance and history do not match',
        );
        await user.click(screen.getByText('Stock controls'));
        const quarantine = screen.getByRole('button', {
            name: 'Quarantine lot',
        });
        expect(quarantine).toBeDisabled();
        await user.selectOptions(
            screen.getByLabelText('Reason for correction or status change'),
            'DAMAGED_PACKAGE',
        );
        const enabledQuarantine = screen.getByRole('button', {
            name: 'Quarantine lot',
        });
        expect(enabledQuarantine).toBeEnabled();
        await user.click(enabledQuarantine);
        expect(inertia.post).toHaveBeenCalledWith(
            '/lot/lot-01/karantina',
            expect.objectContaining({
                reason_code: 'DAMAGED_PACKAGE',
            }),
            expect.objectContaining({ errorBag: 'pharmacyStockState.lot-01' }),
        );
        expect(screen.getByRole('status')).toHaveAttribute(
            'aria-live',
            'polite',
        );
        expect(screen.getByText('Stock movement for lot LOT-01')).toHaveClass(
            'sr-only',
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });
});
