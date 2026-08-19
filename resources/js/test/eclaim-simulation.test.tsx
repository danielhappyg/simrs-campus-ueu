import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EClaimSimulation from '@/pages/claims/eclaim-simulation';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

const inertiaPost = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        post: inertiaPost,
    },
    usePage: () => ({ props: { errors: {} } }),
}));

const props = {
    boundary: {
        classification: 'SIMULASI — DATA SINTETIS',
        mode: 'SIMULATION_ONLY',
        transportState: 'NOT_SENT',
        externalEndpoint: null,
        outboundEnabled: false as const,
        certifiedGrouper: false as const,
        compatibilityProfile: 'ECLAIM_5_X_LOCAL_WS_EDUCATIONAL',
        observedInstallationVersion: '5.8.8',
    },
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: {
            code: 'FINALIZED',
            label: 'Difinalisasi untuk simulasi',
        },
        serviceType: 'Poliklinik umum',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-21T08:00:00+07:00',
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        birthDate: '1992-04-18',
        administrativeSex: 'Perempuan',
        mrn: 'MR-SIM-000001',
        synthetic: true as const,
        allergyStatus: 'Lihat sumber asesmen pada linimasa',
    },
    assignment: {
        program: 'RMIK',
        role: 'Koder RMIK',
        canAdvance: true,
    },
    claimCase: null,
    snapshot: {
        identifiers: {
            nomorKartu: '9901234567890',
            nomorSep: 'SIM-SEP-01J00000000000',
            nomorRm: 'MR-SIM-000001',
            coderNik: '0000000000000000',
            synthetic: true as const,
        },
        coding: {
            diagnoses: [
                {
                    code: 'R42',
                    display: 'Dizziness and giddiness',
                    assignmentPublicId: '01J00000000000000000000003',
                    contentHash: 'a'.repeat(64),
                },
            ],
            procedures: [
                {
                    code: '38.99',
                    display: 'Other puncture of vein',
                    assignmentPublicId: '01J00000000000000000000004',
                    contentHash: 'b'.repeat(64),
                },
            ],
        },
        billing: {
            currency: 'IDR',
            hospitalTotal: 275000,
            educationalPlaceholder: true as const,
        },
    },
    steps: [
        {
            action: 'CREATE_CLAIM',
            method: 'new_claim',
            label: 'Buat klaim',
            completed: false,
            available: true,
            requestKey: '01J00000000000000000000005',
            actionUrl: '/encounters/example/eclaim-simulation/advance',
        },
        {
            action: 'STAGE_CLAIM_DATA',
            method: 'set_claim_data',
            label: 'Kirim data klaim',
            completed: false,
            available: false,
            requestKey: null,
            actionUrl: '/encounters/example/eclaim-simulation/advance',
        },
        {
            action: 'GROUP_CLAIM',
            method: 'grouper',
            label: 'Jalankan grouper simulasi',
            completed: false,
            available: false,
            requestKey: null,
            actionUrl: '/encounters/example/eclaim-simulation/advance',
        },
        {
            action: 'FINALIZE_CLAIM',
            method: 'claim_final',
            label: 'Finalisasi klaim simulasi',
            completed: false,
            available: false,
            requestKey: null,
            actionUrl: '/encounters/example/eclaim-simulation/advance',
        },
        {
            action: 'SIMULATE_SUBMISSION',
            method: 'SIMULATE_SEND_CLAIM',
            label: 'Simulasikan pengiriman',
            completed: false,
            available: false,
            requestKey: null,
            actionUrl: '/encounters/example/eclaim-simulation/advance',
        },
    ],
    events: [],
    urls: {
        back: '/encounters/example/debrief',
        timeline: '/encounters/example/timeline',
        interoperabilityPreview: '/encounters/example/interoperability-preview',
    },
};

describe('E-Klaim simulation workspace', () => {
    it('makes the never-sent boundary and ordered educational methods explicit', async () => {
        const user = userEvent.setup();
        const { container } = render(<EClaimSimulation {...props} />);

        expect(
            screen.getByRole('heading', { name: 'Simulasi Alur E-Klaim' }),
        ).toBeInTheDocument();
        expect(screen.getByText('NOT_SENT')).toBeInTheDocument();
        expect(
            screen.getByText('Endpoint eksternal: tidak dikonfigurasi'),
        ).toBeInTheDocument();
        expect(screen.getByText('new_claim')).toBeInTheDocument();
        expect(screen.getByText('set_claim_data')).toBeInTheDocument();
        expect(screen.getByText('grouper')).toBeInTheDocument();
        expect(screen.getByText('claim_final')).toBeInTheDocument();
        expect(screen.queryByText('SIM-RJ-001')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Jalankan' }));
        expect(inertiaPost).toHaveBeenCalledWith(
            '/encounters/example/eclaim-simulation/advance',
            {
                action: 'CREATE_CLAIM',
                request_key: '01J00000000000000000000005',
            },
            expect.objectContaining({ preserveScroll: true }),
        );

        const results = await axe.run(container);
        expect(results.violations).toEqual([]);
    });
});
