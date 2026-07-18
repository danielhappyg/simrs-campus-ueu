import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EarlyDepartureWorkspace from '@/pages/clinical/early-departure';
import type { OutpatientEarlyDepartureWorkspaceProps } from '@/types/clinical';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...props }: MockLinkProps) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, replaceData] = React.useState(initial);

            return {
                data,
                setData: (key: keyof T, value: T[keyof T]) =>
                    replaceData((current) => ({ ...current, [key]: value })),
                errors: {},
                processing: false,
                post: vi.fn(),
            };
        },
    };
});

const baseProps = {
    boundary: {
        classification: 'SIMULASI — DATA SINTETIS' as const,
        clinicalRecommendation: false as const,
        automaticFinalization: false as const,
    },
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: { code: 'IN_INTAKE', label: 'Asesmen awal' },
        serviceType: 'Rawat Jalan',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-18T08:00:00+07:00',
        periodEnd: null,
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        mrn: 'MRN-SIM-000001',
        birthDate: '1992-04-18',
        administrativeSex: 'Perempuan',
        allergyStatus: 'Lihat sumber klinis yang tersedia',
        synthetic: true as const,
    },
    session: {
        code: 'SIM-RJ-UEU-001',
        scenarioTitle: 'Kunjungan Rawat Jalan Terintegrasi — Data Sintetis',
    },
    source: {
        encounterStatus: { code: 'IN_INTAKE', label: 'Asesmen awal' },
        clinicalSources: [
            {
                documentType: 'NURSING_INTAKE',
                entryPublicId: '01J00000000000000000000003',
                versionPublicId: '01J00000000000000000000004',
                versionNumber: 1,
                status: 'DRAFT',
                contentHash:
                    'f20d0de7107d1b44d35c6b1cfae1ce4ef430f66de01b4841f5057f1455e1f947',
            },
        ],
        snapshotHash: null,
    },
    authorization: {
        assignmentPublicId: '01J00000000000000000000005',
        role: 'Supervisor',
        canRecord: true,
    },
    departure: null,
    form: {
        requestKey: '01J00000000000000000000006',
        outcome: {
            code: 'PATIENT_REQUESTED_DEPARTURE' as const,
            label: 'Pulang atas permintaan sendiri',
            interoperabilityCode: 'aadvice',
        },
    },
    urls: {
        store: '/encounters/example/early-departure',
        encounter: '/encounters/example',
        timeline: '/encounters/example/timeline',
        workQueue: '/work',
    },
} satisfies OutpatientEarlyDepartureWorkspaceProps;

describe('Outpatient early-departure workspace', () => {
    it('requires explicit human confirmation and keeps the non-recommendation boundary visible', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <EarlyDepartureWorkspace {...baseProps} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Pulang atas permintaan sendiri',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('SIMULASI — DATA SINTETIS'),
        ).not.toHaveLength(0);
        expect(
            screen.getByText(/Bukan pembatalan atau tidak hadir/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Bukan keputusan aman untuk pulang/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/tidak memfinalisasi rekam secara otomatis/i),
        ).toBeInTheDocument();
        expect(screen.getByText('IN_INTAKE')).toBeInTheDocument();
        expect(screen.getByText('NURSING_INTAKE')).toBeInTheDocument();
        expect(
            screen.getByText(
                'f20d0de7107d1b44d35c6b1cfae1ce4ef430f66de01b4841f5057f1455e1f947',
            ),
        ).toBeInTheDocument();

        const confirmation = screen.getByRole('checkbox', {
            name: /Saya menegaskan bahwa pasien sintetis/i,
        });
        expect(confirmation).not.toBeChecked();
        expect(confirmation).toBeRequired();
        await user.click(confirmation);
        expect(confirmation).toBeChecked();

        const statedReason = screen.getByLabelText(
            'Alasan yang dinyatakan pasien atau perwakilan sintetis',
        );
        const communication = screen.getByLabelText(
            'Ringkasan komunikasi yang dicatat manusia',
        );
        expect(statedReason).toBeRequired();
        expect(communication).toBeRequired();
        expect(statedReason).toHaveAttribute(
            'aria-describedby',
            'stated-reason-help stated-reason-error',
        );
        expect(communication).toHaveAttribute(
            'aria-describedby',
            'communication-summary-help communication-summary-error',
        );
        expect(
            screen.getByRole('button', {
                name: 'Catat pulang atas permintaan sendiri',
            }),
        ).toHaveClass('min-h-11');

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

    it('renders the recorded event as attributable read-only evidence', () => {
        render(
            <EarlyDepartureWorkspace
                {...baseProps}
                authorization={{
                    ...baseProps.authorization,
                    canRecord: false,
                }}
                source={{
                    ...baseProps.source,
                    snapshotHash:
                        '1e3c9599ed6431ba327c70c75e8dbf5876d3fe77c086d1c672c6d7cf12345678',
                }}
                departure={{
                    publicId: '01J00000000000000000000007',
                    outcome: baseProps.form.outcome,
                    statedReason:
                        'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
                    communicationSummary:
                        'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.',
                    actor: 'Supervisor Kedokteran Demo',
                    role: 'Supervisor',
                    occurredAt: '2026-07-18T08:15:00+07:00',
                    sourceSnapshotHash:
                        '1e3c9599ed6431ba327c70c75e8dbf5876d3fe77c086d1c672c6d7cf12345678',
                }}
                form={{ ...baseProps.form, requestKey: null }}
            />,
        );

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Catat pulang atas permintaan sendiri',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Supervisor Kedokteran Demo'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Pasien sintetis meminta pulang sebelum alur rutin selesai.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Supervisor mencatat informasi simulasi dan tindak lanjut faktual telah disampaikan.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Peristiwa sudah dicatat')).toBeInTheDocument();
    });
});
