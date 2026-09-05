import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PendaftaranRawatJalan from '@/pages/pendaftaran/rawat-jalan';

const inertiaMock = vi.hoisted(() => ({
    post: vi.fn(),
    serverErrors: {
        full_name: 'Patient name is required.',
        clinic_public_id: 'Select a clinic.',
        payer_type: 'The payment method is invalid.',
        insurance_number: 'The insurance number is invalid.',
    },
}));

type MockPostOptions = {
    onError?: (errors: Record<string, string>) => void;
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: (
            props: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
                children?: ReactNode;
                href: string | { url: string };
                prefetch?: boolean;
                preserveScroll?: boolean;
                preserveState?: boolean;
                replace?: boolean;
            },
        ) => {
            const anchorProps = { ...props };
            delete anchorProps.prefetch;
            delete anchorProps.preserveScroll;
            delete anchorProps.preserveState;
            delete anchorProps.replace;
            const { children, href, ...attributes } = anchorProps;

            return (
                <a
                    href={typeof href === 'string' ? href : href.url}
                    {...attributes}
                >
                    {children}
                </a>
            );
        },
        router: { get: vi.fn() },
        useForm: function useForm<T extends Record<string, unknown>>(
            initialData: T,
        ) {
            const [data, setDataState] = React.useState(initialData);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );

            const setData = (
                keyOrData: string | T | ((current: T) => T),
                value?: unknown,
            ) => {
                if (typeof keyOrData === 'function') {
                    setDataState(keyOrData);
                } else if (typeof keyOrData === 'string') {
                    setDataState((current) => ({
                        ...current,
                        [keyOrData]: value,
                    }));
                } else {
                    setDataState(keyOrData);
                }
            };

            return {
                data,
                errors,
                processing: false,
                setData,
                post: (url: string, options?: MockPostOptions) => {
                    inertiaMock.post(url);
                    setErrors(inertiaMock.serverErrors);
                    options?.onError?.(inertiaMock.serverErrors);
                },
                reset: () => setDataState(initialData),
                transform: vi.fn(),
            };
        },
        usePage: () => ({ props: { flash: {} } }),
    };
});

function renderRegistration() {
    return render(
        <main>
            <PendaftaranRawatJalan
                q=""
                searchResults={[]}
                todaysEncounters={[]}
                clinics={[
                    {
                        public_id: 'clinic-1',
                        code: 'UMUM',
                        name: 'Poli Umum',
                        doctors: [],
                    },
                ]}
                sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                maritalOptions={[{ value: 'MENIKAH', label: 'Menikah' }]}
                religionOptions={[{ value: 'ISLAM', label: 'Islam' }]}
                educationOptions={[{ value: 'S1', label: 'S1' }]}
                occupationOptions={[{ value: 'DOSEN', label: 'Dosen' }]}
                ethnicityOptions={[{ value: 'LAINNYA', label: 'Lainnya' }]}
                languageOptions={[{ value: 'INDONESIA', label: 'Indonesia' }]}
                payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                admissionOptions={[
                    { value: 'DATANG_SENDIRI', label: 'Datang sendiri' },
                ]}
                wilayahProvinces={[]}
                canRegister
            />
        </main>,
    );
}

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: {
            // jsdom has no layout/rendering engine, so axe cannot calculate reliable color contrast here.
            'color-contrast': { enabled: false },
        },
    });

    expect(
        result.violations,
        result.violations
            .map(
                (violation) =>
                    `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
            )
            .join('\n'),
    ).toHaveLength(0);
}

describe('outpatient registration validation accessibility', () => {
    beforeEach(() => {
        inertiaMock.post.mockClear();
    });

    it('associates server errors, summarizes them, and refocuses the summary after repeated failures', async () => {
        const { container } = renderRegistration();
        const saveButton = screen.getByRole('button', { name: 'Save' });
        const form = saveButton.closest('form');

        if (!form) {
            throw new Error('Registration form was not rendered.');
        }

        fireEvent.submit(form);

        const summary = await screen.findByRole('alert', {
            name: 'Registration cannot be saved yet.',
        });
        expect(inertiaMock.post).toHaveBeenCalledWith(
            '/pendaftaran/rawat-jalan',
        );
        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(summary).toHaveFocus();

        const errorCases = [
            {
                id: 'full_name',
                control: screen.getByRole('textbox', {
                    name: 'Patient name',
                }),
                linkName: 'Patient name: Patient name is required.',
                message: 'Patient name is required.',
            },
            {
                id: 'clinic_public_id',
                control: screen.getByRole('combobox', {
                    name: 'Clinic',
                }),
                linkName: 'Clinic or unit: Select a clinic.',
                message: 'Select a clinic.',
            },
            {
                id: 'payer_type',
                control: screen.getByRole('combobox', {
                    name: 'Payment method',
                }),
                linkName: 'Payment method: The payment method is invalid.',
                message: 'The payment method is invalid.',
            },
            {
                id: 'insurance_number',
                control: screen.getByRole('textbox', {
                    name: 'Insurance number',
                }),
                linkName: 'Insurance number: The insurance number is invalid.',
                message: 'The insurance number is invalid.',
            },
        ];

        for (const { id, control, linkName, message } of errorCases) {
            const errorId = `${id}-error`;
            const messageElement = document.getElementById(errorId);

            expect(control).toHaveAttribute('aria-invalid', 'true');
            expect(control).toHaveAttribute('aria-describedby', errorId);
            expect(messageElement).not.toBeNull();
            expect(messageElement).toHaveTextContent(message);
            expect(
                within(summary).getByRole('link', { name: linkName }),
            ).toHaveAttribute('href', `#${id}`);
        }

        const validControl = screen.getByRole('textbox', {
            name: 'Medical record number',
        });
        expect(validControl).not.toHaveAttribute('aria-invalid');
        expect(validControl).not.toHaveAttribute('aria-describedby');

        for (const stubName of ['Check', 'FR', 'FP']) {
            const stub = screen.getByRole('button', { name: stubName });
            expect(stub).toBeDisabled();
            expect(stub).not.toHaveAttribute('aria-invalid');
            expect(stub).not.toHaveAttribute('aria-describedby');
        }

        validControl.focus();
        expect(validControl).toHaveFocus();

        fireEvent.submit(form);

        await waitFor(() => expect(summary).toHaveFocus());
        expect(inertiaMock.post).toHaveBeenCalledTimes(2);

        await expectNoWcag21Violations(container);
    }, 10_000);
});
