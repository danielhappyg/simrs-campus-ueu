import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ClinicalVersionStamp } from '@/components/clinical-version-stamp';

describe('ClinicalVersionStamp', () => {
    it('keeps the exact version, schema, approval scope, and complete hash visible', async () => {
        const hash = '6f'.repeat(32);
        const { container } = render(
            <ClinicalVersionStamp
                versionNumber={3}
                schemaVersion="nursing-intake.v1"
                contentHash={hash}
                status={{ code: 'SUBMITTED', label: 'Diajukan' }}
            />,
        );

        expect(
            screen.getByRole('region', {
                name: 'Identitas versi klinis 3',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Versi 3 · nursing-intake.v1'),
        ).toBeInTheDocument();
        expect(screen.getByText('Diajukan')).toBeInTheDocument();
        expect(screen.getByText(hash)).toBeInTheDocument();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });
});
