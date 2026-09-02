import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LaboratoryTariffMappingProps } from './laboratory-tariff-mapping-types';
import { LaboratoryTariffMappingWorkspace } from './laboratory-tariff-mapping-workspace';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    get: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
        href: string;
        children?: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: inertia.get },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);
        const submit = (
            method: 'post' | 'patch',
            url: string,
            options?: { onError?: () => void; onSuccess?: () => void },
        ) => {
            inertia[method](url, data, options);

            if (Object.keys(inertia.errors).length) {
                options?.onError?.();
            } else {
                options?.onSuccess?.();
            }
        };

        return {
            data,
            errors: inertia.errors,
            processing: false,
            setData: (field: keyof T, value: T[keyof T]) =>
                setDataState((current) => ({ ...current, [field]: value })),
            post: (url: string, options?: object) =>
                submit('post', url, options),
            patch: (url: string, options?: object) =>
                submit('patch', url, options),
        };
    },
}));

const source = {
    public_id: '01K00000000000000000000201',
    code: 'LAB-HB',
    display_name: 'Hemoglobin',
    specimen_type: 'Darah EDTA',
    component_count: 3,
    state: 'ACTIVE' as const,
    master_version_public_id: '01K00000000000000000000202',
    master_version: 5,
    master_content_digest: 'a'.repeat(64),
};

const tariff = {
    public_id: '01K00000000000000000000203',
    code: 'TRF-LAB-HB',
    display_name: 'Tarif pemeriksaan hemoglobin',
    care_setting: 'OUTPATIENT' as const,
    state: 'ACTIVE' as const,
    version_public_id: '01K00000000000000000000204',
    version: 4,
    content_digest: 'b'.repeat(64),
    amount_rupiah: 1,
    effective_from: '2026-08-01',
};

const binding = {
    public_id: '01K00000000000000000000205',
    state: 'ACTIVE' as const,
    version: 1,
    content_digest: 'c'.repeat(64),
    latest_head_version: 2,
    latest_head_content_digest: 'd'.repeat(64),
    latest_head_state: 'ACTIVE' as const,
    source,
    care_setting: 'OUTPATIENT' as const,
    tariff,
    effective_from: '2026-08-01',
    effective_until: null,
    actions: {
        history_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/01K/history',
        revise_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/01K',
        retire_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium/01K/nonaktifkan',
    },
};

const props: LaboratoryTariffMappingProps = {
    as_of_date: '2026-09-02',
    source_master_version: 'LABORATORY_EXAMINATION_MASTER_V1',
    source_master_content_digest: 'e'.repeat(64),
    source_trigger: {
        code: 'ORIGINAL_VERIFIED_RESULT_V1',
        label: 'Hasil asli berstatus VERIFIED pada verified_at adalah satu-satunya pemicu biaya.',
    },
    sources: [source],
    tariff_options: [tariff],
    mappings: [binding],
    gaps: [
        {
            source,
            care_setting: 'INPATIENT',
            reason_code: 'TARIF_BELUM_DIPETAKAN',
            reason_label: 'Tarif belum dipetakan',
            detail: 'Belum ada pemetaan tepat untuk rawat inap.',
        },
    ],
    history: {
        binding_public_id: binding.public_id,
        source,
        care_setting: 'OUTPATIENT',
        versions: [
            {
                public_id: '01K00000000000000000000206',
                version: 1,
                state: 'ACTIVE',
                effective_from: '2026-08-01',
                effective_until: null,
                tariff,
                reason: 'Pemetaan awal.',
                authored_at: '2026-08-01T08:00:00Z',
                authored_by: 'Pengelola Tarif',
                previous_content_digest: null,
                content_digest: 'f'.repeat(64),
            },
        ],
    },
    permissions: { can_manage: true },
    commands: {
        create_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-laboratorium',
    },
};

async function expectAccessible(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a'] },
    });
    expect(result.violations).toHaveLength(0);
}

function expectFortyFourPixelTargets(container: HTMLElement) {
    const targets = container.querySelectorAll<HTMLElement>(
        'button, a[href], input, select, textarea, summary',
    );
    expect(targets.length).toBeGreaterThan(0);

    for (const target of targets) {
        const touchClass =
            target instanceof HTMLInputElement && target.type === 'checkbox'
                ? target.closest('label')?.className
                : target.className;
        expect(touchClass, target.outerHTML).toMatch(/min-h-11/);
    }
}

