import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ComponentProps, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PendaftaranRawatInap from '@/pages/pendaftaran/rawat-inap';

const inertiaMock = vi.hoisted(() => ({
    post: vi.fn(),
    payload: vi.fn(),
    serverErrors: {
        full_name: 'Nama lengkap wajib diisi.',
        bed_public_id: 'Tempat tidur sudah dipakai kunjungan rawat inap aktif.',
        chief_complaint: 'Keluhan utama wajib diisi.',
    },
}));

type MockPostOptions = {
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: () => void;
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
        usePage: () => ({
            props: {
                auth: { capabilities: ['inpatient.occupancy.view'] },
                flash: {},
            },
        }),
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
                    inertiaMock.payload(data);
                    setErrors(inertiaMock.serverErrors);
                    options?.onError?.(inertiaMock.serverErrors);
                },
                reset: () => setDataState(initialData),
            };
        },
    };
});

type RegistrationWards = ComponentProps<typeof PendaftaranRawatInap>['wards'];

const defaultWards: RegistrationWards = [
    {
        public_id: 'ward-anggrek',
        code: 'ANGGREK',
        display_name: 'Bangsal Anggrek',
        beds: [
            {
                public_id: 'bed-ang-101-a',
                code: 'ANG-101-A',
                display_name: 'Tempat Tidur ANG-101-A',
                service_class: 'Kelas 1',
            },
            {
                public_id: 'bed-ang-101-b',
                code: 'ANG-101-B',
                display_name: 'Tempat Tidur ANG-101-B',
                service_class: 'Kelas 2',
            },
        ],
    },
];

function inpatientRegistrationPage(wards: RegistrationWards = defaultWards) {
    return (
        <main>
            <PendaftaranRawatInap
                q=""
                searchResults={[]}
                todaysEncounters={[]}
                wards={wards}
                wardOptions={[
                    {
                        value: 'Bangsal Anggrek',
                        label: 'Bangsal Anggrek',
                    },
                ]}
                sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                continueFromOptions={[{ value: 'LANGSUNG', label: 'Langsung' }]}
                filters={{
                    q: '',
                    ward: '',
                    payer: '',
                    continue_from: '',
                    date_from: '',
                    date_to: '',
                }}
                canRegister
            />
        </main>
    );
}

