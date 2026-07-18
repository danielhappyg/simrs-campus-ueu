import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EncounterOverview from '@/pages/encounter/show';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const baseProps = {
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: { code: 'IN_INTAKE', label: 'Asesmen awal' },
        serviceType: 'Rawat Jalan',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-18T08:00:00+07:00',
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        mrn: 'MRN-SIM-000001',
        birthDate: '1992-04-18',
        administrativeSex: 'Perempuan',
        allergyStatus: 'Belum dinilai',
        synthetic: true as const,
    },
    assignment: {
        publicId: '01J00000000000000000000003',
        program: 'Kedokteran',
        role: 'Supervisor',
        canViewDebrief: false,
        canViewReports: false,
        canRecordEarlyDeparture: true,
    },
    session: {
        publicId: '01J00000000000000000000004',
        code: 'SIM-RJ-UEU-001',
        scenarioTitle: 'Kunjungan Rawat Jalan Terintegrasi — Data Sintetis',
    },
    queue: [],
    timeline: [
        {
            publicId: '01J00000000000000000000005',
            fromStatus: 'Tiba',
            fromStatusCode: 'ARRIVED',
            toStatus: 'Asesmen awal',
            toStatusCode: 'IN_INTAKE',
            reason: 'test_intake_started',
            occurredAt: '2026-07-18T08:05:00+07:00',
            actor: 'Mahasiswa Keperawatan Demo',
            role: 'Mahasiswa/learner',
        },
    ],
    workflow: [
        { code: 'PLANNED', label: 'Terencana', current: false },
        { code: 'ARRIVED', label: 'Tiba', current: false },
        { code: 'IN_INTAKE', label: 'Asesmen awal', current: true },
    ],
    urls: {
        debrief: '/encounters/example/debrief',
        timeline: '/encounters/example/timeline',
        outpatientSummaryReport: '/encounters/example/reports/summary',
        debriefEvidenceReport: '/encounters/example/reports/debrief',
        interoperabilityPreview: null,
        earlyDeparture: '/encounters/example/early-departure',
    },
};

describe('Encounter overview early-departure action', () => {
    it('shows the server-authorized action with explicit human wording', () => {
        render(<EncounterOverview {...baseProps} />);

        expect(
            screen.getByRole('link', {
                name: 'Catat pulang atas permintaan sendiri',
            }),
        ).toHaveAttribute('href', '/encounters/example/early-departure');
    });

    it('does not render the action when the server withholds it', () => {
        render(
            <EncounterOverview
                {...baseProps}
                assignment={{
                    ...baseProps.assignment,
                    canRecordEarlyDeparture: false,
                }}
                urls={{ ...baseProps.urls, earlyDeparture: null }}
            />,
        );

        expect(
            screen.queryByRole('link', {
                name: 'Catat pulang atas permintaan sendiri',
            }),
        ).not.toBeInTheDocument();
    });
});
