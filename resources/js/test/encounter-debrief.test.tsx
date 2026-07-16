import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import EncounterDebrief from '@/pages/encounter/debrief';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

const inertiaPost = vi.hoisted(() => vi.fn());
const inertiaPageProps = vi.hoisted(() => ({
    current: {} as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...props }: MockLinkProps) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
        useForm: function useForm<T extends Record<string, unknown>>(
            initialData: T,
        ) {
            const [data, setFormData] = useState(initialData);

            return {
                data,
                errors: {},
                processing: false,
                setData<K extends keyof T>(key: K | T, value?: T[K]) {
                    if (typeof key === 'object') {
                        setFormData(key);

                        return;
                    }

                    setFormData((current) => ({
                        ...current,
                        [key]: value as T[K],
                    }));
                },
                clearErrors() {},
                post(
                    url: string,
                    options: {
                        preserveScroll?: boolean;
                        onSuccess?: (page: {
                            props: Record<string, unknown>;
                        }) => void;
                    } = {},
                ) {
                    inertiaPost(url, options, data);
                    options.onSuccess?.({ props: inertiaPageProps.current });
                },
            };
        },
    };
});

const commonEvent = {
    publicId: '01J00000000000000000000010',
    sequence: 1,
    actor: {
        name: 'Mahasiswa Keperawatan Demo',
        program: 'Keperawatan',
        role: 'Mahasiswa',
        assignmentPublicId: '01J00000000000000000000020',
    },
    source: {
        label: 'Versi dokumen klinis',
        publicId: '01J00000000000000000000030',
        version: 'v1',
    },
    recordedAt: '2026-07-16T09:05:00+07:00',
    clinicalOccurrenceAt: '2026-07-16T09:00:00+07:00',
    primaryAt: '2026-07-16T09:00:00+07:00',
    showsRecordedTimeDifference: true,
    outcome: 'SUCCESS',
};

const props = {
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: {
            code: 'FINALIZED',
            label: 'Difinalisasi untuk simulasi',
        },
        serviceType: 'Poliklinik umum',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-16T08:00:00+07:00',
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        birthDate: '1992-04-18',
        administrativeSex: 'Perempuan',
        mrn: 'MR-SIM-000001',
        synthetic: true as const,
        allergyStatus: 'Lihat sumber asesmen pada linimasa',
    },
    assignment: {
        publicId: '01J00000000000000000000020',
        program: 'Keperawatan',
        role: 'Mahasiswa',
        canViewReports: true,
    },
    session: {
        publicId: '01J00000000000000000000003',
        code: 'SIM-RJ-UEU-001',
        status: { code: 'ACTIVE', label: 'Aktif' },
        scenarioTitle: 'Rawat jalan interprofesional',
        learningOutcomes: [
            'Menelusuri satu alur encounter lintas program studi.',
            'Menerapkan asesmen awal dan skrining keselamatan.',
        ],
    },
    release: {
        gate: 'FINALIZED',
        label: 'Dirilis setelah finalisasi simulasi',
        finalizedAt: '2026-07-16T10:00:00+07:00',
        ordinaryEditsLocked: true,
    },
    teachingEvidence: {
        notes: [
            {
                publicId: '01J00000000000000000000040',
                type: {
                    code: 'FACILITATOR_SYNTHESIS',
                    label: 'Sintesis fasilitator',
                },
                createdAt: '2026-07-16T10:05:00+07:00',
                versions: [
                    {
                        publicId: '01J00000000000000000000041',
                        versionNumber: 1,
                        body: 'Versi awal menyoroti handoff registrasi.',
                        changeReason: null,
                        authoredAt: '2026-07-16T10:05:00+07:00',
                        author: {
                            name: 'Fasilitator Simulasi UEU',
                            program: 'Fasilitasi',
                            role: 'Fasilitator',
                            assignmentPublicId: '01J00000000000000000000050',
                        },
                    },
                    {
                        publicId: '01J00000000000000000000042',
                        versionNumber: 2,
                        body: 'Versi terbaru menyoroti handoff dan alasan koreksi.',
                        changeReason: 'Menambahkan hubungan koreksi.',
                        authoredAt: '2026-07-16T10:15:00+07:00',
                        author: {
                            name: 'Fasilitator Simulasi UEU',
                            program: 'Fasilitasi',
                            role: 'Fasilitator',
                            assignmentPublicId: '01J00000000000000000000050',
                        },
                    },
                ],
                revisionUrl: '/debrief-notes/example/versions',
                revisionRequestKey: '01J00000000000000000000060',
            },
        ],
        rubricReferences: [
            {
                code: 'UEU-OPD-IPE-DRAFT-001',
                title: 'Referensi rubrik perjalanan rawat jalan lintas profesi',
                version: 'DRAFT-0.1',
                status: {
                    code: 'PENDING_PROGRAM_REVIEW',
                    label: 'Menunggu telaah program studi',
                },
                sourceLabel: 'Rancangan internal SIMRS Campus UEU',
                learningOutcomes: [
                    {
                        number: 1,
                        label: 'Menelusuri satu alur encounter lintas program studi.',
                    },
                ],
                nonScoring: true,
            },
        ],
        authoring: {
            canAuthorNotes: false,
            storeUrl: '/encounters/example/debrief/notes',
            requestKey: '01J00000000000000000000061',
            bodyMaxCharacters: 4000,
            noteTypes: [
                {
                    code: 'FACILITATOR_SYNTHESIS',
                    label: 'Sintesis fasilitator',
                },
                {
                    code: 'GUIDED_REFLECTION',
                    label: 'Refleksi terpandu',
                },
            ],
        },
    },
    events: [
        {
            ...commonEvent,
            category: { code: 'NURSING', label: 'Keperawatan' },
            title: 'Versi asesmen awal dibuat',
            detail: 'Sumber v1 direkam tanpa menimpa versi sebelumnya.',
            tags: [{ code: 'HANDOFF', label: 'Handoff' }],
        },
        {
            ...commonEvent,
            publicId: '01J00000000000000000000011',
            sequence: 2,
            category: { code: 'CODING', label: 'Koding' },
            title: 'Koding Diagnosis klinisi disetujui untuk simulasi',
            detail: 'Persetujuan supervisor melekat pada sumber dan release terminologi yang ditinjau.',
            actor: {
                name: 'Supervisor RMIK Demo',
                program: 'RMIK',
                role: 'Supervisor',
                assignmentPublicId: '01J00000000000000000000021',
            },
            source: {
                label: 'Penetapan kode',
                publicId: '01J00000000000000000000031',
                version: null,
            },
            recordedAt: '2026-07-16T10:00:00+07:00',
            clinicalOccurrenceAt: null,
            primaryAt: '2026-07-16T10:00:00+07:00',
            showsRecordedTimeDifference: false,
            tags: [
                {
                    code: 'SUPERVISION',
                    label: 'Keputusan supervisor',
                },
                {
                    code: 'HUMAN_CODING',
                    label: 'Keputusan koding manusia',
                },
            ],
        },
    ],
    summary: {
        displayedEventCount: 2,
        totalAvailableEventCount: 2,
        truncated: false,
        categoryCounts: [
            { label: 'Keperawatan', count: 1 },
            { label: 'Koding', count: 1 },
        ],
        programCounts: [
            { label: 'Keperawatan', count: 1 },
            { label: 'RMIK', count: 1 },
        ],
        correctionCount: 0,
        supervisionCount: 1,
        handoffCount: 1,
    },
    urls: {
        encounter: '/encounters/example',
        self: '/encounters/example/debrief',
        workQueue: '/work',
        outpatientSummaryReport:
            '/encounters/example/reports/outpatient-summary',
        debriefEvidenceReport: '/encounters/example/reports/debrief-evidence',
    },
};

