import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import WorkQueue, { TaskRow } from '@/pages/work/index';
import type { LaboratorySessionMonitor, WorkTaskItem } from '@/types';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

const inertiaGet = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: inertiaGet },
    usePage: () => ({
        props: {
            auth: { user: { name: 'Mahasiswa RMIK Demo' } },
            requestId: '01J00000000000000000000090',
        },
    }),
}));

const debriefTask: WorkTaskItem = {
    publicId: '01J00000000000000000000001',
    type: 'DEBRIEF',
    title: 'Buka linimasa dan debrief encounter',
    description: 'Telaah alur encounter yang telah difinalisasi.',
    status: { code: 'READY', label: 'Siap' },
    priority: 1,
    sourceProgram: 'RMIK',
    availableAt: null,
    context: { synthetic: true },
    assignmentPublicId: '01J00000000000000000000002',
    sessionCode: 'SIM-RJ-UEU-001',
    actionUrl: '/encounters/example/debrief',
};

const readySessionMonitor: LaboratorySessionMonitor = {
    schemaVersion: 1,
    readOnly: true,
    status: 'OK',
    phase: 'READY_TO_START',
    session: {
        code: 'LAB-WEB-MONITOR-001',
        status: 'ACTIVE',
        source: 'SIM-RJ-UEU-001',
    },
    encounter: {
        number: 'ENC-SIM-000001',
        status: 'PLANNED',
        synthetic: true,
    },
    summary: {
        activeAssignments: 10,
        totalTasks: 4,
        openTasks: 3,
        byStatus: {
            READY: 1,
            WAITING: 2,
            BLOCKED: 0,
            IN_PROGRESS: 0,
            SUBMITTED: 0,
            CHANGES_REQUESTED: 0,
            COMPLETE: 1,
            CANCELLED: 0,
        },
    },
    readyTasks: [
        {
            type: 'REGISTRATION',
            program: 'RMIK',
            role: 'REGISTRAR',
            priority: 1,
        },
    ],
    attention: [],
    workQueuePath: '/work?session=LAB-WEB-MONITOR-001',
};

