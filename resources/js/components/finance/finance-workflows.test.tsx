import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FinanceBillDetail } from './finance-bill-detail';
import { FinanceWorklist } from './finance-worklist';
import type { FinanceBillDetailProps, FinanceWorklistProps } from './types';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    routerPost: vi.fn(),
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
    router: { post: inertia.routerPost },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = useState(initial);

        return {
            data,
            errors: inertia.errors,
            processing: false,
            setData: (field: keyof T, value: T[keyof T]) =>
                setDataState((current) => ({
                    ...current,
                    [field]: value,
                })),
            reset: (...fields: (keyof T)[]) =>
                setDataState((current) => {
                    const next = { ...current };

                    for (const field of fields) {
                        next[field] = initial[field];
                    }

                    return next;
                }),
            post: (
                url: string,
                options?: {
                    onError?: () => void;
                    onSuccess?: () => void;
                },
            ) => {
                inertia.post(url, data, options);

                if (Object.keys(inertia.errors).length) {
                    options?.onError?.();
                } else {
                    options?.onSuccess?.();
                }
            },
        };
    },
}));

const worklist: FinanceWorklistProps = {
    definition_version: 'CROSS_SETTING_VERSIONED_ENCOUNTER_BILL_V1',
    generated_at: '1 Sep 2026, 14.10',
    coverage: {
        profile: 'PHARMACY_HANDOVER_RETURN_ONLY_V1',
        label: 'Obat yang telah diserahkan dan retur terkait',
        excluded_label:
            'Layanan, tindakan, kamar, pemeriksaan penunjang, pembayaran, dan klaim.',
        domains: [{ domain: 'PHARMACY', label: 'Apotek' }],
    },
    bills: [
        {
            public_id: '01K00000000000000000000001',
            bill_number: 'TAG-01K00000000000000000000002',
            state: 'NEW_SOURCE_PENDING',
            current_version: 1,
            current_source_event_count: 2,
            pending_source_count: 0,
            synchronization_available: false,
            latest_source_at: '2026-09-01T06:30:00Z',
            fingerprint: '',
            encounter: {
                public_id: '01K00000000000000000000002',
                encounter_number: '01K00000000000000000000002',
                care_setting: 'INPATIENT',
                service_location: 'Bangsal Melati · B-03',
                patient: {
                    public_id: '01K00000000000000000000003',
                    full_name: 'Pasien Pendidikan',
                    medical_record_number: 'RM-000014',
                },
            },
            totals: {
                gross_amount: 36000,
                reversal_amount: -6000,
                net_amount: 30000,
            },
            source_readiness: {
                resolved_count: 0,
                unresolved_count: 1,
                issue_blocked: true,
                issue_blocker:
                    'Satu pemeriksaan radiologi belum memiliki pemetaan tarif efektif.',
                items: [
                    {
                        public_id: '01K00000000000000000000018',
                        source_domain: 'RADIOLOGY',
                        source_reference: '01K00000000000000000000019',
                        description: 'Radiografi toraks',
                        service_at: '2026-09-01T06:15:00Z',
                        state: 'TARIF_BELUM_DIPETAKAN',
                        state_label: 'Tarif belum dipetakan',
                        detail: 'Pemetaan tepat belum tersedia.',
                    },
                ],
            },
            actions: {
                show_url: '/kasir/tagihan/01K00000000000000000000002',
                synchronize_url: null,
            },
        },
    ],
    synchronization_candidates: [],
};

