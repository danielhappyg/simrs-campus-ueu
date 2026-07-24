import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { SubmittedClosureContent } from '@/pages/clinical/closure';
import type { EncounterClosureVersion } from '@/types';

function submittedVersion(
    procedureDocumentation: EncounterClosureVersion['content']['procedureDocumentation'] = {
        state: 'NONE_PERFORMED',
        procedures: [],
    },
): EncounterClosureVersion {
    return {
        publicId: '01TESTCLOSURE000000000001',
        versionNumber: 1,
        schemaVersion: 'encounter-closure.v2',
        contentHash:
            'be8ca50ea14161c3bec881ad2f39fff533334cf1846c776ef4694ce6be79d0e4',
        status: { code: 'SUBMITTED', label: 'Diajukan' },
        author: 'Mahasiswa Kedokteran Simulasi',
        authorRole: 'Peserta didik',
        clinicalOccurrenceAt: '2026-07-22T09:30:00+07:00',
        recordedAt: '2026-07-22T09:35:00+07:00',
        submittedAt: '2026-07-22T09:36:00+07:00',
        reviewedAt: null,
        changeReason: null,
        reviews: [],
        content: {
            authored: {
                leavingCondition:
                    'Kondisi stabil, sadar penuh, dan tidak tampak distress.',
                disposition: 'Pulang dari poliklinik simulasi.',
                followUpPlan: 'Evaluasi ulang dalam 2–3 hari simulasi.',
                referralPlan: null,
                educationInstructions:
                    'Istirahat, hidrasi, dan kembali bila kondisi memburuk.',
                outpatientSummary:
                    'Asesmen, telaah farmasi manusia, dan outcome obat telah ditinjau.',
            },
            procedureDocumentation,
            sourceSnapshot: {
                nursing: null,
                medical: null,
                diagnoses: [],
                results: [],
                medications: [],
            },
            readinessAtAuthoring: { ready: true, checks: [] },
        },
    };
}

describe('SubmittedClosureContent', () => {
    it('shows every authored closure field and the explicit no-procedure attestation', async () => {
        const { container } = render(
            <SubmittedClosureContent version={submittedVersion()} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Isi versi penutupan yang diajukan',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Mahasiswa Kedokteran Simulasi')).toBeVisible();
        expect(
            screen.getByText(
                'Kondisi stabil, sadar penuh, dan tidak tampak distress.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText('Pulang dari poliklinik simulasi.'),
        ).toBeVisible();
        expect(
            screen.getByText('Evaluasi ulang dalam 2–3 hari simulasi.'),
        ).toBeVisible();
        expect(screen.getByText('Tidak dicatat')).toBeVisible();
        expect(
            screen.getByText(
                'Istirahat, hidrasi, dan kembali bila kondisi memburuk.',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Asesmen, telaah farmasi manusia, dan outcome obat telah ditinjau.',
            ),
        ).toBeVisible();
        expect(screen.getByText('Tidak ada tindakan dilakukan')).toBeVisible();
        expect(
            screen.getByText(/Penulis menyatakan tidak ada tindakan/),
        ).toBeVisible();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });

    it('shows the immutable details and provenance of a recorded procedure', () => {
        render(
            <SubmittedClosureContent
                version={submittedVersion({
                    state: 'PROCEDURES_RECORDED',
                    procedures: [
                        {
                            publicId: '01TESTPROCEDURE0000000001',
                            sequenceNumber: 1,
                            status: 'COMPLETED',
                            authoredText: 'Pengambilan sampel darah vena.',
                            performedStartAt: '2026-07-22T09:10:00+07:00',
                            performedEndAt: '2026-07-22T09:12:00+07:00',
                            performerText: 'Mahasiswa Kedokteran Simulasi',
                            bodySiteText: 'Vena median cubital kanan',
                            outcomeText: 'Sampel sintetis berhasil diperoleh',
                            note: 'Tanpa komplikasi simulasi',
                            reasonConditionPublicId:
                                '01TESTCONDITION0000000001',
                            basedOnServiceRequestPublicId:
                                '01TESTSERVICEREQUEST00001',
                            contentHash:
                                '731ac330e6141329ba7df739a7c9c03477f13746df1163a205113e1558c16532',
                        },
                    ],
                })}
            />,
        );

        expect(screen.getByText('Tindakan tercatat')).toBeVisible();
        expect(
            screen.getByText('1. Pengambilan sampel darah vena.'),
        ).toBeVisible();
        expect(screen.getByText('Vena median cubital kanan')).toBeVisible();
        expect(
            screen.getByText('Sampel sintetis berhasil diperoleh'),
        ).toBeVisible();
        expect(screen.getByText('01TESTCONDITION0000000001')).toBeVisible();
        expect(screen.getByText('01TESTSERVICEREQUEST00001')).toBeVisible();
        expect(
            screen.getByText(/731ac330e6141329ba7df739a7c9c034/),
        ).toBeVisible();
    });
});
