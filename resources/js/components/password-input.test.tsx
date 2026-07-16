import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import PasswordInput from '@/components/password-input';

describe('PasswordInput', () => {
    it('keeps its visibility control keyboard reachable and localized', async () => {
        const user = userEvent.setup();

        render(<PasswordInput aria-label="Kata sandi" />);

        const input = screen.getByLabelText('Kata sandi');
        const toggle = screen.getByRole('button', {
            name: 'Tampilkan kata sandi',
        });

        expect(toggle).not.toHaveAttribute('tabindex', '-1');
        expect(input).toHaveAttribute('type', 'password');

        await user.tab();
        expect(input).toHaveFocus();
        await user.tab();
        expect(toggle).toHaveFocus();

        await user.click(toggle);

        expect(input).toHaveAttribute('type', 'text');
        expect(
            screen.getByRole('button', { name: 'Sembunyikan kata sandi' }),
        ).toBeInTheDocument();
    });
});
