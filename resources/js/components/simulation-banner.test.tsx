import { render } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { SimulationBanner } from '@/components/simulation-banner';

describe('SimulationBanner', () => {
    it('states the simulation boundary and has no detected accessibility violations', async () => {
        const { container, getByText } = render(
            <SimulationBanner
                banner="SIMULASI — DATA SINTETIS"
                restriction="Tidak untuk pelayanan pasien nyata"
                mode="SIMULATION"
            />,
        );

        expect(getByText('SIMULASI — DATA SINTETIS')).toBeInTheDocument();
        expect(
            getByText('Tidak untuk pelayanan pasien nyata'),
        ).toBeInTheDocument();

        const result = await axe.run(container);
        expect(result.violations).toHaveLength(0);
    });
});
