import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PendaftaranRawatInap from '@/pages/pendaftaran/rawat-inap';
import PendaftaranRawatJalan from '@/pages/pendaftaran/rawat-jalan';

const inertiaMock = vi.hoisted(() => ({
    posts: [] as Array<{
        url: string;
        data: Record<string, unknown>;
    }>,
    cancellationErrors: {} as Record<string, string>,
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
                    inertiaMock.posts.push({ url, data: { ...data } });

                    if (
                        Object.keys(inertiaMock.cancellationErrors).length > 0
                    ) {
                        setErrors(inertiaMock.cancellationErrors);
                        options?.onError?.(inertiaMock.cancellationErrors);
                    } else {
                        options?.onSuccess?.();
                    }
                },
                reset: () => setDataState(initialData),
                clearErrors: () => setErrors({}),
                transform: vi.fn(),
            };
        },
        usePage: () => ({ props: { flash: {} } }),
    };
});

const registeredOutpatient = {
    public_id: 'encounter-rj-1',
    status: 'REGISTERED',
    clinic_name: 'Poli Umum',
    doctor_name: 'Dr. Pengajar',
    schedule_label: 'Pagi',
    payer_type: 'UMUM',
    queue_number: 7,
    registered_at: '2026-08-30T08:00:00+07:00',
    patient: {
        public_id: 'patient-rj-1',
        medical_record_number: 'SYNTH-RJ-001',
        full_name: 'Pasien Rawat Jalan Sintetis',
    },
};

const registeredInpatient = {
    public_id: 'encounter-ri-1',
    status: 'REGISTERED',
    ward_name: 'Bangsal Anggrek',
    ward_class: 'Kelas 1',
    bed_code: 'ANG-101-A',
    continue_from: 'LANGSUNG',
    payer_type: 'UMUM',
    queue_number: 3,
    registered_at: '2026-08-30T08:15:00+07:00',
    chief_complaint: 'Observasi sintetis',
    patient: {
        public_id: 'patient-ri-1',
        medical_record_number: 'SYNTH-RI-001',
        full_name: 'Pasien Rawat Inap Sintetis',
    },
};

function renderOutpatient(encounter = registeredOutpatient, canCancel = true) {
    return render(
        <PendaftaranRawatJalan
            q=""
            searchResults={[]}
            todaysEncounters={[encounter]}
            clinics={[]}
            sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
            maritalOptions={[]}
            religionOptions={[]}
            educationOptions={[]}
            occupationOptions={[]}
            ethnicityOptions={[]}
            languageOptions={[]}
            payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
            admissionOptions={[
                { value: 'DATANG_SENDIRI', label: 'Datang sendiri' },
            ]}
            wilayahProvinces={[]}
            canRegister
            canCancel={canCancel}
        />,
    );
}

