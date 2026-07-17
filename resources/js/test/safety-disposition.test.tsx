import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import SafetyDispositionWorkspace from '@/pages/clinical/safety-disposition';
import type { OutpatientSafetyDispositionWorkspaceProps } from '@/types';

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

const baseProps: OutpatientSafetyDispositionWorkspaceProps = {
    boundary: {
        classification: 'SIMULASI — DATA SINTETIS',
        emergencyTriageClaim: false,
        clinicalRecommendation: false,
    },
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: { code: 'ESCALATED', label: 'Dihentikan untuk eskalasi' },
        serviceType: 'Poliklinik Umum Simulasi UEU',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-17T08:00:00+07:00',
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        mrn: 'MRN-SIM-000001',
        birthDate: '1992-04-18',
        administrativeSex: 'Perempuan',
        allergyStatus: 'Lihat asesmen awal yang disetujui',
        synthetic: true,
    },
    session: {
        code: 'SIM-RJ-UEU-001',
        scenarioTitle: 'Kunjungan Rawat Jalan Terintegrasi — Data Sintetis',
    },
    source: {
        versionPublicId: '01J00000000000000000000003',
        versionNumber: 1,
        contentHash:
            'f20d0de7107d1b44d35c6b1cfae1ce4ef430f66de01b4841f5057f1455e1f947',
        author: 'Mahasiswa Keperawatan Demo',
        authorRole: 'Mahasiswa/learner',
        approvedAt: '2026-07-17T08:10:00+07:00',
        safetyDecision: 'ESCALATE_TO_SUPERVISOR',
        safetyResponses: [
            {
                questionCode: 'SUPERVISOR_CONCERN',
                response: 'YES',
                note: 'Mahasiswa memilih eskalasi manusia untuk skenario ini.',
            },
        ],
        handoffSummary:
            'Keluhan dan tanda vital dicatat; alur rutin dihentikan untuk keputusan manusia.',
    },
    authorization: {
        assignmentPublicId: '01J00000000000000000000004',
        role: 'Supervisor',
        canRecord: true,
    },
    disposition: null,
    formOptions: {
        requestKey: '01J00000000000000000000005',
        selectedOutcome: null,
        outcomes: [
            {
                code: 'RESUME_ROUTINE_FLOW',
                label: 'Lanjutkan alur rutin simulasi',
                consequence:
                    'Encounter kembali menunggu klinisi dan tugas asesmen medis menjadi siap.',
            },
            {
                code: 'SIMULATED_TRANSFER',
                label: 'Alihkan dalam simulasi',
                consequence:
                    'Encounter dicatat dialihkan dalam simulasi dan asesmen medis rutin dibatalkan.',
            },
        ],
    },
    urls: {
        store: '/encounters/example/safety-disposition',
        encounter: '/encounters/example',
        workQueue: '/work',
    },
};

describe('Outpatient safety disposition workspace', () => {
    it('keeps the paused human-decision boundary explicit and starts without a default outcome', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <SafetyDispositionWorkspace {...baseProps} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Keputusan Eskalasi Simulasi',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('SIMULASI — DATA SINTETIS'),
        ).not.toHaveLength(0);
        expect(screen.getAllByText('ALUR RUTIN DIHENTIKAN')).not.toHaveLength(
            0,
        );
        expect(screen.getByText(/Bukan triase IGD/i)).toBeInTheDocument();
        expect(
            screen.getByText(/Bukan rekomendasi diagnosis atau terapi/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Keputusan harus dibuat oleh manusia yang berwenang/i,
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Asesmen awal')).toBeInTheDocument();
        expect(screen.getByText('Keputusan manusia')).toBeInTheDocument();

        const radios = screen.getAllByRole('radio');
        expect(radios).toHaveLength(2);
        expect(radios[0]).not.toBeChecked();
        expect(radios[1]).not.toBeChecked();
        await user.click(
            screen.getByRole('radio', {
                name: /Lanjutkan alur rutin simulasi/i,
            }),
        );
        expect(radios[0]).toBeChecked();

        const rationale = screen.getByLabelText('Rasional keputusan manusia');
        expect(rationale).toBeRequired();
        expect(rationale).toHaveAttribute(
            'aria-describedby',
            'rationale-help rationale-error',
        );
        expect(
            screen.getByRole('button', { name: 'Catat keputusan manusia' }),
        ).toHaveClass('min-h-11');
        expect(screen.getByTestId('paused-flow-rail')).toHaveClass(
            'overflow-hidden',
        );

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

    it('shows the recorded outcome as attributable read-only evidence', () => {
        render(
            <SafetyDispositionWorkspace
                {...baseProps}
                authorization={{
                    ...baseProps.authorization,
                    canRecord: false,
                }}
                disposition={{
                    publicId: '01J00000000000000000000006',
                    outcome: {
                        code: 'RESUME_ROUTINE_FLOW',
                        label: 'Lanjutkan alur rutin simulasi',
                    },
                    rationale:
                        'Supervisor meninjau eskalasi dan memutuskan alur rutin simulasi dapat dilanjutkan.',
                    actor: 'Supervisor Keperawatan Demo',
                    role: 'Supervisor',
                    occurredAt: '2026-07-17T08:15:00+07:00',
                }}
                formOptions={{
                    ...baseProps.formOptions,
                    requestKey: null,
                }}
            />,
        );

        expect(screen.queryAllByRole('radio')).toHaveLength(0);
        expect(
            screen.queryByRole('button', { name: 'Catat keputusan manusia' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Supervisor Keperawatan Demo'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Supervisor meninjau eskalasi dan memutuskan alur rutin simulasi dapat dilanjutkan.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Keputusan sudah dicatat')).toBeInTheDocument();
    });
});
