import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { EncounterOrbit } from '@/components/encounter-orbit';
import type { WorkTaskItem } from '@/types';

const task: WorkTaskItem = {
    publicId: '01J00000000000000000000000',
    type: 'NURSING_INTAKE',
    title: 'Asesmen awal',
    description: null,
    status: { code: 'READY', label: 'Siap' },
    priority: 1,
    sourceProgram: 'Keperawatan',
    availableAt: null,
    context: { synthetic: true },
    assignmentPublicId: '01J00000000000000000000001',
    sessionCode: 'SIM-001',
};

describe('EncounterOrbit', () => {
    it('marks the first actionable stage without claiming unassigned progress', () => {
        render(<EncounterOrbit tasks={[task]} />);

        expect(screen.getByText('Asesmen awal').closest('li')).toHaveAttribute(
            'aria-current',
            'step',
        );
        expect(screen.getByText('Tugas aktif')).toBeInTheDocument();
        expect(screen.getByText('Farmasi').closest('li')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
