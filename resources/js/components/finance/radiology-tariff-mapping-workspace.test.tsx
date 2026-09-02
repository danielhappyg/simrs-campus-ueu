import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { RadiologyTariffMappingProps } from './radiology-tariff-mapping-types';
import { RadiologyTariffMappingWorkspace } from './radiology-tariff-mapping-workspace';

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
    public_id: '01K00000000000000000000101',
    code: 'RAD-THORAX',
    display_name: 'Radiografi toraks',
    state: 'ACTIVE' as const,
    master_version_public_id: '01K00000000000000000000102',
    master_version: 3,
    master_content_digest: 'a'.repeat(64),
};

const tariff = {
    public_id: '01K00000000000000000000103',
    code: 'TRF-RAD-THORAX',
    display_name: 'Tarif radiografi toraks',
    care_setting: 'OUTPATIENT' as const,
    state: 'ACTIVE' as const,
    version_public_id: '01K00000000000000000000104',
    version: 4,
    content_digest: 'b'.repeat(64),
    amount_rupiah: 1,
    effective_from: '2026-08-01',
};

const binding = {
    public_id: '01K00000000000000000000105',
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
            '/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/01K/history',
        revise_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/01K',
        retire_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi/01K/nonaktifkan',
    },
};

const props: RadiologyTariffMappingProps = {
    as_of_date: '2026-09-02',
    source_master_version: 'RADIOLOGY_EXAMINATION_MASTER_V1',
    source_master_content_digest: 'e'.repeat(64),
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
                public_id: '01K00000000000000000000106',
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
        create_url: '/manajemen-data/tarif-komponen-biaya/pemetaan-radiologi',
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

describe('radiology tariff mapping frontend contract', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.patch.mockReset();
        inertia.get.mockReset();
        inertia.errors = {};
    });

    it('shows exact master and tariff provenance, gaps, and immutable history', async () => {
        const { container } = render(
            <RadiologyTariffMappingWorkspace {...props} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Pemetaan Radiologi' }),
        ).toBeVisible();
        expect(
            screen.getAllByText(source.master_content_digest).length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByText(tariff.content_digest).length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('Tarif belum dipetakan')).toBeVisible();
        expect(
            screen.getByRole('table', {
                name: 'Riwayat versi pemetaan radiologi tetap',
            }),
        ).toBeVisible();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('starts empty without a suggested price and submits a deliberate mapping', async () => {
        const user = userEvent.setup();
        render(<RadiologyTariffMappingWorkspace {...props} history={null} />);

        await user.click(screen.getByRole('button', { name: 'Buat pemetaan' }));
        expect(screen.getByLabelText('Tarif radiologi')).toHaveValue('');
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
            screen.getByLabelText('Tarif radiologi'),
            tariff.public_id,
        );
        await user.type(screen.getByLabelText('Berlaku mulai'), '2026-10-01');
        await user.type(
            screen.getByLabelText('Alasan'),
            'Pemetaan disetujui untuk layanan rawat jalan.',
        );
        await user.click(screen.getByText(/Saya mengonfirmasi versi master/));
        await user.click(
            screen.getByRole('button', { name: 'Simpan pemetaan' }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            props.commands.create_url,
            expect.objectContaining({
                radiology_master_public_id: source.public_id,
                radiology_master_version_public_id:
                    source.master_version_public_id,
                radiology_master_version: 3,
                radiology_master_content_digest: source.master_content_digest,
                care_setting: 'OUTPATIENT',
                tariff_item_public_id: tariff.public_id,
                effective_from: '2026-10-01',
                confirm: true,
                idempotency_key: expect.stringMatching(/^radiology-tariff-/),
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Pemetaan radiologi dibuat.',
        );
    });

    it('supports future revision and retirement with head concurrency fields', async () => {
        const user = userEvent.setup();
        render(<RadiologyTariffMappingWorkspace {...props} history={null} />);

        await user.click(
            screen.getByRole('button', {
                name: /Tambah versi pemetaan RAD-THORAX Rawat jalan/,
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
                name: /Nonaktifkan pemetaan RAD-THORAX Rawat jalan/,
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

    it('renders a cashier-style read-only empty state without sample amounts', async () => {
        const { container } = render(
            <RadiologyTariffMappingWorkspace
                {...props}
                mappings={[]}
                gaps={[]}
                history={null}
                permissions={{ can_manage: false }}
                commands={{ create_url: null }}
            />,
        );

        expect(screen.getByText('Akses lihat-saja')).toBeVisible();
        expect(
            screen.getByText(
                'Belum ada pemetaan tarif radiologi yang dikonfigurasi secara sengaja.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Buat|Tambah|Nonaktifkan/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/Rp\s?\d/)).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('focuses the error summary after a rejected create', async () => {
        inertia.errors = {
            mapping: 'Versi master berubah. Muat ulang sebelum menyimpan.',
        };
        const user = userEvent.setup();
        render(<RadiologyTariffMappingWorkspace {...props} history={null} />);

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
            screen.getByLabelText('Tarif radiologi'),
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
