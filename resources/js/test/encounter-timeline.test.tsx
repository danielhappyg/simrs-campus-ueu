import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EncounterOverview from '@/pages/encounter/show';
import EncounterRecordTimeline from '@/pages/encounter/timeline';

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

const encounter = {
    publicId: '01J00000000000000000000001',
    number: 'ENC-SIM-000001',
    status: { code: 'IN_INTAKE', label: 'Dalam asesmen awal' },
    serviceType: 'Poliklinik umum',
    location: 'Poliklinik Umum Simulasi UEU',
    periodStart: '2026-07-17T08:00:00+07:00',
    environmentMode: 'SIMULATION',
};

const patient = {
    publicId: '01J00000000000000000000002',
    fullName: 'Pasien Sintetis Arunika',
    birthDate: '1992-04-18',
    administrativeSex: 'Perempuan',
    mrn: 'MR-SIM-000001',
    synthetic: true as const,
    allergyStatus: 'Lihat sumber asesmen pada linimasa',
};

const baseEvent = {
    publicId: '01J00000000000000000000010',
    sequence: 1,
    actor: {
        name: 'Mahasiswa Keperawatan Demo',
        program: 'Keperawatan',
        role: 'Mahasiswa',
        assignmentPublicId: '01J00000000000000000000020',
    },
    source: {
        label: 'Versi dokumen klinis',
        publicId: '01J00000000000000000000030',
        version: 'v1',
    },
    recordedAt: '2026-07-17T09:05:00+07:00',
    clinicalOccurrenceAt: '2026-07-17T09:00:00+07:00',
    primaryAt: '2026-07-17T09:00:00+07:00',
    showsRecordedTimeDifference: true,
    outcome: 'SUCCESS',
};

const props = {
    encounter,
    patient,
    assignment: {
        publicId: '01J00000000000000000000020',
        program: 'Keperawatan',
        role: 'Mahasiswa',
    },
    session: {
        publicId: '01J00000000000000000000003',
        code: 'SIM-RJ-UEU-001',
        status: { code: 'ACTIVE', label: 'Aktif' },
        scenarioTitle: 'Rawat jalan interprofesional',
    },
    release: {
        readOnly: true,
        sessionCompleted: false,
        encounterStatus: 'IN_INTAKE',
    },
    events: [
        {
            ...baseEvent,
            category: { code: 'NURSING', label: 'Keperawatan' },
            title: 'Versi asesmen awal dibuat',
            detail: 'Sumber v1 direkam tanpa menimpa versi sebelumnya.',
            tags: [{ code: 'HANDOFF', label: 'Handoff' }],
        },
        {
            ...baseEvent,
            publicId: '01J00000000000000000000011',
            sequence: 2,
            category: { code: 'CODING', label: 'Koding' },
            title: 'Koding diagnosis diajukan',
            detail: 'Draf dikirim untuk tinjauan supervisor.',
            actor: {
                name: 'Koder RMIK Demo',
                program: 'RMIK',
                role: 'Koder RMIK',
                assignmentPublicId: '01J00000000000000000000021',
            },
            source: {
                label: 'Penetapan kode',
                publicId: '01J00000000000000000000031',
                version: null,
            },
            recordedAt: '2026-07-17T09:20:00+07:00',
            clinicalOccurrenceAt: null,
            primaryAt: '2026-07-17T09:20:00+07:00',
            showsRecordedTimeDifference: false,
            tags: [{ code: 'HUMAN_CODING', label: 'Keputusan koding manusia' }],
        },
        {
            ...baseEvent,
            publicId: '01J00000000000000000000012',
            sequence: 3,
            category: { code: 'PHARMACY', label: 'Farmasi' },
            title: 'Telaah farmasi dicatat',
            detail: 'Outcome telaah: diterima.',
            actor: {
                name: 'Mahasiswa Farmasi Demo',
                program: 'Farmasi',
                role: 'Mahasiswa',
                assignmentPublicId: '01J00000000000000000000022',
            },
            source: {
                label: 'Telaah farmasi',
                publicId: '01J00000000000000000000032',
                version: null,
            },
            recordedAt: '2026-07-17T09:30:00+07:00',
            clinicalOccurrenceAt: null,
            primaryAt: '2026-07-17T09:30:00+07:00',
            showsRecordedTimeDifference: false,
            tags: [],
        },
    ],
    summary: {
        displayedEventCount: 3,
        totalAvailableEventCount: 3,
        truncated: false,
        categoryCounts: [
            { label: 'Keperawatan', count: 1 },
            { label: 'Koding', count: 1 },
            { label: 'Farmasi', count: 1 },
        ],
        programCounts: [
            { label: 'Keperawatan', count: 1 },
            { label: 'RMIK', count: 1 },
            { label: 'Farmasi', count: 1 },
        ],
        correctionCount: 0,
        supervisionCount: 0,
        handoffCount: 1,
    },
    urls: {
        encounter: '/encounters/example',
        self: '/encounters/example/timeline',
        debrief: null,
        workQueue: '/work',
    },
};

