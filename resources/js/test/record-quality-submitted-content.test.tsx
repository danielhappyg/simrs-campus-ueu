import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { SubmittedRecordQualityContent } from '@/pages/record-quality/workspace';
import type { RecordQualityReviewVersion } from '@/types';

function submittedReview(): RecordQualityReviewVersion {
    return {
        publicId: '01TESTRECORDQUALITY000001',
        versionNumber: 1,
        checklistVersion: 'OPD-COMP-v2',
        status: { code: 'SUBMITTED', label: 'Diajukan' },
        content: {
            checklistVersion: 'OPD-COMP-v2',
            completeness: {
                checklistVersion: 'OPD-COMP-v2',
                ready: true,
                checks: [
                    {
                        code: 'CLOSURE_APPROVED',
                        label: 'Versi penutupan saat ini disetujui',
                        passed: true,
                        blocking: false,
                        detail: 'Versi penutupan telah disetujui untuk simulasi.',
                        evidence: [
                            {
                                closureContentHash:
                                    'be8ca50ea14161c3bec881ad2f39fff533334cf1846c776ef4694ce6be79d0e4',
                            },
                        ],
                    },
                ],
            },
            assemblySnapshot: {
                closure: {
                    publicId: '01TESTCLOSURE000000000001',
                    versionNumber: 1,
                    schemaVersion: 'encounter-closure.v2',
                    status: 'APPROVED',
                    contentHash:
                        'be8ca50ea14161c3bec881ad2f39fff533334cf1846c776ef4694ce6be79d0e4',
                },
                clinicalSources: {},
            },
            manualFindings: [],
            resolvedCorrections: [],
            codingDocumentationCorrection: null,
            procedureDocumentationCorrection: null,
        },
        contentHash:
            '52cc8ecaba5ad0c7e4431ea0f5608cd1b83447c4c2957915b8026052d9c59891',
        reviewer: 'Koder RMIK Demo',
        reviewerRole: 'Koder RMIK',
        recordedAt: '2026-07-22T06:16:00+07:00',
        submittedAt: '2026-07-22T06:16:00+07:00',
        reviewedAt: null,
        changeReason: null,
        findings: [],
        reviews: [],
    };
}

describe('SubmittedRecordQualityContent', () => {
    it('shows the immutable submitted checklist and its closure source binding', async () => {
        const { container } = render(
            <SubmittedRecordQualityContent version={submittedReview()} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Isi telaah RMIK yang diajukan',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Koder RMIK Demo')).toBeVisible();
        expect(screen.getByText('Siap saat diajukan')).toBeVisible();
        expect(
            screen.getByText('Versi penutupan saat ini disetujui'),
        ).toBeVisible();
        expect(
            screen.getByText('Versi penutupan telah disetujui untuk simulasi.'),
        ).toBeVisible();
        expect(screen.getByText('v1 · APPROVED')).toBeVisible();
        expect(
            screen.getByText(
                'be8ca50ea14161c3bec881ad2f39fff533334cf1846c776ef4694ce6be79d0e4',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Tidak ada temuan manual pada versi yang diajukan.',
            ),
        ).toBeVisible();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });
});