const detail: FinanceBillDetailProps = {
    ...worklist,
    coverage: {
        profile: 'PHARMACY_RADIOLOGY_AND_LABORATORY_TARIFF_SOURCE_V1',
        label: 'Obat yang diserahkan atau diretur, pemeriksaan radiologi selesai, dan hasil laboratorium terverifikasi bertarif',
        excluded_label:
            'Akomodasi, tindakan lain, pembayaran, klaim, dan sumber yang belum direkonsiliasi.',
        domains: [
            { domain: 'PHARMACY', label: 'Apotek' },
            { domain: 'RADIOLOGY', label: 'Radiologi' },
            { domain: 'LABORATORY', label: 'Laboratorium' },
        ],
    },
    bill: {
        ...worklist.bills[0],
        fingerprint: 'a'.repeat(64),
        current_version: 0,
        current_source_event_count: 4,
        state: 'OPEN_NO_VERSION',
        totals: {
            gross_amount: 111001,
            reversal_amount: -6000,
            net_amount: 105001,
        },
        source_readiness: {
            resolved_count: 2,
            unresolved_count: 0,
            issue_blocked: false,
            issue_blocker: null,
            items: [
                {
                    public_id: '01K00000000000000000000018',
                    source_domain: 'RADIOLOGY',
                    source_reference: '01K00000000000000000000019',
                    description: 'Radiografi toraks',
                    service_at: '2026-09-01T06:15:00Z',
                    state: 'TERSINKRONISASI',
                    state_label: 'Tersinkronisasi',
                    detail: 'Sumber dan baris biaya telah direkonsiliasi.',
                },
                {
                    public_id: '01K00000000000000000000028',
                    source_domain: 'LABORATORY',
                    source_reference: '01K00000000000000000000029',
                    description: 'Darah lengkap',
                    service_at: '2026-09-01T06:20:00Z',
                    state: 'TERSINKRONISASI',
                    state_label: 'Tersinkronisasi',
                    detail: 'Sumber dan baris biaya telah direkonsiliasi.',
                },
            ],
        },
        current_sources: [
            {
                public_id: '01K00000000000000000000004',
                source_domain: 'PHARMACY',
                source_reference: '01K00000000000000000000005',
                event_type: 'CHARGE',
                description: 'Parasetamol 500 mg · lot LOT-01',
                quantity: 6,
                unit_amount: 6000,
                signed_amount: 36000,
                occurred_at: '2026-09-01T06:00:00Z',
                tariff_provenance: null,
            },
            {
                public_id: '01K00000000000000000000006',
                source_domain: 'PHARMACY',
                source_reference: '01K00000000000000000000007',
                event_type: 'REVERSAL',
                description: 'Retur Parasetamol 500 mg · lot LOT-01',
                quantity: 1,
                unit_amount: 6000,
                signed_amount: -6000,
                occurred_at: '2026-09-01T06:30:00Z',
                tariff_provenance: null,
            },
            {
                public_id: '01K00000000000000000000020',
                source_domain: 'RADIOLOGY',
                source_reference: '01K00000000000000000000021',
                event_type: 'CHARGE',
                description: 'Radiografi toraks',
                quantity: 1,
                unit_amount: 1,
                signed_amount: 1,
                occurred_at: '2026-09-01T06:15:00Z',
                tariff_provenance: {
                    binding_public_id: '01K00000000000000000000022',
                    binding_version_public_id: '01K00000000000000000000023',
                    binding_version: 2,
                    binding_content_digest: 'b'.repeat(64),
                    radiology_master_version_public_id:
                        '01K00000000000000000000024',
                    radiology_master_version: 3,
                    radiology_master_code: 'RAD-THORAX',
                    radiology_master_content_digest: 'c'.repeat(64),
                    tariff_item_public_id: '01K00000000000000000000025',
                    tariff_item_version_public_id: '01K00000000000000000000026',
                    tariff_item_version: 4,
                    tariff_code: 'TRF-RAD-THORAX',
                    tariff_content_digest: 'd'.repeat(64),
                    component_public_id: '01K00000000000000000000027',
                    component_code: 'RADIOLOGY_SERVICE',
                    component_content_digest: 'e'.repeat(64),
                    service_date: '2026-09-01',
                    effective_from: '2026-08-01',
                },
            },
            {
                public_id: '01K00000000000000000000030',
                source_domain: 'LABORATORY',
                source_reference: '01K00000000000000000000031',
                event_type: 'CHARGE',
                description:
                    'Pemeriksaan laboratorium terverifikasi: Darah lengkap',
                quantity: 1,
                unit_amount: 75000,
                signed_amount: 75000,
                occurred_at: '2026-09-01T06:20:00Z',
                tariff_provenance: {
                    binding_public_id: '01K00000000000000000000032',
                    binding_version_public_id: '01K00000000000000000000033',
                    binding_version: 1,
                    binding_content_digest: 'f'.repeat(64),
                    laboratory_result_public_id: '01K00000000000000000000034',
                    laboratory_result_version: 2,
                    laboratory_result_content_digest: '1'.repeat(64),
                    laboratory_verified_at: '2026-09-01T06:20:00Z',
                    laboratory_specimen_public_id: '01K00000000000000000000035',
                    laboratory_master_version_public_id:
                        '01K00000000000000000000036',
                    laboratory_master_version: 4,
                    laboratory_master_code: 'LAB-CBC',
                    laboratory_master_content_digest: '2'.repeat(64),
                    tariff_item_public_id: '01K00000000000000000000037',
                    tariff_item_version_public_id: '01K00000000000000000000038',
                    tariff_item_version: 3,
                    tariff_code: 'TRF-LAB-CBC',
                    tariff_content_digest: '3'.repeat(64),
                    component_public_id: '01K00000000000000000000039',
                    component_code: 'LABORATORY_SERVICE',
                    component_content_digest: '4'.repeat(64),
                    service_date: '2026-09-01',
                    effective_from: '2026-08-01',
                },
            },
        ],
        versions: [],
    },
    settlement: {
        bill_public_id: '01K00000000000000000000001',
        bill_number: 'TAG-01K00000000000000000000002',
        bill_state: 'OPEN_NO_VERSION',
        bill_fingerprint: 'a'.repeat(64),
        bill_version_public_id: null,
        bill_version: null,
        bill_version_content_digest: null,
        amount: null,
        payment_method: 'CASH',
        settlement_available: false,
        settlement: null,
    },
    permissions: {
        can_issue: true,
        can_settle: false,
        can_request_correction: false,
    },
    commands: {
        issue_url: '/kasir/tagihan/01K00000000000000000000002/terbitkan',
        settlement_url: null,
        correction_request_url: null,
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
        expect(touchClass, target.outerHTML).toMatch(/min-h-(?:11|20)/);
    }
}