describe('Encounter debrief', () => {
    it('presents a simulation-labelled, source-attributed timeline without raw audit internals', async () => {
        const { container } = render(<EncounterDebrief {...props} />);

        expect(
            screen.getByRole('heading', { name: 'Linimasa Rekam & Debrief' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Ringkasan cetak' }),
        ).toHaveAttribute(
            'href',
            '/encounters/example/reports/outpatient-summary',
        );
        expect(
            screen.getByRole('link', { name: 'Laporan debrief' }),
        ).toHaveAttribute(
            'href',
            '/encounters/example/reports/debrief-evidence',
        );
        expect(
            screen.getByText('SIMULASI — DATA SINTETIS'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Dirilis setelah finalisasi simulasi'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Versi asesmen awal dibuat'),
        ).toBeInTheDocument();
        expect(screen.getAllByText(/Penugasan …00000020/)).not.toHaveLength(0);
        expect(screen.getByText(/Dicatat/)).toBeInTheDocument();
        expect(
            screen.getByText(
                'Versi terbaru menyoroti handoff dan alasan koreksi.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Menambahkan hubungan koreksi.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Riwayat 1 versi sebelumnya')).toHaveClass(
            'py-3',
        );
        expect(
            screen.getByText('Menunggu telaah program studi'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Non-scoring/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Buat versi perbaikan' }),
        ).not.toBeInTheDocument();
        expect(container.textContent).not.toContain('raw-secret');
        expect(container.textContent).not.toContain('request_correlation_id');
        expect(container.textContent).not.toContain('ip_hash');

        const result = await axe.run(container);
        expect(
            result.violations,
            JSON.stringify(
                result.violations.map((violation) => ({
                    id: violation.id,
                    targets: violation.nodes.map((node) => node.target),
                })),
            ),
        ).toHaveLength(0);
    });

    it('filters presentation only and announces the result count', async () => {
        const user = userEvent.setup();
        render(<EncounterDebrief {...props} />);

        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Tahap layanan' }),
            'CODING',
        );

        expect(
            screen.queryByText('Versi asesmen awal dibuat'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'Koding Diagnosis klinisi disetujui untuk simulasi',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('1 dari 2 peristiwa')).toBeInTheDocument();
        expect(window.location.search).toContain('stage=CODING');
    });

    it('lets an authorized facilitator compose an attested shared note', async () => {
        inertiaPost.mockClear();
        inertiaPageProps.current = {
            teachingEvidence: {
                ...props.teachingEvidence,
                authoring: {
                    ...props.teachingEvidence.authoring,
                    canAuthorNotes: true,
                    requestKey: '01J00000000000000000000062',
                },
            },
        };
        const user = userEvent.setup();
        render(
            <EncounterDebrief
                {...props}
                teachingEvidence={{
                    ...props.teachingEvidence,
                    notes: [],
                    authoring: {
                        ...props.teachingEvidence.authoring,
                        canAuthorNotes: true,
                    },
                }}
            />,
        );

        await user.type(
            screen.getByRole('textbox', {
                name: 'Isi catatan pembelajaran',
            }),
            'Sintesis fasilitator yang dibagikan kepada seluruh peserta.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi bahwa catatan ini untuk debrief simulasi bersama/,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan catatan bersama' }),
        );

        expect(inertiaPost).toHaveBeenCalledWith(
            '/encounters/example/debrief/notes',
            expect.objectContaining({
                preserveScroll: true,
                onSuccess: expect.any(Function),
            }),
            expect.objectContaining({
                request_key: '01J00000000000000000000061',
                body: 'Sintesis fasilitator yang dibagikan kepada seluruh peserta.',
                simulation_attestation: true,
            }),
        );
        expect(
            screen.getByRole('textbox', {
                name: 'Isi catatan pembelajaran',
            }),
        ).toHaveValue('');
        expect(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi bahwa catatan ini untuk debrief simulasi bersama/,
            }),
        ).not.toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Simpan catatan bersama' }),
        ).toBeDisabled();

        await user.type(
            screen.getByRole('textbox', {
                name: 'Isi catatan pembelajaran',
            }),
            'Catatan bersama kedua menggunakan kunci permintaan baru.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi bahwa catatan ini untuk debrief simulasi bersama/,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan catatan bersama' }),
        );

        expect(inertiaPost).toHaveBeenLastCalledWith(
            '/encounters/example/debrief/notes',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
            expect.objectContaining({
                request_key: '01J00000000000000000000062',
            }),
        );
    });

    it('closes a successful revision and refreshes its request key', async () => {
        inertiaPost.mockClear();
        const responseNote = {
            ...props.teachingEvidence.notes[0],
            revisionRequestKey: '01J00000000000000000000063',
            versions: [
                ...props.teachingEvidence.notes[0].versions,
                {
                    ...props.teachingEvidence.notes[0].versions[1],
                    publicId: '01J00000000000000000000043',
                    versionNumber: 3,
                    body: 'Versi ketiga menjadi nilai awal form penerus.',
                    changeReason: 'Memperjelas tindakan berikutnya.',
                },
            ],
        };
        inertiaPageProps.current = {
            teachingEvidence: {
                ...props.teachingEvidence,
                notes: [responseNote],
                authoring: {
                    ...props.teachingEvidence.authoring,
                    canAuthorNotes: true,
                },
            },
        };
        const user = userEvent.setup();
        render(
            <EncounterDebrief
                {...props}
                teachingEvidence={{
                    ...props.teachingEvidence,
                    authoring: {
                        ...props.teachingEvidence.authoring,
                        canAuthorNotes: true,
                    },
                }}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: 'Buat versi perbaikan' }),
        );
        await user.clear(
            screen.getByRole('textbox', { name: 'Isi versi baru' }),
        );
        await user.type(
            screen.getByRole('textbox', { name: 'Isi versi baru' }),
            'Versi ketiga menjadi nilai awal form penerus.',
        );
        await user.type(
            screen.getByRole('textbox', { name: 'Alasan perubahan' }),
            'Memperjelas tindakan berikutnya.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi versi ini tetap berupa catatan debrief bersama/,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan versi penerus' }),
        );

        expect(
            screen.queryByRole('textbox', { name: 'Isi versi baru' }),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: 'Buat versi perbaikan' }),
        );
        expect(
            screen.getByRole('textbox', { name: 'Isi versi baru' }),
        ).toHaveValue('Versi ketiga menjadi nilai awal form penerus.');
        expect(
            screen.getByRole('textbox', { name: 'Alasan perubahan' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi versi ini tetap berupa catatan debrief bersama/,
            }),
        ).not.toBeChecked();

        await user.type(
            screen.getByRole('textbox', { name: 'Alasan perubahan' }),
            'Menguji kunci permintaan penerus.',
        );
        await user.click(
            screen.getByRole('checkbox', {
                name: /Saya mengonfirmasi versi ini tetap berupa catatan debrief bersama/,
            }),
        );
        await user.click(
            screen.getByRole('button', { name: 'Simpan versi penerus' }),
        );

        expect(inertiaPost).toHaveBeenLastCalledWith(
            '/debrief-notes/example/versions',
            expect.objectContaining({ onSuccess: expect.any(Function) }),
            expect.objectContaining({
                request_key: '01J00000000000000000000063',
            }),
        );
    });
});
