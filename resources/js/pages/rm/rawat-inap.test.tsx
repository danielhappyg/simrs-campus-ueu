import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import RmRawatInap from './rawat-inap';
import RmRawatInapShow from './rawat-inap/show';

const submissions: Array<{ url: string; data: Record<string, unknown> }> = [];

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: ({
            href,
            children,
            ...props
        }: AnchorHTMLAttributes<HTMLAnchorElement> & {
            href: string;
            children?: ReactNode;
        }) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        router: { get: vi.fn(), on: vi.fn(() => () => undefined) },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = React.useState(initial);
            const dataRef = React.useRef(data);
            const setData = (next: T | ((current: T) => T)) => {
                const resolved =
                    typeof next === 'function' ? next(dataRef.current) : next;
                dataRef.current = resolved;
                setState(resolved);
            };

            return {
                data,
                errors: {},
                processing: false,
                setData,
                post: (url: string, options?: { onSuccess?: () => void }) => {
                    submissions.push({ url, data: dataRef.current });
                    options?.onSuccess?.();
                },
            };
        },
    };
});

const indexData = {
    filters: {
        q: '',
        ward: '',
        payer: '',
        review_state: '',
        discharged_from: '',
        discharged_to: '',
    },
    filter_options: {
        wards: [{ value: 'MELATI', label: 'Melati' }],
        payers: [{ value: 'UMUM', label: 'Umum' }],
        review_states: [{ value: 'NOT_REVIEWED', label: 'Belum ditinjau' }],
    },
    encounters: [
        {
            public_id: 'episode-1',
            status: 'READY_FOR_RM',
            discharged_at: '2026-08-30',
            last_ward_name: 'Melati',
            payer_type: 'UMUM',
            review_status: 'NOT_REVIEWED' as const,
            review_version: 0,
            patient: {
                medical_record_number: 'RM-001',
                full_name: 'Rina Test',
            },
        },
    ],
    actions: { show_url: '/rm/rawat-inap' },
};

const detailData = {
    encounter: {
        public_id: 'episode-1',
        status: 'READY_FOR_RM',
        discharged_at: '2026-08-30T09:00:00+07:00',
        last_ward_name: 'Melati',
        payer_type: 'UMUM',
    },
    patient: { medical_record_number: 'RM-001', full_name: 'Rina Test' },
    discharge: {
        public_id: 'discharge-1',
        discharged_at: '2026-08-30T09:00:00+07:00',
        disposition_label: 'Pulang atas izin dokter',
    },
    discharge_summary: { state: 'FINAL', version: 1, fields: {} },
    coding_source: {
        state: 'FINAL',
        version: 1,
        principal_diagnosis_statement: 'Gastroenteritis akut',
        secondary_diagnosis_statements: ['Dehidrasi'],
        performed_procedure_statements: [],
    },
    coding: {
        version: 2,
        content_digest: 'coding-digest-1',
        state: 'DRAFT' as const,
        source_statements: [
            {
                kind: 'PRINCIPAL_DIAGNOSIS' as const,
                index: 0,
                text: 'Gastroenteritis akut',
                text_hash: 'principal-hash',
            },
            {
                kind: 'SECONDARY_DIAGNOSIS' as const,
                index: 0,
                text: 'Dehidrasi',
                text_hash: 'secondary-hash',
            },
        ],
        assignments: [
            {
                kind: 'PRINCIPAL_DIAGNOSIS' as const,
                source_statement_kind: 'PRINCIPAL_DIAGNOSIS',
                source_statement_index: 0,
                source_statement_text_hash: 'principal-hash',
                code: 'A09',
                description: 'Gastroenteritis',
                source_statement: 'Gastroenteritis akut',
            },
        ],
        history: [],
        can_save_draft: true,
    },
    completeness: {
        status: 'COMPLETE' as const,
        version: 3,
        source_fingerprint: 'source-fingerprint-1',
        items: [
            {
                code: 'SUMMARY_FINAL',
                label: 'Ringkasan pulang final',
                status: 'PASS' as const,
                reason: null,
            },
        ],
        blockers: [],
        reviewer_name: 'RMIK Test',
        reviewed_at: null,
        signed_off_by_name: null,
        signed_off_at: null,
        can_save_review: true,
        can_signoff: true,
    },
    summary_addendum: {
        definition_version: 'INPATIENT_POST_CLOSURE_SUMMARY_ADDENDUM_V1',
        available: false,
        reason_options: [],
        can_request: false,
        store_url: null,
        requests: [],
    },
    history: [],
    actions: {
        save_coding_draft_url: '/rm/rawat-inap/episode-1/coding/draft',
        save_review_url: '/rm/rawat-inap/episode-1/review',
        signoff_url: '/rm/rawat-inap/episode-1/signoff',
    },
};

