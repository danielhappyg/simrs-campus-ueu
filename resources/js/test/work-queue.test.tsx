import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import WorkQueue, { TaskRow } from '@/pages/work/index';
import type { WorkTaskItem } from '@/types';

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
});
