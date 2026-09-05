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
            screen.queryByRole('heading', { name: 'Main navigation' }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Open navigation menu' }),
        );

        expect(
            screen.getByRole('heading', { name: 'Main navigation' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Close' })).toBeVisible();

        await user.keyboard('{Escape}');

        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Open navigation menu' }),
            ).toHaveFocus(),
        );
    });
});
