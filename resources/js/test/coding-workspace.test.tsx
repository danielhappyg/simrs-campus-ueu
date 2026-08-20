import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import CodingWorkspace from '@/pages/coding/workspace';
import type { CodingWorkspaceProps } from '@/types';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    preserveScroll?: boolean;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, preserveScroll, children, ...props }: MockLinkProps) => (
        <a
            href={href}
            data-preserve-scroll={preserveScroll ? 'true' : undefined}
            {...props}
        >
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        post: vi.fn(),
    },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        clearErrors: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
    }),
}));

const props: CodingWorkspaceProps = {
    encounter: {
        publicId: '01J00000000000000000000001',
        number: 'ENC-SIM-000001',
        status: { code: 'RECORD_REVIEW', label: 'Telaah rekam medis' },
        serviceType: 'Rawat jalan',
        location: 'Poliklinik Umum Simulasi UEU',
        periodStart: '2026-07-15T08:00:00+07:00',
        environmentMode: 'SIMULATION',
    },
    patient: {
        publicId: '01J00000000000000000000002',
        fullName: 'Pasien Sintetis Arunika',
        birthDate: '1998-04-17',
        administrativeSex: 'Perempuan',
        mrn: 'RM-SIM-000001',
        synthetic: true,
        allergyStatus: 'Tidak ada alergi yang dilaporkan',
    },
    session: {
        code: 'SIM-OPD-001',
        scenarioTitle: 'Rawat jalan interprofesional',
    },
    assignment: {
        publicId: '01J00000000000000000000003',
        program: 'RMIK',
        role: 'Koder RMIK',
        canCode: true,
        canReview: false,
    },
    task: {
        publicId: '01J00000000000000000000004',
        type: 'CODING',
        status: { code: 'READY', label: 'Siap' },
    },
    documentationCorrection: null,
    prerequisites: {
        qualityApproved: true,
        qualityReviewPublicId: '01J00000000000000000000005',
        qualityReviewVersion: 1,
        medicalSourceApproved: true,
        medicalSourcePublicId: '01J00000000000000000000006',
        medicalSourceVersion: 1,
        medicalSourceHash: 'a'.repeat(64),
        closureSourceApproved: true,
        closureSourcePublicId: '01J00000000000000000000015',
        closureSourceVersion: 1,
        closureSourceHash: 'b'.repeat(64),
    },
    releases: [
        {
            system: { code: 'ICD_10', label: 'ICD-10' },
            sourceType: 'DIAGNOSIS',
            active: true,
            release: {
                publicId: '01J00000000000000000000007',
                logicalVersion: 'ICD10_2010',
                status: { code: 'ACTIVE', label: 'Aktif' },
                sourceFilename: '[PUBLIC] ICD-10 e-klaim (1).xlsx',
                sourceSha256: '3'.repeat(64),
                rowCount: 18543,
                ignoredBlankRows: 998,
                provenance: 'Berkas pengguna terverifikasi',
                activatedAt: '2026-07-15T09:00:00+07:00',
            },
        },
        {
            system: { code: 'ICD_9_CM', label: 'ICD-9-CM' },
            sourceType: 'PROCEDURE',
            active: true,
            release: {
                publicId: '01J00000000000000000000008',
                logicalVersion: 'ICD9CM_2010',
                status: { code: 'ACTIVE', label: 'Aktif' },
                sourceFilename: '[PUBLIC] ICD-9CM e-klaim.xlsx',
                sourceSha256: '9'.repeat(64),
                rowCount: 4626,
                ignoredBlankRows: 0,
                provenance: 'Berkas pengguna terverifikasi',
                activatedAt: '2026-07-15T09:00:00+07:00',
            },
        },
    ],
    sources: [
        {
            publicId: '01J00000000000000000000009',
            sourceType: { code: 'DIAGNOSIS', label: 'Diagnosis klinisi' },
            terminologySystem: 'ICD_10',
            correctionSupported: true,
            authoredText: 'Dizziness and giddiness',
            certainty: { code: 'WORKING', label: 'Diagnosis kerja' },
            role: { code: 'PRIMARY', label: 'Utama' },
            clinicalStatus: 'ACTIVE',
            clinicianCode: null,
            sourceVersion: {
                publicId: '01J00000000000000000000006',
                versionNumber: 1,
                schemaVersion: 'medical-assessment.v1',
                status: { code: 'APPROVED', label: 'Disetujui' },
                contentHash: 'a'.repeat(64),
                documentContentHash: 'a'.repeat(64),
                author: 'Mahasiswa Kedokteran Demo',
            },
            procedureDetails: null,
            canGenerate: true,
            suggestionUrl:
                '/encounters/example/conditions/example/coding-suggestions',
            latestRun: null,
            assignmentHistory: [],
        },
    ],
    selectedSourcePublicId: '01J00000000000000000000009',
    manualSearch: {
        query: '',
        system: 'ICD_10',
        error: null,
        results: [],
    },
    formOptions: {
        suggestionRequestKey: '01J00000000000000000000010',
        decisionRequestKey: '01J00000000000000000000011',
        reviewRequestKey: '01J00000000000000000000012',
        decisionTypes: [],
        reviewActions: [],
    },
    urls: {
        self: '/encounters/example/coding',
        recordQuality: '/encounters/example/record-quality',
        workQueue: '/work',
    },
};

