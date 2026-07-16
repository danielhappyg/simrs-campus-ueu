import { describe, expect, it } from 'vitest';
import { presentRecordedWorkflow } from '@/lib/encounter-workflow';

describe('presentRecordedWorkflow', () => {
    it('marks only recorded stages complete when the current state is an exception branch', () => {
        const presented = presentRecordedWorkflow(
            [
                { code: 'PLANNED', label: 'Terencana', current: false },
                { code: 'ARRIVED', label: 'Tiba', current: false },
                { code: 'IN_INTAKE', label: 'Asesmen awal', current: false },
                {
                    code: 'WAITING_CLINICIAN',
                    label: 'Menunggu klinisi',
                    current: false,
                },
            ],
            [
                { toStatusCode: 'PLANNED' },
                { toStatusCode: 'ARRIVED' },
                { toStatusCode: 'CANCELLED' },
            ],
        );

        expect(
            presented
                .filter((state) => state.completed)
                .map((state) => state.code),
        ).toEqual(['PLANNED', 'ARRIVED']);
        expect(
            presented.find((state) => state.code === 'IN_INTAKE')?.completed,
        ).toBe(false);
        expect(
            presented.find((state) => state.code === 'WAITING_CLINICIAN')
                ?.completed,
        ).toBe(false);
    });
});