describe('inpatient RMIK workspace', () => {
    it('offers only RMIK worklist filters and links to the inpatient review', () => {
        render(<RmRawatInap inpatient_rm={indexData} />);
        expect(
            screen.getByRole('link', { name: 'Rawat Jalan' }),
        ).toHaveAttribute('href', '/rm/rawat-jalan');
        expect(screen.getByRole('link', { name: 'Tinjau RM' })).toHaveAttribute(
            'href',
            '/rm/rawat-inap/episode-1',
        );
        expect(screen.getByLabelText('Bangsal terakhir')).toBeInTheDocument();
        expect(
            screen.queryByText(/status klaim|kelas perawatan/i),
        ).not.toBeInTheDocument();
    });

    it('presents discharged episodes oldest first even when the incoming worklist is not ordered', () => {
        render(
            <RmRawatInap
                inpatient_rm={{
                    ...indexData,
                    encounters: [
                        {
                            ...indexData.encounters[0],
                            public_id: 'newer-episode',
                            discharged_at: '2026-08-31',
                            patient: {
                                medical_record_number: 'RM-002',
                                full_name: 'Pasien Baru',
                            },
                        },
                        indexData.encounters[0],
                    ],
                }}
            />,
        );

        const names = screen
            .getAllByRole('cell')
            .filter((cell) =>
                ['Rina Test', 'Pasien Baru'].includes(cell.textContent ?? ''),
            );
        expect(names.map((cell) => cell.textContent)).toEqual([
            'Rina Test',
            'Pasien Baru',
        ]);
    });

    it('keeps clinical input read-only and posts versioned manual coding drafts', async () => {
        submissions.length = 0;
        const user = userEvent.setup();
        render(<RmRawatInapShow inpatient_rm={detailData} />);
        expect(
            screen.getByRole('complementary', {
                name: 'Sumber klinis hanya-baca',
            }),
        ).toHaveTextContent('Gastroenteritis akut');
        expect(
            screen.getByText(/bukan pencarian katalog kode/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Tidak ada pernyataan sumber untuk kategori ini.'),
        ).toBeInTheDocument();
        expect(screen.getAllByLabelText('Masukkan kode')).toHaveLength(2);
        expect(screen.getAllByLabelText('Masukkan kode')[0]).toHaveValue('A09');
        await user.type(screen.getAllByLabelText('Masukkan kode')[1], 'E86');
        await user.type(
            screen.getAllByLabelText('Keterangan kode')[1],
            'Dehidrasi',
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan draf pengodean' }),
        );
        expect(submissions).toHaveLength(1);
        expect(submissions[0].url).toBe(
            '/rm/rawat-inap/episode-1/coding/draft',
        );
        expect(submissions[0].data).toMatchObject({
            expected_version: 2,
            assignments: [
                { kind: 'PRINCIPAL_DIAGNOSIS', code: 'A09' },
                {
                    kind: 'SECONDARY_DIAGNOSIS',
                    code: 'E86',
                    source_statement_kind: 'SECONDARY_DIAGNOSIS',
                    source_statement_index: 0,
                    source_statement_text_hash: 'secondary-hash',
                },
            ],
        });
        expect(submissions[0].data.idempotency_key).toMatch(
            /^inpatient-rmik-coding-draft-/,
        );

        await user.click(
            screen.getByRole('button', { name: 'Simpan hasil review' }),
        );
        expect(submissions[1]).toMatchObject({
            url: '/rm/rawat-inap/episode-1/review',
            data: {
                expected_version: 3,
                source_fingerprint: 'source-fingerprint-1',
                coding_version: 2,
                coding_digest: 'coding-digest-1',
            },
        });
        expect(submissions[1].data).not.toHaveProperty('items');
    });

    it('holds sign-off until every source is coded and the draft is saved, then posts only after confirmation', async () => {
        submissions.length = 0;
        const user = userEvent.setup();
        render(<RmRawatInapShow inpatient_rm={detailData} />);

        const signoff = screen.getByRole('button', {
            name: 'Sign-off RM dan tutup episode',
        });
        expect(signoff).toBeDisabled();
        await user.type(screen.getAllByLabelText('Masukkan kode')[1], 'E86');
        await user.type(
            screen.getAllByLabelText('Keterangan kode')[1],
            'Dehidrasi',
        );
        expect(signoff).toBeDisabled();
        await user.click(
            screen.getByRole('button', { name: 'Simpan draf pengodean' }),
        );
        expect(signoff).toBeEnabled();
        await user.click(signoff);
        expect(submissions).toHaveLength(1);
        await user.click(
            screen.getByRole('button', {
                name: 'Ya, sign-off RM dan tutup episode',
            }),
        );
        expect(submissions[1]).toMatchObject({
            url: '/rm/rawat-inap/episode-1/signoff',
            data: {
                expected_version: 3,
                source_fingerprint: 'source-fingerprint-1',
                coding_version: 2,
                coding_digest: 'coding-digest-1',
            },
        });
    });

    it('uses refreshed server versions for repeated coding and review without replacing dirty edits', async () => {
        submissions.length = 0;
        const user = userEvent.setup();
        const { rerender } = render(
            <RmRawatInapShow inpatient_rm={detailData} />,
        );
        const refreshed = {
            ...detailData,
            coding: {
                ...detailData.coding,
                version: 3,
                content_digest: 'coding-digest-2',
                assignments: [
                    ...detailData.coding.assignments,
                    {
                        kind: 'SECONDARY_DIAGNOSIS' as const,
                        source_statement_kind: 'SECONDARY_DIAGNOSIS',
                        source_statement_index: 0,
                        source_statement_text_hash: 'secondary-hash',
                        code: 'E86',
                        description: 'Dehidrasi',
                        source_statement: 'Dehidrasi',
                    },
                ],
            },
            completeness: {
                ...detailData.completeness,
                version: 4,
                source_fingerprint: 'source-fingerprint-2',
            },
        };
        rerender(<RmRawatInapShow inpatient_rm={refreshed} />);

        await waitFor(() =>
            expect(screen.getAllByLabelText('Masukkan kode')[1]).toHaveValue(
                'E86',
            ),
        );
        await user.clear(screen.getAllByLabelText('Masukkan kode')[0]);
        await user.type(screen.getAllByLabelText('Masukkan kode')[0], 'A09.9');
        await user.click(
            screen.getByRole('button', { name: 'Simpan draf pengodean' }),
        );
        expect(submissions[0].data).toMatchObject({
            expected_version: 3,
            assignments: [{ code: 'A09.9' }, { code: 'E86' }],
        });

        await user.click(
            screen.getByRole('button', { name: 'Simpan hasil review' }),
        );
        expect(submissions[1].data).toMatchObject({
            expected_version: 4,
            source_fingerprint: 'source-fingerprint-2',
            coding_version: 3,
            coding_digest: 'coding-digest-2',
        });
    });

    it('keeps sign-off locked when the calculated review has a blocker', () => {
        render(
            <RmRawatInapShow
                inpatient_rm={{
                    ...detailData,
                    coding: {
                        ...detailData.coding,
                        assignments: [
                            ...detailData.coding.assignments,
                            {
                                kind: 'SECONDARY_DIAGNOSIS',
                                source_statement_kind: 'SECONDARY_DIAGNOSIS',
                                source_statement_index: 0,
                                source_statement_text_hash: 'secondary-hash',
                                source_statement: 'Dehidrasi',
                                code: 'E86',
                                description: 'Dehidrasi',
                            },
                        ],
                    },
                    completeness: {
                        ...detailData.completeness,
                        blockers: [
                            {
                                code: 'CODING_SOURCE_CHANGED',
                                label: 'Sumber berubah',
                                reason: 'Telaah ulang diperlukan.',
                            },
                        ],
                    },
                }}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: 'Sign-off RM dan tutup episode',
            }),
        ).toBeDisabled();
    });

    it('renders a closed FINAL episode as terminal read-only evidence', () => {
        render(
            <RmRawatInapShow
                inpatient_rm={{
                    ...detailData,
                    encounter: { ...detailData.encounter, status: 'CLOSED' },
                    coding: { ...detailData.coding, state: 'FINAL' },
                    completeness: {
                        ...detailData.completeness,
                        status: 'SIGNED_OFF',
                    },
                }}
            />,
        );

        expect(
            screen.getAllByText('Episode ditutup oleh RM').length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('Final v2')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Simpan draf pengodean' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: 'Sign-off RM dan tutup episode',
            }),
        ).not.toBeInTheDocument();

        for (const input of screen.getAllByRole('textbox')) {
            expect(input).toBeDisabled();
        }
    });

    it('has no focused automated accessibility violations', async () => {
        const { container } = render(
            <RmRawatInapShow inpatient_rm={detailData} />,
        );
        const result = await axe.run(container, {
            rules: { 'color-contrast': { enabled: false } },
        });
        expect(result.violations).toEqual([]);
    });
});