describe('finance cashier frontend contracts', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.routerPost.mockReset();
        inertia.errors = {};
    });

    it('renders the cashier worklist as a semantic reconciliation table with explicit pharmacy-only coverage', async () => {
        const { container } = render(<FinanceWorklist {...worklist} />);

        expect(
            screen.getByRole('heading', { name: 'Daftar Tagihan' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('complementary', {
                name: 'Cakupan sumber biaya',
            }),
        ).toHaveTextContent('Obat yang telah diserahkan dan retur terkait');
        expect(
            screen.getByRole('table', {
                name: /Daftar tagihan episode pasien/i,
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Ada sumber biaya baru')).toBeInTheDocument();
        expect(
            screen.getByText(
                (content) => content.replace(/\s/g, '') === 'Rp30.000',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('columnheader', { name: 'Neto' }),
        ).toBeVisible();
        expect(
            screen.getByRole('columnheader', {
                name: 'Kesiapan Sumber Biaya',
            }),
        ).toBeVisible();
        expect(
            screen.getByRole('list', { name: 'Status kesiapan sumber' }),
        ).toHaveTextContent(
            'Radiologi · Radiografi toraks — Tarif belum dipetakan',
        );
        expect(screen.getByText('Penerbitan tertahan')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /bayar|klaim|lunas/i }),
        ).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('synchronizes a first-use candidate only through the dedicated POST action', async () => {
        const user = userEvent.setup();
        render(
            <FinanceWorklist
                {...worklist}
                bills={[]}
                synchronization_candidates={[
                    {
                        encounter_public_id: '01K00000000000000000000008',
                        care_setting: 'EMERGENCY',
                        encounter_status: 'IN_EXAMINATION',
                        location_label: 'IGD',
                        patient: {
                            public_id: '01K00000000000000000000009',
                            full_name: 'Pasien IGD Pendidikan',
                            medical_record_number: 'RM-000015',
                        },
                        source_event_count: 1,
                        gross_amount: 12000,
                        reversal_amount: 0,
                        net_amount: 12000,
                        latest_source_at: '2026-09-01T07:00:00Z',
                        source_readiness: {
                            resolved_count: 1,
                            unresolved_count: 1,
                            issue_blocked: true,
                            issue_blocker:
                                'Satu pemeriksaan masih belum terselesaikan.',
                            items: [],
                        },
                        synchronize_url:
                            '/kasir/episode/01K00000000000000000000008/sumber-biaya/sinkronkan',
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('table', {
                name: /Episode dengan sumber biaya valid/i,
            }),
        ).toBeInTheDocument();
        await user.click(
            screen.getByRole('button', {
                name: /Sinkronkan sumber valid episode/i,
            }),
        );

        expect(inertia.routerPost).toHaveBeenCalledWith(
            '/kasir/episode/01K00000000000000000000008/sumber-biaya/sinkronkan',
            {
                idempotency_key: expect.stringMatching(/^finance-sync-/),
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('shows a laboratory readiness blocker as a closed, attributable state', async () => {
        const { container } = render(
            <FinanceWorklist
                {...worklist}
                bills={[
                    {
                        ...worklist.bills[0],
                        source_readiness: {
                            resolved_count: 0,
                            unresolved_count: 1,
                            issue_blocked: true,
                            issue_blocker:
                                'Bukti hasil asli laboratorium tidak konsisten.',
                            items: [
                                {
                                    public_id: '01K00000000000000000000028',
                                    source_domain: 'LABORATORY',
                                    source_reference:
                                        '01K00000000000000000000029',
                                    description: 'Darah lengkap',
                                    service_at: '2026-09-01T06:20:00Z',
                                    state: 'BUKTI_TIDAK_KONSISTEN',
                                    state_label: 'Bukti tidak konsisten',
                                    detail: 'Digest hasil asli tidak cocok.',
                                },
                            ],
                        },
                    },
                ]}
            />,
        );

        expect(
            screen.getByRole('list', { name: 'Status kesiapan sumber' }),
        ).toHaveTextContent(
            'Laboratorium · Darah lengkap — Bukti tidak konsisten',
        );
        expect(screen.getByText('Penerbitan tertahan')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Sinkronkan sumber valid/ }),
        ).not.toBeInTheDocument();

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('requires deliberate confirmation and submits the exact issue payload', async () => {
        const user = userEvent.setup();
        const { container } = render(<FinanceBillDetail {...detail} />);

        const issueButton = screen.getByRole('button', {
            name: 'Terbitkan versi 1',
        });
        expect(issueButton).toBeDisabled();

        await user.type(
            screen.getByLabelText('Alasan penerbitan'),
            'Pemeriksaan sumber biaya Apotek selesai.',
        );
        await user.click(
            screen.getByText(/Saya mengonfirmasi bahwa versi ini/i),
        );
        expect(issueButton).toBeEnabled();
        await user.click(issueButton);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post).toHaveBeenCalledWith(
            detail.commands.issue_url,
            expect.objectContaining({
                expected_fingerprint: 'a'.repeat(64),
                issue_reason: 'Pemeriksaan sumber biaya Apotek selesai.',
                confirm_issue: true,
                idempotency_key: expect.stringMatching(/^finance-issue-/),
            }),
            expect.any(Object),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Versi 1 diterbitkan.',
        );
        expect(
            screen.getByRole('table', {
                name: 'Sumber biaya Apotek, Radiologi, Laboratorium, dan Akomodasi terkini',
            }),
        ).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', {
                    name: 'Kesiapan Sumber Biaya',
                }),
            ).getByText('2'),
        ).toBeVisible();
        expect(screen.getByText('Pemeriksaan radiologi')).toBeVisible();
        expect(
            screen.getByText('Hasil asli laboratorium VERIFIED'),
        ).toBeVisible();
        const provenanceButtons = screen.getAllByText('Provenans tarif');
        await user.click(provenanceButtons[0]);
        await user.click(provenanceButtons[1]);
        expect(screen.getByText(/TRF-RAD-THORAX/)).toBeVisible();
        expect(screen.getByText(/TRF-LAB-CBC/)).toBeVisible();
        expect(screen.getByText('Master laboratorium')).toBeVisible();
        expect(screen.getByText('Hasil asli VERIFIED')).toBeVisible();
        const laboratoryProvenance = provenanceButtons[1].closest('details');
        expect(laboratoryProvenance).not.toBeNull();
        const laboratoryEvidence = within(laboratoryProvenance!);
        expect(
            laboratoryEvidence.getByText(/01K00000000000000000000034/),
        ).toBeVisible();
        expect(laboratoryEvidence.getByText(/1{64}/)).toBeVisible();
        expect(
            laboratoryEvidence.getByText(/01K00000000000000000000035/),
        ).toBeVisible();
        expect(
            laboratoryEvidence.getByText(/01K00000000000000000000036/),
        ).toBeVisible();
        expect(laboratoryEvidence.getByText(/2{64}/)).toBeVisible();
        expect(
            laboratoryEvidence.getByText(/LABORATORY_SERVICE/),
        ).toBeVisible();
        expect(laboratoryEvidence.getByText(/4{64}/)).toBeVisible();
        expect(laboratoryEvidence.getByText(/Berlaku mulai/)).toHaveTextContent(
            '2026-08-01',
        );

        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('focuses the Indonesian error summary and hides issuance for a read-only projection', () => {
        inertia.errors = {
            finance: 'Sumber biaya berubah. Muat ulang sebelum menerbitkan.',
        };
        const { rerender } = render(<FinanceBillDetail {...detail} />);

        const alert = screen.getByRole('alert');
        expect(alert).toHaveFocus();
        expect(alert).toHaveTextContent(
            'Versi tagihan belum dapat diterbitkan',
        );

        rerender(
            <FinanceBillDetail
                {...detail}
                permissions={{
                    can_issue: false,
                    can_settle: false,
                    can_request_correction: false,
                }}
                commands={{
                    issue_url: null,
                    settlement_url: null,
                    correction_request_url: null,
                }}
            />,
        );
        expect(
            screen.queryByRole('heading', {
                name: 'Terbitkan Versi Tagihan',
            }),
        ).not.toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', { name: 'Riwayat Versi' }),
            ).getByText('Belum ada versi yang diterbitkan.'),
        ).toBeVisible();
    });

    it('blocks issue for unresolved readiness and preserves historical coverage labels', () => {
        render(
            <FinanceBillDetail
                {...detail}
                bill={{
                    ...detail.bill,
                    source_readiness: worklist.bills[0].source_readiness,
                    versions: [
                        {
                            public_id: '01K00000000000000000000028',
                            version: 1,
                            source_event_count: 2,
                            gross_amount: 36000,
                            reversal_amount: -6000,
                            net_amount: 30000,
                            issue_reason: 'Penerbitan awal.',
                            issued_by_user_id: 7,
                            issued_at: '2026-09-01T08:00:00Z',
                            coverage_profile:
                                'PHARMACY_HANDOVER_RETURN_ONLY_V1',
                            coverage_label:
                                'Obat yang telah diserahkan dan retur terkait',
                            lines: detail.bill.current_sources.slice(0, 2),
                        },
                    ],
                }}
            />,
        );

        expect(
            screen.getByText('Penerbitan versi tagihan tertahan'),
        ).toBeVisible();
        expect(
            screen.queryByRole('heading', {
                name: 'Terbitkan Versi Tagihan',
            }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Cakupan saat diterbitkan:/)).toHaveTextContent(
            'Obat yang telah diserahkan dan retur terkait',
        );
    });

    it('records only an exact server-derived cash settlement after deliberate confirmation', async () => {
        const user = userEvent.setup();
        const settlementUrl =
            '/kasir/tagihan/01K00000000000000000000002/pelunasan-tunai';
        const issued = {
            ...detail,
            bill: {
                ...detail.bill,
                state: 'ISSUED_CURRENT' as const,
                current_version: 1,
            },
            settlement: {
                ...detail.settlement,
                bill_state: 'ISSUED_CURRENT' as const,
                bill_version_public_id: '01K00000000000000000000028',
                bill_version: 1,
                bill_version_content_digest: 'b'.repeat(64),
                amount: 105001,
                settlement_available: true,
            },
            permissions: {
                can_issue: false,
                can_settle: true,
                can_request_correction: false,
            },
            commands: {
                issue_url: null,
                settlement_url: settlementUrl,
                correction_request_url: null,
            },
        };
        const { container, rerender } = render(
            <FinanceBillDetail {...issued} />,
        );

        expect(
            screen.getByRole('heading', { name: 'Pelunasan Tunai' }),
        ).toBeVisible();
        expect(
            screen.getAllByText(
                (content) => content.replace(/\s/g, '') === 'Rp105.001',
            ),
        ).not.toHaveLength(0);
        const settle = screen.getByRole('button', {
            name: 'Catat Pelunasan Tunai',
        });
        expect(settle).toBeDisabled();

        await user.click(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi penerimaan tunai tepat sebesar/i,
            }),
        );
        await user.click(settle);

        expect(inertia.post).toHaveBeenCalledWith(
            settlementUrl,
            expect.objectContaining({
                expected_bill_fingerprint: 'a'.repeat(64),
                expected_bill_version_content_digest: 'b'.repeat(64),
                confirm_settlement: true,
            }),
            expect.anything(),
        );
        expect(inertia.post.mock.calls.at(-1)?.[1]).not.toHaveProperty(
            'amount',
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);

        rerender(
            <FinanceBillDetail
                {...issued}
                settlement={{
                    ...issued.settlement,
                    settlement_available: false,
                    settlement: {
                        public_id: '01K00000000000000000000060',
                        receipt_number:
                            'KWT-20260902-01K00000000000000000000060',
                        amount: 105001,
                        payment_method: 'CASH',
                        state: 'SETTLED',
                        settled_at: '2026-09-02T09:00:00Z',
                        content_digest: 'c'.repeat(64),
                        correction_state: 'ACTIVE',
                        correction_public_id: null,
                        correction_request_available: true,
                        correction_url: null,
                        receipt_url:
                            '/kasir/pelunasan/01K00000000000000000000060/kuitansi',
                    },
                }}
                permissions={{
                    can_issue: false,
                    can_settle: false,
                    can_request_correction: false,
                }}
                commands={{
                    issue_url: null,
                    settlement_url: null,
                    correction_request_url: null,
                }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Tagihan Lunas' }),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Lihat Kuitansi' }),
        ).toHaveAttribute(
            'href',
            '/kasir/pelunasan/01K00000000000000000000060/kuitansi',
        );
    });

    it('keeps an active receipt visible while separately collecting a restored exact balance', async () => {
        const user = userEvent.setup();
        const settlementUrl =
            '/kasir/tagihan/01K00000000000000000000002/pelunasan-tunai';
        const restored = {
            ...detail,
            bill: {
                ...detail.bill,
                state: 'ISSUED_CURRENT' as const,
                current_version: 1,
            },
            settlement: {
                ...detail.settlement,
                bill_state: 'ISSUED_CURRENT' as const,
                bill_version_public_id: '01K00000000000000000000028',
                bill_version: 1,
                bill_version_content_digest: 'b'.repeat(64),
                amount: 50000,
                settlement_available: true,
                settlement: {
                    public_id: '01K00000000000000000000061',
                    receipt_number: 'KWT-20260902-AKTIF',
                    amount: 55001,
                    payment_method: 'CASH' as const,
                    state: 'SETTLED' as const,
                    settled_at: '2026-09-02T10:00:00Z',
                    content_digest: 'c'.repeat(64),
                    correction_state: 'ACTIVE' as const,
                    correction_public_id: null,
                    correction_request_available: true,
                    correction_url: null,
                    receipt_url:
                        '/kasir/pelunasan/01K00000000000000000000061/kuitansi',
                },
            },
            permissions: {
                can_issue: false,
                can_settle: true,
                can_request_correction: false,
            },
            commands: {
                issue_url: null,
                settlement_url: settlementUrl,
                correction_request_url: null,
            },
        };
        const { container } = render(<FinanceBillDetail {...restored} />);

        expect(screen.getByText('KWT-20260902-AKTIF')).toBeVisible();
        expect(
            screen.getByRole('heading', {
                name: 'Sisa Tagihan yang Dapat Dilunasi',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(/tidak mencakup sisa tagihan ini/i),
        ).toBeVisible();
        expect(
            screen.getAllByText(
                (content) => content.replace(/\s/g, '') === 'Rp50.000',
            ),
        ).not.toHaveLength(0);

        await user.click(
            screen.getByRole('checkbox', {
                name: /penerimaan tunai baru tepat sebesar/i,
            }),
        );
        await user.click(
            screen.getByRole('button', {
                name: 'Catat Pelunasan Sisa Tunai',
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            settlementUrl,
            expect.objectContaining({
                expected_bill_fingerprint: 'a'.repeat(64),
                expected_bill_version_content_digest: 'b'.repeat(64),
                confirm_settlement: true,
            }),
            expect.any(Object),
        );
        expect(inertia.post.mock.calls.at(-1)?.[1]).not.toHaveProperty(
            'amount',
        );
        expectFortyFourPixelTargets(container);
        await expectAccessible(container);
    });

    it('renders an accommodation day with closed-interval tariff provenance', async () => {
        const user = userEvent.setup();
        render(
            <FinanceBillDetail
                {...detail}
                coverage={{
                    profile:
                        'PHARMACY_RADIOLOGY_LABORATORY_ACCOMMODATION_TARIFF_SOURCE_V1',
                    label: 'Sumber biaya yang telah direkonsiliasi, termasuk akomodasi rawat inap.',
                    excluded_label: 'Tindakan lain, pembayaran, dan klaim.',
                    domains: [
                        { domain: 'PHARMACY', label: 'Apotek' },
                        { domain: 'RADIOLOGY', label: 'Radiologi' },
                        { domain: 'LABORATORY', label: 'Laboratorium' },
                        { domain: 'ACCOMMODATION', label: 'Akomodasi' },
                    ],
                }}
                bill={{
                    ...detail.bill,
                    current_sources: [
                        ...detail.bill.current_sources,
                        {
                            public_id: '01K00000000000000000000040',
                            source_domain: 'ACCOMMODATION',
                            source_reference: '01K00000000000000000000041',
                            event_type: 'CHARGE',
                            description:
                                'Akomodasi rawat inap · hari okupansi 2026-09-01',
                            quantity: 1,
                            unit_amount: 1,
                            signed_amount: 1,
                            occurred_at: '2026-09-01T16:00:00Z',
                            tariff_provenance: {
                                binding_public_id: '01K00000000000000000000042',
                                binding_version_public_id:
                                    '01K00000000000000000000043',
                                binding_version: 1,
                                binding_content_digest: 'a'.repeat(64),
                                opening_location_event_public_id:
                                    '01K00000000000000000000044',
                                opening_location_event_digest: 'b'.repeat(64),
                                closing_type: 'ROUTINE_DISCHARGE',
                                closing_public_id: '01K00000000000000000000045',
                                closing_content_digest: 'c'.repeat(64),
                                bed_public_id: '01K00000000000000000000046',
                                bed_code: 'BED-MELATI-03',
                                inpatient_bed_version_public_id:
                                    '01K00000000000000000000047',
                                inpatient_bed_version: 3,
                                inpatient_bed_content_digest: 'd'.repeat(64),
                                ward_code: 'WRD-MELATI',
                                room_label: 'Kamar B',
                                service_class: 'Kelas II',
                                pricing_unit: 'OCCUPANCY_DAY',
                                occupancy_anchor_at: '2026-09-01T03:00:00Z',
                                interval_start_at: '2026-09-01T02:00:00Z',
                                interval_end_at: '2026-09-01T16:00:00Z',
                                tariff_item_public_id:
                                    '01K00000000000000000000048',
                                tariff_item_version_public_id:
                                    '01K00000000000000000000049',
                                tariff_item_code: 'TRF-AKM-MELATI',
                                tariff_content_digest: 'e'.repeat(64),
                                component_public_id:
                                    '01K00000000000000000000050',
                                component_code: 'ACCOMMODATION_SERVICE',
                                component_content_digest: 'f'.repeat(64),
                                service_date: '2026-09-01',
                                effective_from: '2026-08-01',
                            },
                        },
                    ],
                }}
            />,
        );

        expect(screen.getByText('Hari okupansi akomodasi')).toBeVisible();
        await user.click(screen.getAllByText('Provenans tarif').at(-1)!);
        expect(screen.getByText('Hari okupansi')).toBeVisible();
        expect(screen.getByText(/OCCUPANCY_DAY/)).toBeVisible();
        expect(screen.getByText(/BED-MELATI-03/)).toBeVisible();
        expect(screen.getByText(/ROUTINE_DISCHARGE/)).toBeVisible();
        expect(screen.getByText(/TRF-AKM-MELATI/)).toBeVisible();
    });
});