function renderInpatient(
    encounter: typeof registeredInpatient & {
        cancellation?: {
            reason_code: string;
            note: string | null;
            cancelled_at: string | null;
            cancelled_by: string | null;
        } | null;
    } = registeredInpatient,
    canCancel = true,
) {
    return render(
        <PendaftaranRawatInap
            q=""
            searchResults={[]}
            todaysEncounters={[encounter]}
            wards={[
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
                    ],
                },
            ]}
            wardOptions={[
                { value: 'Bangsal Anggrek', label: 'Bangsal Anggrek' },
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
            canOpen
            canCancel={canCancel}
        />,
    );
}

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: {
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

describe('registration encounter cancellation UI', () => {
    beforeEach(() => {
        inertiaMock.posts = [];
        inertiaMock.cancellationErrors = {};
    });

    it('hides cancellation when the global capability is absent', () => {
        renderOutpatient(registeredOutpatient, false);

        expect(
            screen.queryByRole('button', { name: /Cancel visit/ }),
        ).not.toBeInTheDocument();
    });

    it('posts the exact CAN-04 payload from an accessible outpatient confirmation dialog and restores focus', async () => {
        const user = userEvent.setup();
        const { container } = renderOutpatient();
        const trigger = screen.getByRole('button', {
            name: 'Cancel visit for Pasien Rawat Jalan Sintetis',
        });

        await user.click(trigger);

        const dialog = screen.getByRole('dialog', {
            name: 'Cancel this visit before care begins?',
        });
        expect(dialog).toHaveTextContent('Poli Umum');
        expect(dialog).toHaveTextContent('Queue 007');
        expect(dialog).toHaveTextContent(
            'The queue number and registration data remain recorded in the visit history.',
        );

        const reason = within(dialog).getByRole('combobox', {
            name: 'Cancellation reason',
        });
        expect(within(reason).getAllByRole('option')).toHaveLength(5);
        await user.selectOptions(reason, 'DUPLIKAT_KUNJUNGAN');
        await user.type(
            within(dialog).getByRole('textbox', {
                name: /Cancellation note/,
            }),
            'Duplikat ditemukan sebelum pelayanan.',
        );
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Cancel visit',
            }),
        );

        expect(inertiaMock.posts).toHaveLength(1);
        expect(inertiaMock.posts[0]?.url).toBe(
            '/pendaftaran/kunjungan/encounter-rj-1/batalkan',
        );
        expect(inertiaMock.posts[0]?.data).toMatchObject({
            reason_code: 'DUPLIKAT_KUNJUNGAN',
            note: 'Duplikat ditemukan sebelum pelayanan.',
        });
        expect(inertiaMock.posts[0]?.data.idempotency_key).toEqual(
            expect.any(String),
        );
        expect(inertiaMock.posts[0]?.data.idempotency_key).not.toBe('');
        await waitFor(() => expect(trigger).toHaveFocus());

        await expectNoWcag21Violations(container);
    });

    it('associates server validation errors inside the dialog', async () => {
        const user = userEvent.setup();
        inertiaMock.cancellationErrors = {
            reason_code: 'A cancellation reason is required.',
        };
        renderOutpatient();

        await user.click(
            screen.getByRole('button', {
                name: 'Cancel visit for Pasien Rawat Jalan Sintetis',
            }),
        );
        const dialog = screen.getByRole('dialog');
        await user.selectOptions(
            within(dialog).getByRole('combobox', {
                name: 'Cancellation reason',
            }),
            'SALAH_PENDAFTARAN',
        );
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Cancel visit',
            }),
        );

        expect(
            within(dialog).getByRole('combobox', {
                name: 'Cancellation reason',
            }),
        ).toHaveAttribute('aria-invalid', 'true');
        expect(
            within(dialog).getByText('A cancellation reason is required.'),
        ).toHaveAttribute('id', 'cancellation-reason-error');
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'The cancellation could not be saved.',
        );
    });

    it('announces the server-provided cancellation error and keeps the dialog open', async () => {
        const user = userEvent.setup();
        inertiaMock.cancellationErrors = {
            cancellation:
                'Only registered visits that have not received care can be cancelled.',
        };
        renderOutpatient();

        await user.click(
            screen.getByRole('button', {
                name: 'Cancel visit for Pasien Rawat Jalan Sintetis',
            }),
        );
        const dialog = screen.getByRole('dialog');
        await user.selectOptions(
            within(dialog).getByRole('combobox', {
                name: 'Cancellation reason',
            }),
            'SALAH_PENDAFTARAN',
        );
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Cancel visit',
            }),
        );

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(within(dialog).getByRole('alert')).toHaveTextContent(
            'Only registered visits that have not received care can be cancelled.',
        );
    });

    it('keeps queue and bed history visible after inpatient cancellation while blocking active actions', () => {
        renderInpatient({
            ...registeredInpatient,
            status: 'CANCELLED',
            cancellation: {
                reason_code: 'PASIEN_TIDAK_MELANJUTKAN',
                note: 'Keluarga mengubah rencana sebelum pelayanan.',
                cancelled_at: '2026-08-30T08:20:00+07:00',
                cancelled_by: 'Petugas Registrasi',
            },
        });

        const register = screen.getByRole('table', {
            name: 'Inpatient registration list',
        });
        expect(screen.getByText('Cancelled')).toBeInTheDocument();
        expect(within(register).getByText('ANG-101-A')).toBeInTheDocument();
        expect(
            screen.getByText('Patient did not continue'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Petugas Registrasi', { exact: false }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'The bed is available again; placement history remains recorded.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Open clinical care/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Print/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Cancel visit for Pasien Rawat Inap Sintetis',
            }),
        ).toBeDisabled();
    });

    it('submits inpatient cancellation to the shared command and preserves bed context in the dialog', async () => {
        const user = userEvent.setup();
        renderInpatient();

        await user.click(
            screen.getByRole('button', {
                name: 'Cancel visit for Pasien Rawat Inap Sintetis',
            }),
        );
        const dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('Bangsal Anggrek · ANG-101-A');
        expect(dialog).toHaveTextContent(
            'The bed becomes available after the cancellation is successful.',
        );
        await user.selectOptions(
            within(dialog).getByRole('combobox', {
                name: 'Cancellation reason',
            }),
            'PERUBAHAN_RENCANA_SEBELUM_PELAYANAN',
        );
        await user.click(
            within(dialog).getByRole('button', {
                name: 'Cancel visit',
            }),
        );

        expect(inertiaMock.posts[0]).toMatchObject({
            url: '/pendaftaran/kunjungan/encounter-ri-1/batalkan',
            data: {
                reason_code: 'PERUBAHAN_RENCANA_SEBELUM_PELAYANAN',
                note: '',
            },
        });
    });
});
