import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import WorkflowConflict from '@/pages/errors/workflow-conflict';

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

describe('workflow conflict page', () => {
    it('explains an intentional gate without presenting it as a system failure', async () => {
        const { container } = render(
            <WorkflowConflict
                reason="Debrief tersedia setelah encounter difinalisasi untuk simulasi."
                workQueueUrl="/work"
            />,
        );

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Tahap belum tersedia',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('HTTP 409')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Debrief tersedia setelah encounter difinalisasi untuk simulasi.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/something is broken/i),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Kembali ke antrean kerja' }),
        ).toHaveAttribute('href', '/work');

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
});
