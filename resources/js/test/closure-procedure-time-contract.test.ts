import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

describe('closure procedure time contract', () => {
    const source = readFileSync(
        resolve('resources/js/pages/clinical/closure.tsx'),
        'utf8',
    );

    it('retains exact procedure instants when a closure successor is authored', () => {
        expect(source).toMatch(
            /dateTimeFormValue\(\s*procedure\.performedStartAt,\s*true,\s*\)/,
        );
        expect(source).toMatch(
            /dateTimeFormValue\(\s*procedure\.performedEndAt,\s*true,?\s*\)/,
        );
    });

    it('allows second-precision native procedure input for start and end', () => {
        const procedureDateTimeInputs = [
            ...source.matchAll(
                /id=\{`procedure_\$\{index\}_(?:start|end)`\}[\s\S]*?type="datetime-local"[\s\S]*?step=\{\s*1\s*\}/g,
            ),
        ];

        expect(procedureDateTimeInputs).toHaveLength(2);
    });

    it('merges rapid procedure field edits against the latest form state', () => {
        expect(source).toMatch(
            /function updateProcedure\([\s\S]*?form\.setData\(\(previousData\) => \(\{[\s\S]*?procedures: previousData\.procedures\.map/,
        );
    });

    it('commits both native date-time controls when their browser editor blurs', () => {
        const procedureBlurHandlers = [
            ...source.matchAll(
                /onBlur=\{\s*\(\s*event,\s*\)\s*=>\s*updateProcedure\([\s\S]*?'performed_(?:start|end)_at',[\s\S]*?event\s*\.target\s*\.value,[\s\S]*?\)\s*\}/g,
            ),
        ];

        expect(procedureBlurHandlers).toHaveLength(2);
    });
});