describe('work queue', () => {
    it('renders the finalized debrief task contract without crashing', () => {
        render(
            <WorkQueue
                assignments={[
                    {
                        publicId: '01J00000000000000000000002',
                        program: { code: 'RMIK', label: 'RMIK' },
                        role: {
                            code: 'REGISTRAR',
                            label: 'Petugas registrasi',
                        },
                        capabilities: [
                            { code: 'debrief.view', label: 'Melihat debrief' },
                        ],
                        session: {
                            publicId: '01J00000000000000000000003',
                            code: 'SIM-RJ-UEU-001',
                            status: { code: 'ACTIVE', label: 'Aktif' },
                            courseCode: 'SIMRS-RJ',
                            cohortCode: 'REFERENSI-2026',
                            scenarioTitle: 'Rawat jalan interprofesional',
                        },
                    },
                ]}
                tasks={[debriefTask]}
                summary={{
                    ready: 1,
                    inProgress: 0,
                    waiting: 0,
                    blocked: 0,
                    changesRequested: 0,
                }}
                selectedSessionCode="SIM-RJ-UEU-001"
                selectionRequired={false}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Buka linimasa dan debrief encounter',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buka tugas' }),
        ).toHaveAttribute('href', '/encounters/example/debrief');
        expect(screen.getAllByText('SIM-RJ-UEU-001')).toHaveLength(2);
        expect(
            screen.getByText('Penutupan & RMIK').closest('li'),
        ).toHaveAttribute('aria-current', 'step');
    });

    it('requires an explicit choice when more than one active session is available', async () => {
        const user = userEvent.setup();
        const firstAssignment = {
            publicId: '01J00000000000000000000002',
            program: { code: 'RMIK', label: 'RMIK' },
            role: { code: 'REGISTRAR', label: 'Petugas registrasi' },
            capabilities: [{ code: 'session.view', label: 'Melihat sesi' }],
            session: {
                publicId: '01J00000000000000000000003',
                code: 'SIM-RJ-UEU-001',
                status: { code: 'ACTIVE', label: 'Aktif' },
                courseCode: 'SIMRS-RJ',
                cohortCode: 'REFERENSI-2026',
                scenarioTitle: 'Rawat jalan interprofesional',
            },
        };
        const secondAssignment = {
            ...firstAssignment,
            publicId: '01J00000000000000000000012',
            session: {
                ...firstAssignment.session,
                publicId: '01J00000000000000000000013',
                code: 'UAT-MAIN-001',
                cohortCode: 'UAT-2026',
            },
        };

        const { container } = render(
            <WorkQueue
                assignments={[firstAssignment, secondAssignment]}
                tasks={[]}
                summary={{
                    ready: 0,
                    inProgress: 0,
                    waiting: 0,
                    blocked: 0,
                    changesRequested: 0,
                }}
                selectedSessionCode={null}
                selectionRequired
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Pilih satu sesi simulasi' }),
        ).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Buka tugas' })).toBeNull();

        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Pilih sesi' }),
            'UAT-MAIN-001',
        );

        expect(inertiaGet).toHaveBeenCalledWith(
            '/work',
            { session: 'UAT-MAIN-001' },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );

        const results = await axe.run(container);
        expect(results.violations).toEqual([]);
    });

    it('uses a safe icon fallback if a newer server task reaches the browser', () => {
        render(
            <TaskRow
                task={{
                    ...debriefTask,
                    type: 'FUTURE_TASK' as WorkTaskItem['type'],
                    title: 'Tugas versi server yang lebih baru',
                }}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Tugas versi server yang lebih baru',
            }),
        ).toBeInTheDocument();
    });

    it('renders the facilitator handoff rail as operational evidence with no grading claim', async () => {
        const { container } = render(
            <WorkQueue
                assignments={[
                    {
                        publicId: '01J00000000000000000000052',
                        program: {
                            code: 'FACILITATION',
                            label: 'Fasilitasi',
                        },
                        role: {
                            code: 'FACILITATOR',
                            label: 'Fasilitator',
                        },
                        capabilities: [
                            {
                                code: 'session.facilitate',
                                label: 'Memfasilitasi sesi',
                            },
                        ],
                        session: {
                            publicId: '01J00000000000000000000053',
                            code: 'LAB-WEB-MONITOR-001',
                            status: { code: 'ACTIVE', label: 'Aktif' },
                            courseCode: 'SIMRS-RJ',
                            cohortCode: 'PILOT-2026',
                            scenarioTitle: 'Rawat jalan interprofesional',
                        },
                    },
                ]}
                tasks={[]}
                summary={{
                    ready: 0,
                    inProgress: 0,
                    waiting: 0,
                    blocked: 0,
                    changesRequested: 0,
                }}
                selectedSessionCode="LAB-WEB-MONITOR-001"
                selectionRequired={false}
                sessionMonitor={readySessionMonitor}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Jalur serah terima laboratorium',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Siap dimulai')).toBeInTheDocument();
        expect(screen.getByText('10')).toBeInTheDocument();
        expect(screen.getAllByText('Registrasi')).not.toHaveLength(0);
        expect(
            screen.getByText('RMIK · Petugas registrasi'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/bukan persentase, nilai, atau keputusan/i),
        ).toBeInTheDocument();

        const results = await axe.run(container);
        expect(results.violations).toEqual([]);
    });

    it('renders a stop instruction when the safe session projection is blocked', () => {
        render(
            <WorkQueue
                assignments={[
                    {
                        publicId: '01J00000000000000000000062',
                        program: {
                            code: 'FACILITATION',
                            label: 'Fasilitasi',
                        },
                        role: {
                            code: 'FACILITATOR',
                            label: 'Fasilitator',
                        },
                        capabilities: [
                            {
                                code: 'session.facilitate',
                                label: 'Memfasilitasi sesi',
                            },
                        ],
                        session: {
                            publicId: '01J00000000000000000000063',
                            code: 'LAB-WEB-MONITOR-003',
                            status: { code: 'ACTIVE', label: 'Aktif' },
                            courseCode: 'SIMRS-RJ',
                            cohortCode: 'PILOT-2026',
                            scenarioTitle: 'Rawat jalan interprofesional',
                        },
                    },
                ]}
                tasks={[]}
                summary={{
                    ready: 0,
                    inProgress: 0,
                    waiting: 0,
                    blocked: 0,
                    changesRequested: 0,
                }}
                selectedSessionCode="LAB-WEB-MONITOR-003"
                selectionRequired={false}
                sessionMonitor={{
                    schemaVersion: 1,
                    readOnly: true,
                    status: 'BLOCKED',
                    session: 'UNAVAILABLE',
                    blockers: [
                        {
                            id: 'session.assignment_roster',
                            detail: 'Internal sanitized detail',
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Pemantauan sesi dihentikan',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/daftar sepuluh penugasan aktif belum lengkap/i),
        ).toBeInTheDocument();
        expect(screen.queryByText('Internal sanitized detail')).toBeNull();
    });
});
