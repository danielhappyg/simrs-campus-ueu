import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type {
    AnchorHTMLAttributes,
    FormHTMLAttributes,
    ReactNode,
} from 'react';
import { describe, expect, it, vi } from 'vitest';

import Login from '@/pages/auth/login';

type MockLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
    href: string | { url: string };
};

type MockFormProps = Omit<FormHTMLAttributes<HTMLFormElement>, 'children'> & {
    resetOnSuccess?: string[];
    children:
        | ReactNode
        | ((state: {
              processing: boolean;
              errors: Record<string, string>;
          }) => ReactNode);
};

vi.mock('@inertiajs/react', () => ({
    Form: ({ children, resetOnSuccess, ...props }: MockFormProps) => {
        void resetOnSuccess;

        return (
            <form {...props}>
                {typeof children === 'function'
                    ? children({
                          processing: false,
                          errors: {
                              email: 'Email is required.',
                              password: 'Password is required.',
                          },
                      })
                    : children}
            </form>
        );
    },
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={typeof href === 'string' ? href : href.url} {...props}>
            {children}
        </a>
    ),
}));

describe('login keyboard order', () => {
    it('follows the visible authentication sequence', async () => {
        const user = userEvent.setup();

        render(<Login canResetPassword />);

        const focusOrder = [
            screen.getByRole('textbox', { name: 'Email' }),
            screen.getByLabelText('Password'),
            screen.getByRole('button', { name: 'Show password' }),
            screen.getByRole('link', { name: 'Forgot password?' }),
            screen.getByRole('checkbox', { name: 'Remember me' }),
            screen.getByRole('button', { name: 'Sign in' }),
        ];

        expect(document.activeElement).toBe(document.body);

        for (const control of focusOrder) {
            await user.tab();
            expect(control).toHaveFocus();
        }
    });

    it('associates visible validation errors with their fields', () => {
        render(<Login canResetPassword />);

        const email = screen.getByRole('textbox', {
            name: 'Email',
        });
        const password = screen.getByLabelText('Password');

        expect(email).toHaveAttribute('aria-invalid', 'true');
        expect(email).toHaveAttribute('aria-describedby', 'email-error');
        expect(screen.getByText('Email is required.')).toHaveAttribute(
            'id',
            'email-error',
        );
        expect(password).toHaveAttribute('aria-invalid', 'true');
        expect(password).toHaveAttribute('aria-describedby', 'password-error');
        expect(screen.getByText('Password is required.')).toHaveAttribute(
            'id',
            'password-error',
        );
    });

    it('has no detected accessibility violations in the validation state', async () => {
        const { container } = render(<Login canResetPassword />);

        const result = await axe.run(container);

        expect(
            result.violations,
            JSON.stringify(
                result.violations.map((violation) => ({
                    id: violation.id,
                    nodes: violation.nodes.map((node) => node.html),
                })),
            ),
        ).toHaveLength(0);
    });
});
