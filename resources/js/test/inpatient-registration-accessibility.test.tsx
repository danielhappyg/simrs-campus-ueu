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
        full_name: 'Full name is required.',
        bed_public_id:
            'The bed is already in use by an active inpatient visit.',
        chief_complaint: 'Chief complaint is required.',
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
            screen.getByRole('link', { name: 'View bed availability' }),
        ).toHaveAttribute('href', '/manajemen-data/bangsal');
        const saveButton = screen.getByRole('button', {
            name: 'Save inpatient registration',
        });
        const form = saveButton.closest('form');

        if (!form) {
            throw new Error('Inpatient registration form was not rendered.');
        }

        fireEvent.submit(form);

        const summary = await screen.findByRole('alert', {
            name: 'Inpatient registration cannot be saved yet.',
        });
        expect(inertiaMock.post).toHaveBeenCalledWith(
            '/pendaftaran/rawat-inap',
        );
        expect(inertiaMock.payload).toHaveBeenCalledWith(
            expect.objectContaining({
                bed_code: 'ANG-101-A',
                bed_public_id: 'bed-ang-101-a',
                admission_authority_type: 'PLANNED_ORDER',
                admission_authority_reference: '',
            }),
        );
        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(summary).toHaveFocus();

        const errorCases = [
            {
                id: 'full_name',
                control: screen.getByRole('textbox', { name: 'Full name' }),
                linkName: 'Full name: Full name is required.',
                message: 'Full name is required.',
            },
            {
                id: 'bed_code',
                control: screen.getByRole('combobox', {
                    name: 'Bed',
                }),
                linkName:
                    'Bed: The bed is already in use by an active inpatient visit.',
                message:
                    'The bed is already in use by an active inpatient visit.',
            },
            {
                id: 'chief_complaint',
                control: screen.getByRole('textbox', {
                    name: 'Chief complaint',
                }),
                linkName: 'Chief complaint: Chief complaint is required.',
                message: 'Chief complaint is required.',
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

        const validControl = screen.getByRole('textbox', { name: 'Phone' });
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

        fireEvent.change(screen.getByRole('combobox', { name: 'Bed' }), {
            target: { value: 'bed-ang-101-b' },
        });

        expect(screen.getByRole('textbox', { name: 'Class' })).toHaveValue(
            'Kelas 2',
        );

        fireEvent.change(
            screen.getByRole('textbox', {
                name: 'Admission authority reference number',
            }),
            { target: { value: 'ORDER-RI-SINTETIS-001' } },
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Save inpatient registration' }),
        );

        expect(inertiaMock.payload).toHaveBeenCalledWith(
            expect.objectContaining({
                ward_name: 'Bangsal Anggrek',
                ward_class: 'Kelas 2',
                bed_code: 'ANG-101-B',
                bed_public_id: 'bed-ang-101-b',
                admission_authority_type: 'PLANNED_ORDER',
                admission_authority_reference: 'ORDER-RI-SINTETIS-001',
            }),
        );
    });

    it('surfaces a pending IGD admission with an action into the guarded disposition handoff', () => {
        render(
            <PendaftaranRawatInap
                q=""
                searchResults={[]}
                todaysEncounters={[]}
                pendingEmergencyAdmissions={[
                    {
                        source_encounter_public_id: 'igd-episode-001',
                        disposition_public_id: 'igd-disposition-001',
                        disposition_version: 1,
                        signed_at: '2026-09-04T09:15:00+07:00',
                        payer_type: 'BPJS',
                        admission_reason: 'Memerlukan pemantauan lanjutan.',
                        patient: {
                            medical_record_number: '000123',
                            full_name: 'PASIEN IGD SINTETIS',
                        },
                        handoff_url:
                            '/pemeriksaan/igd/igd-episode-001?tab=disposition',
                    },
                ]}
                wards={defaultWards}
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
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Awaiting emergency handoff',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('PASIEN IGD SINTETIS')).toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Continue emergency handoff for PASIEN IGD SINTETIS',
            }),
        ).toHaveAttribute(
            'href',
            '/pemeriksaan/igd/igd-episode-001?tab=disposition',
        );
    });

    it('keeps outpatient admission pending until a registrar selects a currently available bed', async () => {
        render(
            <PendaftaranRawatInap
                q=""
                searchResults={[]}
                todaysEncounters={[]}
                pendingOutpatientAdmissions={[
                    {
                        source_encounter_public_id: 'rj-episode-001',
                        disposition_public_id: 'rj-disposition-001',
                        disposition_version: 2,
                        signed_at: '2026-09-05T09:15:00+07:00',
                        payer_type: 'UMUM',
                        admission_reason: 'Observasi lanjutan.',
                        patient: {
                            medical_record_number: '000124',
                            full_name: 'PASIEN RJ SINTETIS',
                        },
                        handoff_url:
                            '/pemeriksaan/rawat-jalan/rj-episode-001/disposition/handoff',
                    },
                ]}
                wards={defaultWards}
                wardOptions={[]}
                sexOptions={[{ value: 'male', label: 'Laki-laki' }]}
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
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Awaiting outpatient handoff',
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Select a bed and hand off outpatient care for PASIEN RJ SINTETIS',
            }),
        );
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'Observasi lanjutan.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Complete handoff' }),
        );
        expect(inertiaMock.post).toHaveBeenCalledWith(
            '/pemeriksaan/rawat-jalan/rj-episode-001/disposition/handoff',
        );
        expect(inertiaMock.payload).toHaveBeenLastCalledWith(
            expect.objectContaining({
                expected_disposition_version: 2,
                bed_public_id: 'bed-ang-101-a',
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
        expect(screen.getByRole('combobox', { name: 'Bed' })).toHaveValue(
            'bed-ang-b-301',
        );
        expect(screen.getByRole('textbox', { name: 'Class' })).toHaveValue(
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
        expect(screen.getByRole('combobox', { name: 'Bed' })).toHaveValue(
            'bed-mawar-201',
        );
        expect(screen.getByRole('textbox', { name: 'Class' })).toHaveValue(
            'Kelas VIP',
        );

        rerender(inpatientRegistrationPage([]));

        expect(
            screen.getByText(/No inpatient beds are available/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Save inpatient registration' }),
        ).toBeDisabled();
        await waitFor(() =>
            expect(screen.getByRole('textbox', { name: 'Class' })).toHaveValue(
                '',
            ),
        );
    });
});
