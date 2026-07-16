import { render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import WorkQueue, { TaskRow } from '@/pages/work/index';
import type { WorkTaskItem } from '@/types';

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
        expect(
            screen.getByText('Penutupan & RMIK').closest('li'),
        ).toHaveAttribute('aria-current', 'step');
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
