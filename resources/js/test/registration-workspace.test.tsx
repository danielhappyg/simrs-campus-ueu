import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import RegistrationWorkspace from '@/pages/patient/registration';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        clearErrors: vi.fn(),
        post: vi.fn(),
    }),
}));

const props = {
    session: {
        publicId: '01J00000000000000000000001',
        code: 'SIM-RJ-UEU-001',
        courseCode: 'SIMRS-RJ',
        scenarioTitle: 'Kunjungan Rawat Jalan Terintegrasi — Data Sintetis',
    },
    assignmentPublicId: '01J00000000000000000000002',
    searchUrl: '/sessions/example/registration',
    storeUrl: '/sessions/example/registrations',
    searchQuery: '',
    candidates: [],
    appointments: [
        {
            publicId: '01J00000000000000000000003',
            appointmentCode: 'APT-SIM-000001',
            scheduledAt: '2026-07-16T08:00:00+07:00',
            visitReason: 'Evaluasi keluhan rawat jalan pada skenario latihan.',
            status: { code: 'BOOKED', label: 'Terjadwal' },
            canCheckIn: true,
            checkInUrl: '/appointments/example/check-in',
            termination: {
                url: '/appointments/example/termination',
                canCancel: true,
                canMarkNoShow: false,
            },
            patient: {
                publicId: '01J00000000000000000000004',
                fullName: 'Pasien Sintetis Arunika',
                birthDate: '1992-04-18',
                administrativeSex: 'Perempuan',
                mrn: 'MR-SIM-000001',
                synthetic: true as const,
            },
            encounter: {
                publicId: '01J00000000000000000000005',
                number: 'ENC-SIM-000001',
                status: { code: 'PLANNED', label: 'Terencana' },
                location: 'Poliklinik Umum Simulasi UEU',
                url: '/encounters/example',
            },
        },
    ],
    canCreateRegistration: false,
    locations: [
        {
            publicId: '01J00000000000000000000006',
            code: 'POLI-UMUM-SIM',
            name: 'Poliklinik Umum Simulasi UEU',
        },
    ],
    registrationKey: '01J00000000000000000000007',
    defaultScheduledAt: '2026-07-16T08:00',
    options: {
        administrativeSex: [
            { value: 'UNKNOWN', label: 'Belum ditentukan' },
            { value: 'FEMALE', label: 'Perempuan' },
        ],
        visitSources: [{ value: 'SCHEDULED', label: 'Terjadwal' }],
    },
};

describe('registration workspace', () => {
    it('keeps check-in actionable while the one-case registration form is disabled', async () => {
        const user = userEvent.setup();
        const { container } = render(<RegistrationWorkspace {...props} />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Pencarian & Registrasi Pasien',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Sesi ini sudah memiliki satu encounter bersama/),
        ).toBeInTheDocument();

        const caseForm = container.querySelector(
            'fieldset[disabled][aria-describedby="case-limit-message"]',
        );
        expect(caseForm).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Simpan janji & encounter terencana',
            }),
        ).toBeDisabled();

        const checkIn = screen.getByRole('button', { name: 'Check-in' });
        expect(checkIn).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Akhiri kunjungan' }),
        ).toBeEnabled();
        await user.click(checkIn);

        expect(inertia.post).toHaveBeenCalledWith(
            '/appointments/example/check-in',
        );
    });

    it('does not expose termination controls for terminal or later clinical states', () => {
        const terminal = {
            ...props.appointments[0],
            publicId: '01J00000000000000000000008',
            status: { code: 'CANCELLED', label: 'Dibatalkan' },
            canCheckIn: false,
            termination: {
                url: '/appointments/terminal/termination',
                canCancel: false,
                canMarkNoShow: false,
            },
            encounter: {
                ...props.appointments[0].encounter,
                publicId: '01J00000000000000000000009',
                status: { code: 'CANCELLED', label: 'Dibatalkan' },
            },
        };
        const laterClinical = {
            ...props.appointments[0],
            publicId: '01J00000000000000000000010',
            status: { code: 'CHECKED_IN', label: 'Sudah check-in' },
            canCheckIn: false,
            termination: {
                url: '/appointments/later/termination',
                canCancel: false,
                canMarkNoShow: false,
            },
            encounter: {
                ...props.appointments[0].encounter,
                publicId: '01J00000000000000000000011',
                status: { code: 'IN_INTAKE', label: 'Asesmen awal' },
            },
        };

        render(
            <RegistrationWorkspace
                {...props}
                appointments={[terminal, laterClinical]}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Akhiri kunjungan' }),
        ).not.toBeInTheDocument();
    });
});
