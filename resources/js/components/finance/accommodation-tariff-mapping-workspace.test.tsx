import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AccommodationTariffMappingProps } from './accommodation-tariff-mapping-types';
import { AccommodationTariffMappingWorkspace } from './accommodation-tariff-mapping-workspace';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
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
    public_id: '01K00000000000000000000301',
    code: 'BED-MELATI-03',
    display_name: 'Tempat tidur B-03',
    ward_code: 'WRD-MELATI',
    ward_display_name: 'Bangsal Melati',
    room_label: 'Kamar B',
    service_class_label: 'Kelas II',
    state: 'ACTIVE' as const,
    bed_version_public_id: '01K00000000000000000000302',
    bed_version: 3,
    bed_content_digest: 'a'.repeat(64),
};

const tariff = {
    public_id: '01K00000000000000000000303',
    code: 'TRF-AKM-MELATI',
    display_name: 'Akomodasi Bangsal Melati',
    state: 'ACTIVE' as const,
    version_public_id: '01K00000000000000000000304',
    version: 2,
    content_digest: 'b'.repeat(64),
    amount_rupiah: 1,
    effective_from: '2026-08-01',
};

const binding = {
    public_id: '01K00000000000000000000305',
    state: 'ACTIVE' as const,
    version: 1,
    content_digest: 'c'.repeat(64),
    latest_head_version: 1,
    latest_head_content_digest: 'd'.repeat(64),
    latest_head_state: 'ACTIVE' as const,
    source,
    tariff,
    effective_from: '2026-08-01',
    effective_until: null,
    actions: {
        history_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/01K/riwayat',
        revise_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/01K',
        retire_url:
            '/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi/01K/nonaktifkan',
    },
};

const props: AccommodationTariffMappingProps = {
    as_of_date: '2026-09-02',
    source_master_version: 'MANAGED_INPATIENT_WARD_BED_MASTER_V1',
    source_master_content_digest: 'e'.repeat(64),
    source_trigger: {
        code: 'CLOSED_OCCUPANCY_DAY_V1',
        label: 'Hanya interval okupansi tertutup dengan jangkar hari yang dapat menjadi sumber biaya.',
    },
    sources: [source],
    tariff_options: [tariff],
    mappings: [binding],
    gaps: [
        {
            source,
            reason_code: 'TARIF_BELUM_DIPETAKAN',
            reason_label: 'Tarif belum dipetakan',
            detail: 'Tidak ada pemetaan untuk versi tempat tidur lain.',
        },
    ],
    history: null,
    permissions: { can_manage: true },
    commands: {
        create_url: '/manajemen-data/tarif-komponen-biaya/pemetaan-akomodasi',
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

describe('accommodation tariff mapping frontend contract', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.patch.mockReset();
        inertia.errors = {};
    });

    it('shows only closed-day guidance, exact bed-version evidence, gaps, and no inferred price', async () => {
        const { container } = render(
            <AccommodationTariffMappingWorkspace {...props} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Inpatient Accommodation Mapping',
            }),
        ).toBeVisible();
        expect(screen.getByText('Closed occupancy days only')).toBeVisible();
        expect(screen.getAllByText('Bed-version evidence')).not.toHaveLength(0);
        expect(screen.getByText('Tarif belum dipetakan')).toBeVisible();
        expect(
            screen.getByRole('table', {
                name: 'Effective accommodation tariff mappings',
            }),
        ).toBeVisible();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('submits a deliberate exact bed-version mapping without a generated amount', async () => {
        const user = userEvent.setup();
        render(
            <AccommodationTariffMappingWorkspace
                {...props}
                mappings={[]}
                history={null}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Create mapping' }),
        );
        expect(screen.getByLabelText('Accommodation tariff')).toHaveValue('');
        expect(
            screen.getByText(/The system does not suggest a tariff or value/),
        ).toBeVisible();
        await user.selectOptions(
            screen.getByLabelText('Bed and master version'),
            source.public_id,
        );
        await user.selectOptions(
            screen.getByLabelText('Accommodation tariff'),
            tariff.public_id,
        );
        await user.type(screen.getByLabelText('Effective from'), '2026-10-01');
        await user.type(
            screen.getByLabelText('Reason'),
            'Pemetaan akomodasi dijadwalkan.',
        );
        await user.click(screen.getByText(/I confirm the exact bed version/));
        await user.click(screen.getByRole('button', { name: 'Save mapping' }));

        expect(inertia.post).toHaveBeenCalledWith(
            props.commands.create_url,
            expect.objectContaining({
                inpatient_bed_public_id: source.public_id,
                inpatient_bed_version_public_id: source.bed_version_public_id,
                inpatient_bed_version: 3,
                inpatient_bed_content_digest: source.bed_content_digest,
                tariff_item_public_id: tariff.public_id,
                effective_from: '2026-10-01',
                confirm: true,
                idempotency_key: expect.stringMatching(
                    /^accommodation-tariff-/,
                ),
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Accommodation mapping created.',
        );
    });

    it('keeps a cashier projection read-only and honest when mappings are empty', async () => {
        const { container } = render(
            <AccommodationTariffMappingWorkspace
                {...props}
                mappings={[]}
                gaps={[]}
                permissions={{ can_manage: false }}
                commands={{ create_url: null }}
                read_error="Proyeksi belum dapat dibaca."
            />,
        );

        expect(screen.getByText('Read-only access')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Proyeksi belum dapat dibaca.',
        );
        expect(
            screen.getByText(
                'No accommodation tariff mappings have been configured.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: /Create mapping|Add version|Deactivate/,
            }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/Rp\s?\d/)).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });
});
