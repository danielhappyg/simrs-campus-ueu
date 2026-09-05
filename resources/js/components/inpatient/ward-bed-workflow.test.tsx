import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    InpatientWardProjection,
    WardBedCensusProps,
} from '@/components/inpatient/ward-bed-types';
import ManajemenDataBangsal from '@/pages/manajemen-data/bangsal';

const inertiaMock = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
    errors: {} as Record<string, string>,
    failSubmissions: false,
    flash: { success: null, error: null } as {
        success: string | null;
        error: string | null;
    },
    capabilities: [] as string[],
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string;
        children?: ReactNode;
        prefetch?: boolean;
    }) => {
        const attributes = { ...props };
        delete attributes.prefetch;

        return (
            <a href={href} {...attributes}>
                {children}
            </a>
        );
    },
    router: {
        get: inertiaMock.get,
        reload: inertiaMock.reload,
    },
    usePage: () => ({
        props: {
            flash: inertiaMock.flash,
            auth: { capabilities: inertiaMock.capabilities },
        },
    }),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);
        const setData = (fieldOrValues: keyof T | T, value?: T[keyof T]) => {
            if (typeof fieldOrValues === 'object') {
                setDataState(fieldOrValues);

                return;
            }

            setDataState((current) => ({
                ...current,
                [fieldOrValues]: value,
            }));
        };
        const optionsResult = (
            method: 'post' | 'patch',
            url: string,
            options?: {
                onSuccess?: () => void;
                onError?: (errors: Record<string, string>) => void;
            },
        ) => {
            inertiaMock[method](url, data);

            if (inertiaMock.failSubmissions) {
                options?.onError?.(inertiaMock.errors);
            } else {
                options?.onSuccess?.();
            }
        };

        return {
            data,
            errors: inertiaMock.errors,
            processing: false,
            setData,
            clearErrors: vi.fn(),
            post: (url: string, options?: object) =>
                optionsResult('post', url, options),
            patch: (url: string, options?: object) =>
                optionsResult('patch', url, options),
        };
    },
}));

const wards: InpatientWardProjection[] = [
    {
        public_id: 'ward-melati',
        code: 'MELATI',
        display_name: 'Bangsal Melati',
        state: 'ACTIVE',
        version: 2,
        summary: {
            active_beds: 2,
            occupied_beds: 1,
            available_beds: 1,
        },
        actions: {
            update_url: '/manajemen-data/bangsal/wards/ward-melati',
            retire_url: '/manajemen-data/bangsal/wards/ward-melati/retire',
            create_bed_url: '/manajemen-data/bangsal/wards/ward-melati/beds',
        },
        beds: [
            {
                public_id: 'bed-a-01',
                code: 'A-01',
                display_name: 'Tempat Tidur A-01',
                room_label: 'Ruang 101',
                service_class: 'Kelas 1',
                state: 'ACTIVE',
                version: 1,
                occupancy: {
                    state: 'AVAILABLE',
                    occupant: null,
                },
                actions: {
                    update_url: '/manajemen-data/bangsal/beds/bed-a-01',
                    retire_url: '/manajemen-data/bangsal/beds/bed-a-01/retire',
                },
            },
            {
                public_id: 'bed-a-02',
                code: 'A-02',
                display_name: 'Tempat Tidur A-02',
                room_label: 'Ruang 101',
                service_class: 'Kelas 1',
                state: 'ACTIVE',
                version: 3,
                occupancy: {
                    state: 'OCCUPIED',
                    occupant: null,
                },
                actions: {
                    update_url: '/manajemen-data/bangsal/beds/bed-a-02',
                    retire_url: '/manajemen-data/bangsal/beds/bed-a-02/retire',
                },
            },
        ],
    },
];

