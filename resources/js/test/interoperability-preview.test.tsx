import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import OutpatientInteroperabilityPreview from '@/pages/encounter/interoperability-preview';

type MockLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
    href: string;
    children?: ReactNode;
};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: MockLinkProps) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const patientUrl =
    'https://simrs-campus-ueu.example.invalid/fhir/Patient/01J00000000000000000000001';
const encounterUrl =
    'https://simrs-campus-ueu.example.invalid/fhir/Encounter/01J00000000000000000000002';

const props = {
    boundary: {
        classification: 'SIMULASI — DATA SINTETIS',
        environment: 'SIMULATION',
        mode: 'LOCAL_MAPPING_PREVIEW',
        transportState: 'NOT_SENT',
        fhirVersion: '4.0.1',
        satusehatProfileStatus: 'NOT_CLAIMED',
        readyForTransmission: false,
        externalEndpoint: null,
    },
    summary: {
        resourceCount: 4,
        resourceTypeCounts: {
            Composition: 1,
            Condition: 1,
            Patient: 1,
            Procedure: 1,
        },
    },
    bundle: {
        resourceType: 'Bundle',
        id: '01J00000000000000000000002',
        type: 'collection',
        timestamp: '2026-07-17T08:00:00+00:00',
        entry: [
            {
                fullUrl: encounterUrl.replace('Encounter', 'Composition'),
                resource: {
                    resourceType: 'Composition',
                    id: '01J00000000000000000000002',
                    status: 'preliminary',
                    title: 'Local outpatient preview — not sent',
                },
            },
            {
                fullUrl: patientUrl,
                resource: {
                    resourceType: 'Patient',
                    id: '01J00000000000000000000001',
                    name: [{ text: 'Pasien Sintetis Arunika' }],
                },
            },
            {
                fullUrl:
                    'https://simrs-campus-ueu.example.invalid/fhir/Condition/01J00000000000000000000003',
                resource: {
                    resourceType: 'Condition',
                    id: '01J00000000000000000000003',
                    code: {
                        coding: [
                            {
                                code: 'R42',
                                display: 'Dizziness and giddiness',
                                extension: [
                                    {
                                        url: 'https://simrs-campus-ueu.example.invalid/fhir/StructureDefinition/human-reviewed',
                                        valueBoolean: true,
                                    },
                                ],
                            },
                        ],
                        text: 'Sindrom pusing dalam evaluasi',
                    },
                },
            },
            {
                fullUrl:
                    'https://simrs-campus-ueu.example.invalid/fhir/Procedure/01J00000000000000000000004',
                resource: {
                    resourceType: 'Procedure',
                    id: '01J00000000000000000000004',
                    code: {
                        coding: [
                            {
                                code: '38.99',
                                display: 'Other puncture of vein',
                                extension: [
                                    {
                                        url: 'https://simrs-campus-ueu.example.invalid/fhir/StructureDefinition/human-reviewed',
                                        valueBoolean: true,
                                    },
                                ],
                            },
                        ],
                        text: 'Pengambilan sampel darah vena',
                    },
                },
            },
        ],
    },
    sourceIndex: [
        {
            fullUrl: patientUrl,
            resourceType: 'Patient',
            resourceId: '01J00000000000000000000001',
            sourceType: 'synthetic_patient',
            sourcePublicId: '01J00000000000000000000001',
            sourcePath: 'patient',
        },
    ],
    validation: {
        status: 'PREVIEW_ONLY',
        readyForTransmission: false,
        structuralErrors: [],
        issues: [
            {
                code: 'PROFILE_VALIDATION_NOT_RUN',
                severity: 'warning',
                message:
                    'SATUSEHAT profile validation has not been run; no conformance claim is made.',
            },
            {
                code: 'NATIONAL_IDENTIFIERS_ABSENT',
                severity: 'warning',
                message:
                    'Required national identifiers are intentionally absent.',
            },
        ],
    },
    urls: {
        self: '/encounters/example/interoperability-preview',
        back: '/encounters/example/debrief',
        encounter: '/encounters/example',
        timeline: '/encounters/example/timeline',
    },
};

describe('Outpatient interoperability preview', () => {
    it('makes the local mapping and blocked transport boundary unmistakable', async () => {
        const { container } = render(
            <OutpatientInteroperabilityPreview {...props} />,
        );

        expect(
            screen.getByRole('heading', {
                name: 'Pratinjau Interoperabilitas Lokal',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('BELUM DIKIRIM')).toBeInTheDocument();
        expect(
            screen.getByText('SIMULASI — DATA SINTETIS'),
        ).toBeInTheDocument();
        expect(screen.getByText('Bundle · collection')).toBeInTheDocument();
        expect(screen.getAllByText('Condition')).not.toHaveLength(0);
        expect(
            screen.getByText('R42 · Dizziness and giddiness'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('38.99 · Other puncture of vein'),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Ditinjau manusia')).toHaveLength(2);
        expect(
            screen.getByText('PROFILE_VALIDATION_NOT_RUN'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Kirim ke SATUSEHAT/i),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /kirim|transmit|submit/i }),
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

    it('keeps raw JSON contained behind an explicit inspection control', async () => {
        const user = userEvent.setup();
        render(<OutpatientInteroperabilityPreview {...props} />);

        expect(
            screen.queryByText(/"resourceType": "Bundle"/),
        ).not.toBeInTheDocument();
        await user.click(
            screen.getByRole('button', { name: 'Lihat JSON bundle' }),
        );
        expect(
            screen.getByText(/"resourceType": "Bundle"/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Sembunyikan JSON bundle' }),
        ).toBeInTheDocument();
    });
});