function renderInpatientRegistration() {
    return render(inpatientRegistrationPage());
}

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: {
            // jsdom has no layout engine, so rendered contrast is verified in the native-browser rehearsal.
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

describe('inpatient registration validation accessibility', () => {
    beforeEach(() => {
        inertiaMock.post.mockClear();
        inertiaMock.payload.mockClear();
    });

    it('associates server errors, exposes a linked summary, and refocuses repeated failures', async () => {
        const { container } = renderInpatientRegistration();
        expect(
            screen.getByRole('link', { name: 'Lihat ketersediaan TT' }),
        ).toHaveAttribute('href', '/manajemen-data/bangsal');
        const saveButton = screen.getByRole('button', {
            name: 'Simpan pendaftaran RI',
        });
        const form = saveButton.closest('form');

        if (!form) {
            throw new Error('Inpatient registration form was not rendered.');
        }

        fireEvent.submit(form);

        const summary = await screen.findByRole('alert', {
            name: 'Pendaftaran rawat inap belum dapat disimpan.',
        });
        expect(inertiaMock.post).toHaveBeenCalledWith(
            '/pendaftaran/rawat-inap',
        );
        expect(inertiaMock.payload).toHaveBeenCalledWith(
            expect.objectContaining({
                bed_code: 'ANG-101-A',
                bed_public_id: 'bed-ang-101-a',
            }),
        );
        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(summary).toHaveFocus();

        const errorCases = [
            {
                id: 'full_name',
                control: screen.getByRole('textbox', { name: 'Nama lengkap' }),
                linkName: 'Nama lengkap: Nama lengkap wajib diisi.',
                message: 'Nama lengkap wajib diisi.',
            },
            {
                id: 'bed_code',
                control: screen.getByRole('combobox', {
                    name: 'Tempat tidur',
                }),
                linkName:
                    'Tempat tidur: Tempat tidur sudah dipakai kunjungan rawat inap aktif.',
                message:
                    'Tempat tidur sudah dipakai kunjungan rawat inap aktif.',
            },
            {
                id: 'chief_complaint',
                control: screen.getByRole('textbox', {
                    name: 'Keluhan utama',
                }),
                linkName: 'Keluhan utama: Keluhan utama wajib diisi.',
                message: 'Keluhan utama wajib diisi.',
            },
        ];

        for (const { id, control, linkName, message } of errorCases) {
            const errorId = `${id}-error`;

            expect(control).toHaveAttribute('aria-invalid', 'true');
            expect(control).toHaveAttribute('aria-describedby', errorId);
            expect(document.getElementById(errorId)).toHaveTextContent(message);
            expect(
                within(summary).getByRole('link', { name: linkName }),
            ).toHaveAttribute('href', `#${id}`);
        }

        const validControl = screen.getByRole('textbox', { name: 'Telepon' });
        expect(validControl).not.toHaveAttribute('aria-invalid');
        expect(validControl).not.toHaveAttribute('aria-describedby');

        validControl.focus();
        fireEvent.submit(form);

        await waitFor(() => expect(summary).toHaveFocus());
        expect(inertiaMock.post).toHaveBeenCalledTimes(2);

        await expectNoWcag21Violations(container);
    });

    it('derives the displayed and submitted class from the immutable bed selection', () => {
        renderInpatientRegistration();

        fireEvent.change(
            screen.getByRole('combobox', { name: 'Tempat tidur' }),
            { target: { value: 'bed-ang-101-b' } },
        );

        expect(screen.getByRole('textbox', { name: 'Kelas' })).toHaveValue(
            'Kelas 2',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Simpan pendaftaran RI' }),
        );

        expect(inertiaMock.payload).toHaveBeenCalledWith(
            expect.objectContaining({
                ward_name: 'Bangsal Anggrek',
                ward_class: 'Kelas 2',
                bed_code: 'ANG-101-B',
                bed_public_id: 'bed-ang-101-b',
            }),
        );
    });

    it('distinguishes wards with the same display name by immutable ID and code', () => {
        render(
            inpatientRegistrationPage([
                ...defaultWards,
                {
                    public_id: 'ward-anggrek-b',
                    code: 'ANGGREK-B',
                    display_name: 'Bangsal Anggrek',
                    beds: [
                        {
                            public_id: 'bed-ang-b-301',
                            code: 'ANG-B-301',
                            display_name: 'Tempat Tidur ANG-B-301',
                            service_class: 'Kelas Utama',
                        },
                    ],
                },
            ]),
        );

        fireEvent.change(document.getElementById('ward_name')!, {
            target: { value: 'ward-anggrek-b' },
        });

        expect(document.getElementById('ward_name')).toHaveValue(
            'ward-anggrek-b',
        );
        expect(
            screen.getByRole('combobox', { name: 'Tempat tidur' }),
        ).toHaveValue('bed-ang-b-301');
        expect(screen.getByRole('textbox', { name: 'Kelas' })).toHaveValue(
            'Kelas Utama',
        );
    });

    it('reconciles placement after refreshed availability and clears it when none remain', async () => {
        const { rerender } = renderInpatientRegistration();

        rerender(
            inpatientRegistrationPage([
                {
                    public_id: 'ward-empty',
                    code: 'EMPTY',
                    display_name: 'Bangsal Tanpa TT',
                    beds: [],
                },
                {
                    public_id: 'ward-mawar',
                    code: 'MAWAR',
                    display_name: 'Bangsal Mawar',
                    beds: [
                        {
                            public_id: 'bed-mawar-201',
                            code: 'MWR-201',
                            display_name: 'Tempat Tidur MWR-201',
                            service_class: 'Kelas VIP',
                        },
                    ],
                },
            ]),
        );

        await waitFor(() =>
            expect(document.getElementById('ward_name')).toHaveValue(
                'ward-mawar',
            ),
        );
        expect(
            screen.getByRole('combobox', { name: 'Tempat tidur' }),
        ).toHaveValue('bed-mawar-201');
        expect(screen.getByRole('textbox', { name: 'Kelas' })).toHaveValue(
            'Kelas VIP',
        );

        rerender(inpatientRegistrationPage([]));

        expect(
            screen.getByText(
                /Tidak ada tempat tidur rawat inap yang tersedia/i,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Simpan pendaftaran RI' }),
        ).toBeDisabled();
        await waitFor(() =>
            expect(screen.getByRole('textbox', { name: 'Kelas' })).toHaveValue(
                '',
            ),
        );
    });
});