describe('Encounter record timeline', () => {
    it('presents a read-only simulation-labelled source index without audit internals', async () => {
        const { container } = render(<EncounterRecordTimeline {...props} />);

        expect(
            screen.getByRole('heading', {
                name: 'Linimasa Rekam Rawat Jalan',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('SIMULASI — DATA SINTETIS'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Indeks provenance sumber/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Versi asesmen awal dibuat'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Versi dokumen klinis · v1'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Mahasiswa Keperawatan Demo/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Dicatat/)).toBeInTheDocument();
        expect(container.textContent).not.toContain('request_correlation_id');
        expect(container.textContent).not.toContain('ip_hash');
        expect(container.textContent).not.toContain('raw-secret');

        const result = await axe.run(container);
        expect(
            result.violations,
            JSON.stringify(
                result.violations.map((violation) => ({
                    id: violation.id,
                    targets: violation.nodes.map((node) => node.target),
                })),
            ),
        ).toHaveLength(0);
    });

    it('filters presentation by stage and actor program and announces the count', async () => {
        const user = userEvent.setup();
        render(<EncounterRecordTimeline {...props} />);

        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Tahap layanan' }),
            'CODING',
        );
        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Program aktor' }),
            'RMIK',
        );

        expect(
            screen.getByText('Koding diagnosis diajukan'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Versi asesmen awal dibuat'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('1 dari 3 peristiwa')).toBeInTheDocument();
    });

    it('shows honest empty-filter and truncation states', async () => {
        const user = userEvent.setup();
        render(
            <EncounterRecordTimeline
                {...props}
                summary={{
                    ...props.summary,
                    truncated: true,
                    totalAvailableEventCount: 301,
                }}
            />,
        );

        expect(screen.getByText(/Proyeksi dibatasi/)).toBeInTheDocument();
        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Tahap layanan' }),
            'NURSING',
        );
        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Program aktor' }),
            'RMIK',
        );

        expect(
            screen.getByText('Tidak ada peristiwa yang cocok dengan filter.'),
        ).toBeInTheDocument();
        expect(screen.getByText('0 dari 3 peristiwa')).toBeInTheDocument();
    });

    it('links the encounter overview to the distinct record timeline', () => {
        render(
            <EncounterOverview
                encounter={encounter}
                patient={patient}
                assignment={{
                    publicId: props.assignment.publicId,
                    program: props.assignment.program,
                    role: props.assignment.role,
                    canViewDebrief: false,
                    canViewReports: false,
                    canRecordEarlyDeparture: false,
                }}
                session={{
                    publicId: props.session.publicId,
                    code: props.session.code,
                    scenarioTitle: props.session.scenarioTitle,
                }}
                queue={[]}
                timeline={[]}
                workflow={[
                    { code: 'PLANNED', label: 'Direncanakan', current: false },
                    {
                        code: 'IN_INTAKE',
                        label: 'Dalam asesmen awal',
                        current: true,
                    },
                ]}
                urls={{
                    debrief: '/encounters/example/debrief',
                    timeline: '/encounters/example/timeline',
                    outpatientSummaryReport:
                        '/encounters/example/reports/outpatient-summary',
                    debriefEvidenceReport:
                        '/encounters/example/reports/debrief-evidence',
                }}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Buka linimasa rekam' }),
        ).toHaveAttribute('href', '/encounters/example/timeline');
    });

    it('does not render record navigation when the server withholds authorization', () => {
        render(
            <EncounterOverview
                encounter={encounter}
                patient={patient}
                assignment={{
                    publicId: props.assignment.publicId,
                    program: 'RMIK',
                    role: 'Petugas Registrasi Simulasi',
                    canViewDebrief: false,
                    canViewReports: false,
                    canRecordEarlyDeparture: false,
                }}
                session={{
                    publicId: props.session.publicId,
                    code: props.session.code,
                    scenarioTitle: props.session.scenarioTitle,
                }}
                queue={[]}
                timeline={[]}
                workflow={[
                    { code: 'PLANNED', label: 'Direncanakan', current: true },
                ]}
                urls={{
                    debrief: '/encounters/example/debrief',
                    timeline: null,
                    outpatientSummaryReport:
                        '/encounters/example/reports/outpatient-summary',
                    debriefEvidenceReport:
                        '/encounters/example/reports/debrief-evidence',
                }}
            />,
        );

        expect(
            screen.queryByRole('link', { name: 'Buka linimasa rekam' }),
        ).not.toBeInTheDocument();
    });
});
