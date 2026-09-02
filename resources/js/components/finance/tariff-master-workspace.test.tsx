import { fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { TariffMasterProps } from './tariff-master-types';
import { TariffMasterWorkspace } from './tariff-master-workspace';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    get: vi.fn(),
    errors: {} as Record<string, string>,
    flash: { success: null as string | null },
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: React.ComponentProps<'a'>) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: inertia.get },
    usePage: () => ({ props: { flash: inertia.flash } }),
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);

        return {
            data,
            setData: (key: keyof T, value: T[keyof T]) =>
                setDataState((current) => ({ ...current, [key]: value })),
            errors: inertia.errors,
            processing: false,
            post: inertia.post,
            patch: inertia.patch,
        };
    },
}));

const digest = 'a'.repeat(64);
const props: TariffMasterProps = {
    as_of_date: '2026-09-02',
    groups: [
        {
            public_id: '01GROUP0000000000000000000',
            code: 'JASA',
            display_name: 'Jasa Pelayanan',
            state: 'ACTIVE',
            version: 1,
            content_digest: digest,
            component_count: 1,
            actions: {
                history_url: '/group/history',
                revise_url: '/group/revise',
                retire_url: '/group/retire',
            },
        },
    ],
    components: [
        {
            public_id: '01COMP00000000000000000000',
            code: 'JASA_DOKTER',
            display_name: 'Jasa Dokter',
            state: 'ACTIVE',
            version: 1,
            content_digest: digest,
            description: null,
            terminology_label: null,
            tariff_count: 1,
            group: {
                public_id: '01GROUP0000000000000000000',
                code: 'JASA',
                display_name: 'Jasa Pelayanan',
            },
            actions: {
                history_url: '/component/history',
                revise_url: '/component/revise',
                retire_url: '/component/retire',
            },
        },
    ],
    catalogues: [
        {
            public_id: '01CAT000000000000000000000',
            code: 'UMUM',
            display_name: 'Katalog Umum',
            state: 'ACTIVE',
            version: 1,
            content_digest: digest,
            tariff_count: 1,
            actions: {
                history_url: '/catalogue/history',
                revise_url: '/catalogue/revise',
                retire_url: '/catalogue/retire',
            },
        },
    ],
    tariffs: [
        {
            public_id: '01TAR000000000000000000000',
            code: 'RJ_KONSUL',
            display_name: 'Konsultasi Rawat Jalan',
            state: 'ACTIVE',
            version: 2,
            content_digest: digest,
            latest_head_version: 2,
            latest_head_content_digest: digest,
            latest_head_state: 'ACTIVE',
            care_setting: 'OUTPATIENT',
            service_domain: 'GENERAL_SERVICE',
            reference_label: null,
            ward_class_label: null,
            amount_rupiah: 150000,
            effective_from: '2026-09-01',
            next_effective_from: null,
            is_effective: true,
            catalogue: {
                public_id: '01CAT000000000000000000000',
                code: 'UMUM',
                display_name: 'Katalog Umum',
            },
            component: {
                public_id: '01COMP00000000000000000000',
                code: 'JASA_DOKTER',
                display_name: 'Jasa Dokter',
            },
            actions: {
                history_url: '/tariff/history',
                revise_url: '/tariff/revise',
                retire_url: '/tariff/retire',
            },
        },
    ],
    history: null,
    permissions: { can_manage: true },
    commands: {
        create_group_url: '/group',
        create_component_url: '/component',
        create_catalogue_url: '/catalogue',
        create_tariff_url: '/tariff',
    },
    read_error: null,
};