describe('laboratory tariff mapping frontend contract', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.patch.mockReset();
        inertia.get.mockReset();
        inertia.errors = {};
    });

    it('shows the original VERIFIED rule, specimen identity, exact provenance, gaps, and history', async () => {
        const { container } = render(
            <LaboratoryTariffMappingWorkspace {...props} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Pemetaan Laboratorium' }),
        ).toBeVisible();
        expect(screen.getByText('Hanya hasil asli VERIFIED')).toBeVisible();
        expect(screen.getByText(/verified_at/)).toBeVisible();
        expect(
            screen.getAllByText('Darah EDTA · 3 komponen hasil'),
        ).toHaveLength(3);
        expect(
            screen.getAllByText(source.master_content_digest).length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('Tarif belum dipetakan')).toBeVisible();
        expect(
            screen.getByRole('table', {
                name: 'Riwayat versi pemetaan laboratorium tetap',
            }),
        ).toBeVisible();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('keeps tariff blank and submits a deliberate mapping with exact master evidence', async () => {
        const user = userEvent.setup();
        render(<LaboratoryTariffMappingWorkspace {...props} history={null} />);

        await user.click(screen.getByRole('button', { name: 'Buat pemetaan' }));
        expect(screen.getByLabelText('Tarif laboratorium')).toHaveValue('');
        expect(screen.getByText(/Tidak ada tarif atau nilai/)).toBeVisible();

        await user.selectOptions(
            screen.getByLabelText('Pemeriksaan dan versi master'),
            source.public_id,
        );
        await user.selectOptions(
            screen.getByLabelText('Jenis layanan'),
            'OUTPATIENT',
        );
        await user.selectOptions(
            screen.getByLabelText('Tarif laboratorium'),
            tariff.public_id,
        );
        await user.type(screen.getByLabelText('Berlaku mulai'), '2026-10-01');
        await user.type(
            screen.getByLabelText('Alasan'),
            'Pemetaan disetujui untuk rawat jalan.',
        );
        await user.click(screen.getByText(/Saya mengonfirmasi versi master/));
        await user.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            props.commands.create_url,
            expect.objectContaining({
                laboratory_master_public_id: source.public_id,
                laboratory_master_version_public_id:
                    source.master_version_public_id,
                laboratory_master_version: 5,
                laboratory_master_content_digest: source.master_content_digest,
                care_setting: 'OUTPATIENT',
                tariff_item_public_id: tariff.public_id,
                effective_from: '2026-10-01',
                confirm: true,
                idempotency_key: expect.stringMatching(/^laboratory-tariff-/),
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Pemetaan laboratorium dibuat.',
        );
    });

    it('supports keyboard dismissal and future revision and retirement with head evidence', async () => {
        const user = userEvent.setup();
        render(<LaboratoryTariffMappingWorkspace {...props} history={null} />);

        await user.click(screen.getByRole('button', { name: 'Buat pemetaan' }));
        expect(
            screen.getByRole('button', { name: 'Tutup formulir pemetaan' }),
        ).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', {
                name: /Tambah versi pemetaan LAB-HB Rawat jalan/,
            }),
        );
        await user.type(screen.getByLabelText('Berlaku mulai'), '2026-11-01');
        await user.type(
            screen.getByLabelText('Alasan'),
            'Perubahan terjadwal.',
        );
        await user.click(screen.getByText(/Saya mengonfirmasi versi master/));
        await user.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );
        expect(inertia.patch).toHaveBeenCalledWith(
            binding.actions.revise_url,
            expect.objectContaining({
                expected_version: 2,
                expected_digest: binding.latest_head_content_digest,
                effective_from: '2026-11-01',
            }),
            expect.any(Object),
        );

        await user.click(
            screen.getByRole('button', {
                name: /Nonaktifkan pemetaan LAB-HB Rawat jalan/,
            }),
        );
        await user.type(screen.getByLabelText('Nonaktif mulai'), '2026-12-01');
        await user.type(screen.getByLabelText('Alasan'), 'Layanan dihentikan.');
        await user.click(screen.getByText(/Saya mengonfirmasi versi master/));
        await user.click(
            screen.getByRole('button', { name: 'Jadwalkan nonaktif' }),
        );
        expect(inertia.post).toHaveBeenLastCalledWith(
            binding.actions.retire_url,
            expect.objectContaining({
                expected_version: 2,
                expected_digest: binding.latest_head_content_digest,
                effective_from: '2026-12-01',
            }),
            expect.any(Object),
        );
    });

    it('renders cashier read-only and honest empty and error states without sample prices', async () => {
        const { container } = render(
            <LaboratoryTariffMappingWorkspace
                {...props}
                mappings={[]}
                gaps={[]}
                history={null}
                permissions={{ can_manage: false }}
                commands={{ create_url: null }}
                read_error="Proyeksi belum dapat dibaca."
            />,
        );

        expect(screen.getByText('Akses lihat-saja')).toBeVisible();
        expect(
            screen.getByText(
                'Belum ada pemetaan tarif laboratorium yang dikonfigurasi secara sengaja.',
            ),
        ).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Proyeksi belum dapat dibaca.',
        );
        expect(
            screen.queryByRole('button', { name: /Buat|Tambah|Nonaktifkan/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/Rp\s?\d/)).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('moves focus to the error summary after a rejected create', async () => {
        inertia.errors = {
            mapping: 'Versi master berubah. Muat ulang sebelum menyimpan.',
        };
        const user = userEvent.setup();
        render(<LaboratoryTariffMappingWorkspace {...props} history={null} />);

        await user.click(screen.getByRole('button', { name: 'Buat pemetaan' }));
        await user.selectOptions(
            screen.getByLabelText('Pemeriksaan dan versi master'),
            source.public_id,
        );
        await user.selectOptions(
            screen.getByLabelText('Jenis layanan'),
            'OUTPATIENT',
        );
        await user.selectOptions(
            screen.getByLabelText('Tarif laboratorium'),
            tariff.public_id,
        );
        await user.type(screen.getByLabelText('Berlaku mulai'), '2026-10-01');
        await user.type(screen.getByLabelText('Alasan'), 'Pemetaan terjadwal.');
        await user.click(screen.getByText(/Saya mengonfirmasi versi master/));
        await user.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );

        expect(screen.getByRole('alert')).toHaveFocus();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Versi master berubah. Muat ulang sebelum menyimpan.',
        );
    });
});