describe('Coding workspace', () => {
    it('keeps the human-review boundary and release provenance visible without a bulk-accept action', async () => {
        const { container } = render(
            <main>
                <CodingWorkspace {...props} />
            </main>,
        );

        expect(
            screen.getByRole('heading', { name: 'Saran Koding Otomatis' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Wajib ditinjau koder')).toBeInTheDocument();
        expect(
            screen.getByText('Bukan finalisasi otomatis'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Dizziness and giddiness')).toHaveLength(2);
        expect(
            screen.getByText('ICD10_2010 · 18.543 kode'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('ICD9CM_2010 · 4.626 kode'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /terima semua/i }),
        ).not.toBeInTheDocument();

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

    it('shows the attributed correction gate while coding is blocked', () => {
        render(
            <CodingWorkspace
                {...props}
                documentationCorrection={{
                    publicId: '01J00000000000000000000013',
                    sourceType: 'DIAGNOSIS',
                    status: {
                        code: 'MEDICAL_APPROVED',
                        label: 'Menunggu penutupan penerus',
                    },
                    reason: 'Perjelas sifat pusing.',
                    responsibleAuthor: 'Mahasiswa Kedokteran Demo',
                    requestedAt: '2026-07-16T09:00:00+07:00',
                    requestedSourceHash: 'a'.repeat(64),
                    requestedClosureHash: null,
                    responseMedicalVersionPublicId:
                        '01J00000000000000000000014',
                    responseClosurePublicId: null,
                }}
            />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Koding diblokir selama koreksi sumber',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Menunggu penutupan penerus'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Perjelas sifat pusing/)).toBeInTheDocument();
    });

    it('shows the coder rationale and source provenance before supervisor approval', () => {
        render(
            <CodingWorkspace
                {...props}
                assignment={{
                    ...props.assignment,
                    role: 'Supervisor',
                    canCode: false,
                    canReview: true,
                }}
                task={{
                    publicId: '01J00000000000000000000020',
                    type: 'CODING_REVIEW',
                    status: { code: 'READY', label: 'Siap' },
                }}
                sources={[
                    {
                        ...props.sources[0],
                        canGenerate: false,
                        assignmentHistory: [
                            {
                                publicId: '01J00000000000000000000021',
                                sourceType: {
                                    code: 'DIAGNOSIS',
                                    label: 'Diagnosis klinisi',
                                },
                                status: {
                                    code: 'SUBMITTED',
                                    label: 'Diajukan',
                                },
                                selectionMethod: {
                                    code: 'MANUAL',
                                    label: 'Dipilih manual',
                                },
                                concept: {
                                    publicId: '01J00000000000000000000022',
                                    code: 'G44.2',
                                    display: 'Tension-type headache',
                                },
                                sourceClinicalContentHash: 'a'.repeat(64),
                                sourceStatementHash: 's'.repeat(64),
                                terminologySourceHash: '3'.repeat(64),
                                contentHash: 'c'.repeat(64),
                                rationale:
                                    'G44.2 dipilih setelah meninjau pernyataan diagnosis dan indeks ICD10_2010.',
                                changeReason: null,
                                coder: 'Koder RMIK Demo',
                                recordedAt: '2026-07-15T10:15:00+07:00',
                                submittedAt: '2026-07-15T10:16:00+07:00',
                                reviewedAt: null,
                                canSubmit: false,
                                canReview: true,
                                submitUrl: '/coding-assignments/example/submit',
                                reviewUrl: '/coding-assignments/example/review',
                                reviews: [],
                            },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getByText('Rasional koder')).toBeInTheDocument();
        expect(
            screen.getByText(
                'G44.2 dipilih setelah meninjau pernyataan diagnosis dan indeks ICD10_2010.',
            ),
        ).toBeVisible();
        expect(screen.getByText(/Pernyataan sumber:/)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Setujui kode' }),
        ).toBeInTheDocument();
    });

    it('shows an immutable performed procedure with ICD-9-CM actions and a dedicated procedure-correction route', () => {
        const procedurePublicId = '01J00000000000000000000016';

        render(
            <CodingWorkspace
                {...props}
                sources={[
                    ...props.sources,
                    {
                        publicId: procedurePublicId,
                        sourceType: {
                            code: 'PROCEDURE',
                            label: 'Prosedur yang dilakukan',
                        },
                        terminologySystem: 'ICD_9_CM',
                        correctionSupported: true,
                        authoredText:
                            'Pengambilan sampel darah vena untuk pemeriksaan sintetis.',
                        certainty: null,
                        role: null,
                        clinicalStatus: 'COMPLETED',
                        clinicianCode: null,
                        sourceVersion: {
                            publicId: '01J00000000000000000000015',
                            versionNumber: 1,
                            schemaVersion: 'encounter-closure.v2',
                            status: {
                                code: 'APPROVED',
                                label: 'Disetujui untuk simulasi',
                            },
                            contentHash: 'c'.repeat(64),
                            documentContentHash: 'b'.repeat(64),
                            author: 'Mahasiswa Kedokteran Demo',
                        },
                        procedureDetails: {
                            performedStartAt: '2026-07-15T09:15:00+07:00',
                            performedEndAt: '2026-07-15T09:25:00+07:00',
                            performerText: 'Petugas laboratorium simulasi',
                            bodySiteText: 'Vena lengan kanan',
                            outcomeText: 'Sampel sintetis berhasil diperoleh',
                            basedOnServiceRequestPublicId:
                                '01J00000000000000000000017',
                        },
                        canGenerate: true,
                        suggestionUrl:
                            '/encounters/example/procedures/example/coding-suggestions',
                        latestRun: {
                            publicId: '01J00000000000000000000018',
                            sourceType: {
                                code: 'PROCEDURE',
                                label: 'Prosedur yang dilakukan',
                            },
                            outcome: {
                                code: 'NO_RELIABLE_CANDIDATE',
                                label: 'Tidak ada kandidat andal',
                            },
                            engineType: 'DETERMINISTIC_LEXICAL',
                            engineVersion: 'coding-reference.v1',
                            configurationHash: 'd'.repeat(64),
                            normalizedInputHash: 'e'.repeat(64),
                            generatedAt: '2026-07-15T10:00:00+07:00',
                            release: {
                                publicId: '01J00000000000000000000008',
                                system: 'ICD-9-CM',
                                logicalVersion: 'ICD9CM_2010',
                                sourceSha256: '9'.repeat(64),
                            },
                            canDecide: true,
                            decisionUrl:
                                '/coding-suggestion-runs/example/decisions',
                            candidates: [],
                            decisions: [],
                        },
                        assignmentHistory: [],
                    },
                ]}
                selectedSourcePublicId={procedurePublicId}
                manualSearch={{
                    query: '',
                    system: 'ICD_9_CM',
                    error: null,
                    results: [],
                }}
            />,
        );

        expect(
            screen.getAllByText(
                'Pengambilan sampel darah vena untuk pemeriksaan sintetis.',
            ),
        ).toHaveLength(2);
        expect(
            screen.getAllByText('Petugas laboratorium simulasi'),
        ).not.toHaveLength(0);
        expect(screen.getByText('Vena lengan kanan')).toBeInTheDocument();
        expect(
            screen.getByRole('combobox', { name: 'Sistem klasifikasi' }),
        ).toHaveValue('ICD_9_CM');
        expect(
            screen.getByRole('button', { name: 'Tolak saran' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('VAL-A16');
        expect(screen.getByRole('status')).toHaveTextContent(
            'Tidak ada kandidat andal',
        );
        expect(
            screen.getByRole('button', {
                name: 'Minta koreksi prosedur',
            }),
        ).toBeDisabled();
    });
});
