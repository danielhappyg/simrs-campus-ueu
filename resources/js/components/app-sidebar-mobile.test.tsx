import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import {
    Sidebar,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => true,
}));

describe('mobile application sidebar', () => {
    it('renders and opens the narrow-screen sidebar accessibly', async () => {
        const user = userEvent.setup();

        render(
            <SidebarProvider>
                <Sidebar>
                    <p>Mobile navigation</p>
                </Sidebar>
                <SidebarTrigger />
            </SidebarProvider>,
        );

        expect(
            screen.queryByRole('heading', { name: 'Navigasi utama' }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Buka menu navigasi' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Navigasi utama' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Tutup' })).toBeVisible();

        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Buka menu navigasi' }),
            ).toHaveFocus(),
        );
    });
});
