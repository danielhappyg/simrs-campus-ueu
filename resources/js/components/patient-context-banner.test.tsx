import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { PatientContextBanner } from '@/components/patient-context-banner';
import type { EncounterContext, PatientContext } from '@/types';

const patient: PatientContext = {
    publicId: '01J00000000000000000000000',
    fullName: 'Pasien Sintetis Arunika',
    birthDate: '1998-04-17',
    administrativeSex: 'Perempuan',
    mrn: 'RM-SIM-000001',
    recordStatus: 'ACTIVE',
    synthetic: true,
    allergyStatus: 'Belum dikaji',
};

const encounter: EncounterContext = {
    publicId: '01J00000000000000000000001',
    number: 'ENC-SIM-000001',
    status: { code: 'ARRIVED', label: 'Sudah hadir' },
    serviceType: 'Rawat jalan',
    location: 'Poliklinik Kampus UEU',
    periodStart: '2026-07-15T08:00:00+07:00',
    environmentMode: 'SIMULATION',
};

describe('PatientContextBanner', () => {
    it('keeps synthetic, identity, allergy, encounter, and acting-role context visible', async () => {
        const { container } = render(
            <PatientContextBanner
                patient={patient}
                encounter={encounter}
                actingAs="Mahasiswa Keperawatan"
            />,
        );

        expect(
            screen.getByRole('region', {
                name: 'Konteks pasien dan encounter aktif',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('SIMULASI — DATA SINTETIS'),
        ).toBeInTheDocument();
        expect(screen.getByText(patient.fullName)).toBeInTheDocument();
        expect(screen.getByText(`MRN ${patient.mrn}`)).toBeInTheDocument();
        expect(screen.getByText('17 Apr 1998')).toBeInTheDocument();
        expect(screen.getByText('Belum dikaji')).toBeInTheDocument();
        expect(
            screen.getByText('Tidak boleh dianggap “tidak ada alergi”.'),
        ).toBeInTheDocument();
        expect(screen.getByText(encounter.number)).toBeInTheDocument();
        expect(screen.getByText(encounter.location)).toBeInTheDocument();
        expect(screen.getByText('Mahasiswa Keperawatan')).toBeInTheDocument();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });
});