describe('Tarif & Komponen Biaya workspace', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        inertia.errors = {};
        inertia.post.mockImplementation(
            (_url: string, options?: { onError?: () => void }) =>
                options?.onError?.(),
        );
        inertia.patch.mockImplementation(
            (_url: string, options?: { onError?: () => void }) =>
                options?.onError?.(),
        );
    });

    it('shows the deliberate empty state without a sample price', () => {
        render(
            <TariffMasterWorkspace
                {...props}
                groups={[]}
                components={[]}
                catalogues={[]}
                tariffs={[]}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Tarif & Komponen Biaya' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Tarif harus ditambahkan secara sengaja/),
        ).toBeInTheDocument();
        expect(screen.getByText(/Tidak ada harga bawaan/)).toBeInTheDocument();
        expect(screen.queryByText(/Rp\s?0/)).not.toBeInTheDocument();
    });

    it('renders semantic current-value tables, integer rupiah, history, and 44px actions', () => {
        render(<TariffMasterWorkspace {...props} />);
        expect(
            screen.getByRole('table', {
                name: 'Daftar tarif menurut tanggal berlaku',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText(/Rp\s?150\.000/)).toBeInTheDocument();
        const historyLink = screen.getByRole('link', {
            name: 'Buka riwayat RJ_KONSUL',
        });
        expect(historyLink).toHaveAttribute('href', '/tariff/history');
        expect(historyLink).toHaveClass('min-h-11', 'min-w-11');
    });

    it('keeps cashier access read-only', () => {
        render(
            <TariffMasterWorkspace
                {...props}
                permissions={{ can_manage: false }}
                commands={{
                    create_group_url: null,
                    create_component_url: null,
                    create_catalogue_url: null,
                    create_tariff_url: null,
                }}
            />,
        );
        expect(screen.getByText('Akses lihat-saja')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Tambah katalog/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Tambah versi RJ_KONSUL' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Buka riwayat RJ_KONSUL' }),
        ).toBeInTheDocument();
    });

    it('opens the steward tariff form with effective date and integer rupiah controls', () => {
        render(<TariffMasterWorkspace {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Tambah tarif/ }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText('Nilai rupiah')).toHaveAttribute(
            'step',
            '1',
        );
        expect(within(dialog).getByLabelText('Berlaku mulai')).toHaveAttribute(
            'type',
            'date',
        );
        expect(within(dialog).getByLabelText('Alasan')).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Simpan versi' }),
        ).toHaveClass('min-h-11');
        fireEvent.keyDown(dialog, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('filters by care setting and moves focus to the accessible error summary', () => {
        const { rerender } = render(<TariffMasterWorkspace {...props} />);
        fireEvent.change(screen.getByLabelText('Filter jenis layanan'), {
            target: { value: 'INPATIENT' },
        });
        expect(
            screen.queryByRole('rowheader', {
                name: /Konsultasi Rawat Jalan/,
            }),
        ).not.toBeInTheDocument();

        inertia.errors = { amount_rupiah: 'Nilai rupiah wajib diisi.' };
        rerender(<TariffMasterWorkspace {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /Tambah tarif/ }));
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Simpan versi',
            }),
        );
        expect(screen.getByRole('alert')).toHaveFocus();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Nilai rupiah wajib diisi.',
        );
    });

    it('shows immutable history with half-open effective periods', () => {
        render(
            <TariffMasterWorkspace
                {...props}
                history={{
                    kind: 'tariff',
                    code: 'RJ_KONSUL',
                    display_name: 'Konsultasi Rawat Jalan',
                    versions: [
                        {
                            public_id: '01VERSION00000000000000000',
                            version: 1,
                            state: 'ACTIVE',
                            effective_from: '2026-09-01',
                            effective_until: '2026-09-30',
                            display_name: 'Konsultasi Rawat Jalan',
                            amount_rupiah: 100000,
                            reason: 'Pembentukan awal',
                            authored_at: '2026-09-01 08:00',
                            authored_by: 'Pengelola Tarif',
                            content_digest: digest,
                        },
                    ],
                }}
            />,
        );
        expect(
            screen.getByRole('table', { name: 'Versi tetap RJ_KONSUL' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('2026-09-01 sampai sebelum 2026-09-30'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Rp\s?100\.000/)).toBeInTheDocument();
    });
});
