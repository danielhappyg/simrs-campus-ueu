import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { DispenseForm, FinalCheckForm } from '@/pages/clinical/pharmacy';
import type {
    PharmacyMedicationRequestRecord,
    PharmacyWorkspaceProps,
} from '@/types';

const medicationRequest = {
    publicId: '01TESTMEDICATIONREQUEST0001',
    authoredMedication: 'Parasetamol',
    quantityValue: '9.000',
    quantityUnit: 'tablet',
    dispenseAction: {
        allowed: true,
        requestKey: '01TESTDISPENSEREQUEST00001',
        url: '/dispenses',
    },
    dispensePreparations: [],
} as unknown as PharmacyMedicationRequestRecord;

const outcomes: PharmacyWorkspaceProps['formOptions']['dispenseOutcomes'] = [
    { code: 'COMPLETE', label: 'Diserahkan lengkap' },
    { code: 'PARTIAL', label: 'Diserahkan sebagian' },
    { code: 'NOT_DISPENSED', label: 'Tidak diserahkan' },
];

describe('DispenseForm', () => {
    it('defaults safely when no matching synthetic stock exists', async () => {
        const { container } = render(
            <DispenseForm
                medicationRequest={medicationRequest}
                stocks={[]}
                outcomes={outcomes}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Parasetamol');
        expect(screen.getByRole('alert')).toHaveTextContent(
            'sistem tidak melakukan substitusi otomatis',
        );
        expect(screen.getByRole('combobox', { name: 'Outcome' })).toHaveValue(
            'NOT_DISPENSED',
        );
        expect(
            screen.getByRole('spinbutton', { name: 'Jumlah (tablet)' }),
        ).toHaveValue(0);
        expect(
            screen.queryByRole('combobox', {
                name: 'Lot stok sintetis · FEFO',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Pemeriksaan akhir supervisor'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Ajukan penyiapan ke supervisor',
            }),
        ).toBeInTheDocument();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });

    it('names available session lots when the prescription does not match stock', () => {
        render(
            <DispenseForm
                medicationRequest={medicationRequest}
                stocks={[
                    {
                        id: 11,
                        publicId: '01TESTSTOCK00000000000001',
                        authoredMedication: 'Obat Simulasi A',
                        form: 'Tablet',
                        strength: '500 mg',
                        lotNumber: 'LOT-SIM-A-001',
                        expiresOn: '2027-01-01',
                        quantityOnHand: '30.000',
                        unit: 'tablet',
                        synthetic: true,
                    },
                ]}
                outcomes={outcomes}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Parasetamol');
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Obat Simulasi A (tablet)',
        );
        expect(screen.getByRole('combobox', { name: 'Outcome' })).toHaveValue(
            'NOT_DISPENSED',
        );
    });

    it('gives the linked supervisor separate approve and change controls', async () => {
        const supervisorRequest = {
            ...medicationRequest,
            finalCheckAction: {
                allowed: true,
                requestKey: '01TESTFINALCHECKREQUEST01',
                preparationPublicId: '01TESTPREPARATION0000001',
                url: '/dispenses',
            },
        } as PharmacyMedicationRequestRecord;
        const { container } = render(
            <FinalCheckForm medicationRequest={supervisorRequest} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Pemeriksaan akhir supervisor',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Minta perbaikan' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Setujui pemeriksaan akhir',
            }),
        ).toBeInTheDocument();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });
});