const baseProps: WardBedCensusProps = {
    generated_at: '2026-08-30T10:00:00+07:00',
    filters: {
        q: '',
        ward_code: '',
        service_class: '',
        occupancy_state: '',
        master_state: '',
    },
    totals: {
        active_wards: 1,
        active_beds: 2,
        occupied_beds: 1,
        available_beds: 1,
    },
    wards,
    permissions: {
        can_view_census: true,
        can_manage_master: false,
    },
    commands: {
        create_ward_url: null,
    },
    filter_options: {
        wards: [{ value: 'MELATI', label: 'Bangsal Melati · MELATI' }],
        service_classes: [{ value: 'Kelas 1', label: 'Kelas 1' }],
    },
    reason_options: [
        { value: 'INITIAL_SETUP', label: 'Penyiapan awal' },
        { value: 'DATA_CORRECTION', label: 'Koreksi data' },
        { value: 'OPERATIONAL_CHANGE', label: 'Perubahan operasional' },
        { value: 'RETIREMENT', label: 'Penonaktifan' },
    ],
};

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

describe('managed inpatient ward and bed UI', () => {
    beforeEach(() => {
        inertiaMock.get.mockReset();
        inertiaMock.reload.mockReset();
        inertiaMock.post.mockReset();
        inertiaMock.patch.mockReset();
        inertiaMock.errors = {};
        inertiaMock.failSubmissions = false;
        inertiaMock.flash = { success: null, error: null };
        inertiaMock.capabilities = [];
    });

    it('renders a semantic read-only census without mutation controls or hidden occupant details', async () => {
        const { container } = render(<ManajemenDataBangsal {...baseProps} />);

        expect(
            screen.getByRole('heading', {
                name: 'Wards & Beds',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Active wards').nextSibling).toHaveTextContent(
            '1',
        );
        expect(
            screen.getByRole('table', {
                name: /Wards, beds, record status/i,
            }),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Available').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Occupied').length).toBeGreaterThan(0);
        expect(
            screen.getByText(
                'Encounter details are unavailable for this role.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add Ward' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();

        await expectNoWcag21Violations(container);
    });

    it('shows the triage vocabulary entry only with its exact admin capability', () => {
        const { rerender } = render(<ManajemenDataBangsal {...baseProps} />);

        expect(
            screen.queryByRole('link', { name: 'Emergency triage vocabulary' }),
        ).not.toBeInTheDocument();

        inertiaMock.capabilities = ['master.emergency.triage.manage'];
        rerender(<ManajemenDataBangsal {...baseProps} />);

        expect(
            screen.getByRole('link', { name: 'Emergency triage vocabulary' }),
        ).toHaveAttribute('href', '/manajemen-data/triage');
    });

    it('requires both manage permission and a non-null URL before showing mutation controls', () => {
        const noUrls = wards.map((ward) => ({
            ...ward,
            actions: {
                update_url: null,
                retire_url: null,
                create_bed_url: null,
            },
            beds: ward.beds.map((bed) => ({
                ...bed,
                actions: { update_url: null, retire_url: null },
            })),
        }));

        render(
            <ManajemenDataBangsal
                {...baseProps}
                wards={noUrls}
                permissions={{
                    can_view_census: true,
                    can_manage_master: true,
                }}
                commands={{ create_ward_url: null }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Add Ward' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Add Bed' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Edit' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Retire' }),
        ).not.toBeInTheDocument();
    });

    it('creates a ward through the projected URL and restores focus after success', async () => {
        const user = userEvent.setup();
        render(
            <ManajemenDataBangsal
                {...baseProps}
                permissions={{
                    can_view_census: true,
                    can_manage_master: true,
                }}
                commands={{
                    create_ward_url: '/manajemen-data/bangsal/wards',
                }}
            />,
        );

        const trigger = screen.getByRole('button', { name: 'Add Ward' });
        await user.click(trigger);
        expect(
            screen.getByRole('dialog', { name: 'Add ward' }),
        ).toBeInTheDocument();

        await user.type(screen.getByRole('textbox', { name: 'Code' }), 'mwr');
        await user.type(
            screen.getByRole('textbox', { name: 'Display name' }),
            'Bangsal Mawar',
        );
        await user.click(screen.getByRole('button', { name: 'Save ward' }));

        expect(inertiaMock.post).toHaveBeenCalledWith(
            '/manajemen-data/bangsal/wards',
            expect.objectContaining({
                code: 'MWR',
                display_name: 'Bangsal Mawar',
                reason_code: 'INITIAL_SETUP',
            }),
        );
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(screen.getByRole('status', { hidden: true })).toHaveTextContent(
            'Ward added successfully.',
        );
    });

    it('focuses a linked error summary again on repeated failures', async () => {
        inertiaMock.errors = {
            display_name: 'Nama tampilan wajib diisi.',
            master: 'Versi data sudah berubah. Muat ulang halaman.',
        };
        inertiaMock.failSubmissions = true;
        const user = userEvent.setup();

        render(
            <ManajemenDataBangsal
                {...baseProps}
                permissions={{
                    can_view_census: true,
                    can_manage_master: true,
                }}
                commands={{
                    create_ward_url: '/manajemen-data/bangsal/wards',
                }}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Add Ward' }));
        const save = screen.getByRole('button', { name: 'Save ward' });
        await user.click(save);

        const summary = screen.getByRole('alert', {
            name: 'Changes could not be saved.',
        });
        expect(summary).toHaveFocus();
        expect(
            within(summary).getByRole('link', {
                name: 'Display name: Nama tampilan wajib diisi.',
            }),
        ).toHaveAttribute('href', '#ward-bed-display_name');

        save.focus();
        fireEvent.click(save);
        await waitFor(() => expect(summary).toHaveFocus());
        expect(inertiaMock.post).toHaveBeenCalledTimes(2);
    });

    it('blocks retirement controls for occupied beds and wards with active children', () => {
        render(
            <ManajemenDataBangsal
                {...baseProps}
                permissions={{
                    can_view_census: true,
                    can_manage_master: true,
                }}
                commands={{
                    create_ward_url: '/manajemen-data/bangsal/wards',
                }}
            />,
        );

        const retireButtons = screen.getAllByRole('button', {
            name: 'Retire',
        });
        expect(
            retireButtons.filter((button) => button.hasAttribute('disabled')),
        ).toHaveLength(2);
        expect(document.body).toHaveTextContent(
            'Currently occupied; cannot be deactivated.',
        );
    });

    it('submits exact census filters and offers a clear-filter empty state', async () => {
        const user = userEvent.setup();
        render(
            <ManajemenDataBangsal
                {...baseProps}
                filters={{
                    ...baseProps.filters,
                    q: 'tidak ada',
                }}
                wards={[]}
                totals={{
                    active_wards: 0,
                    active_beds: 0,
                    occupied_beds: 0,
                    available_beds: 0,
                }}
            />,
        );

        expect(
            screen.getByText('No beds match these filters.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Bangsal Melati · MELATI' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Kelas 1' }),
        ).toBeInTheDocument();
        expect(
            within(screen.getByLabelText('Bed status')).getByRole('option', {
                name: 'Retired',
            }),
        ).toHaveValue('RETIRED');
        await user.click(
            screen.getAllByRole('button', { name: 'Clear filters' })[0],
        );

        expect(inertiaMock.get).toHaveBeenCalledWith(
            '/manajemen-data/bangsal',
            {},
            expect.objectContaining({ preserveState: true, replace: true }),
        );
    });

    it('shows and announces the server replay message instead of local success copy', () => {
        inertiaMock.flash = {
            success: 'Permintaan ini sudah diproses.',
            error: null,
        };

        render(<ManajemenDataBangsal {...baseProps} />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Permintaan ini sudah diproses.',
        );
    });

    it('does not present failed reads as zero occupancy', () => {
        render(
            <ManajemenDataBangsal
                {...baseProps}
                wards={[]}
                totals={{
                    active_wards: 0,
                    active_beds: 0,
                    occupied_beds: 0,
                    available_beds: 0,
                }}
                read_error="Basis data belum dapat dibaca."
            />,
        );

        const alert = screen.getByRole('alert');
        expect(alert).toHaveTextContent(
            'Availability data could not be loaded.',
        );
        expect(alert).toHaveTextContent('Basis data belum dapat dibaca.');
        expect(screen.queryByText('Active beds')).not.toBeInTheDocument();
    });
});
