import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import { afterEach, describe, expect, it, vi } from 'vitest';
import VisitTerminationDialog from '@/components/visit-termination-dialog';
import type { RegistrationAppointment } from '@/types';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: inertia.post },
}));

const appointment: RegistrationAppointment = {
    publicId: '01J00000000000000000000003',
    appointmentCode: 'APT-SIM-000001',
    scheduledAt: '2026-07-16T08:00:00+07:00',
    visitReason: 'Evaluasi keluhan sintetis.',
    status: { code: 'BOOKED', label: 'Terjadwal' },
    canCheckIn: true,
    checkInUrl: '/appointments/example/check-in',
    termination: {
        url: '/appointments/example/termination',
        canCancel: true,
        canMarkNoShow: true,
    },
    patient: {
        publicId: '01J00000000000000000000004',
        fullName: 'Pasien Sintetis Arunika',
        birthDate: '1992-04-18',
        mrn: 'MR-SIM-000001',
        synthetic: true,
    },
    encounter: {
        publicId: '01J00000000000000000000005',
        number: 'ENC-SIM-000001',
        status: { code: 'PLANNED', label: 'Terencana' },
        location: 'Poliklinik Umum Simulasi UEU',
        url: '/encounters/example',
    },
};

afterEach(() => vi.clearAllMocks());

describe('visit termination dialog', () => {
    it('submits the server-allowed outcome and attributed reason', async () => {
        const user = userEvent.setup();
        render(<VisitTerminationDialog appointment={appointment} />);

        await user.click(
            screen.getByRole('button', { name: 'Akhiri kunjungan' }),
        );
        await user.selectOptions(
            screen.getByLabelText('Outcome kunjungan'),
            'NO_SHOW',
        );
        await user.type(
            screen.getByLabelText('Alasan terminasi'),
            'Pasien sintetis tidak hadir pada jadwal latihan.',
        );
        await user.click(
            screen.getByRole('button', { name: 'Konfirmasi terminasi' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/appointments/example/termination',
            {
                outcome: 'NO_SHOW',
                reason: 'Pasien sintetis tidak hadir pada jadwal latihan.',
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('keeps confirmation disabled until the required reason is valid', async () => {
        const user = userEvent.setup();
        render(<VisitTerminationDialog appointment={appointment} />);

        await user.click(
            screen.getByRole('button', { name: 'Akhiri kunjungan' }),
        );
        const confirm = screen.getByRole('button', {
            name: 'Konfirmasi terminasi',
        });
        expect(confirm).toBeDisabled();

        await user.type(screen.getByLabelText('Alasan terminasi'), 'pendek');
        expect(confirm).toBeDisabled();

        await user.type(screen.getByLabelText('Alasan terminasi'), ' sekali');
        expect(confirm).toBeEnabled();
    });

    it('is axe clean when open', async () => {
        const user = userEvent.setup();
        render(<VisitTerminationDialog appointment={appointment} />);
        await user.click(
            screen.getByRole('button', { name: 'Akhiri kunjungan' }),
        );

        expect((await axe.run(document.body)).violations).toHaveLength(0);
    });
});
