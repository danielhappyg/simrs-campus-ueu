import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DiagnosisTable } from './diagnosis-table';

const catalogue = [
    { code: 'A09', display: 'Infectious gastroenteritis', system: 'ICD-10' },
    { code: 'E11', display: 'Type 2 diabetes mellitus', system: 'ICD-10' },
    { code: 'I10', display: 'Essential hypertension', system: 'ICD-10' },
];

function renderTable(
    overrides: Partial<Parameters<typeof DiagnosisTable>[0]> = {},
) {
    const onChange = vi.fn();
    render(
        <DiagnosisTable
            primary={null}
            secondary={[]}
            lookupUrl="/terminology"
            disabled={false}
            onChange={onChange}
            {...overrides}
        />,
    );

    return onChange;
}

function ControlledTable() {
    const [values, setValues] = useState({
        primary: null as { code: string; display: string } | null,
        secondary: [] as { code: string; display: string }[],
    });

    return (
        <DiagnosisTable
            {...values}
            lookupUrl="/terminology"
            disabled={false}
            onChange={setValues}
        />
    );
}

describe('DiagnosisTable', () => {
    it('only changes diagnoses after explicit add and guards duplicate codes', async () => {
        const user = userEvent.setup();
        const onChange = renderTable();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ options: catalogue }),
            }),
        );

        await user.type(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
            'A0',
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: /A09/ }),
            ).toBeInTheDocument(),
        );
        await user.click(screen.getByRole('button', { name: /A09/ }));
        expect(onChange).not.toHaveBeenCalled();
        await user.type(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
            'I1',
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Add diagnosis' }),
            ).toBeDisabled(),
        );
        await user.clear(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
        );
        await user.type(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
            'A0',
        );
        await user.click(
            (await screen.findAllByRole('button', { name: /^A09/ }))[0],
        );
        await user.click(screen.getByRole('button', { name: 'Add diagnosis' }));
        expect(onChange).toHaveBeenLastCalledWith({
            primary: { code: 'A09', display: 'Infectious gastroenteritis' },
            secondary: [],
        });

        vi.unstubAllGlobals();
    });

    it('prevents duplicate codes after a controlled update and clears a stale selection on a new query', async () => {
        const user = userEvent.setup();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ options: catalogue }),
            }),
        );
        render(<ControlledTable />);
        const search = screen.getByLabelText('Search ICD-10 code or diagnosis');
        await user.type(search, 'A0');
        await user.click(
            (await screen.findAllByRole('button', { name: /^A09/ }))[0],
        );
        await user.click(screen.getByRole('button', { name: 'Add diagnosis' }));
        expect(screen.getByLabelText('Type')).toHaveValue('SECONDARY');
        await user.type(search, 'I1');
        expect(
            screen.getByRole('button', { name: 'Add diagnosis' }),
        ).toBeDisabled();
        await user.clear(search);
        await user.type(search, 'A0');
        await user.click(
            (await screen.findAllByRole('button', { name: /^A09/ }))[0],
        );
        await user.click(screen.getByRole('button', { name: 'Add diagnosis' }));
        expect(screen.getByRole('status')).toHaveTextContent(
            'already in the table',
        );
        vi.unstubAllGlobals();
    });

    it('promotes a secondary without dropping the former primary and supports cancel editing', async () => {
        const user = userEvent.setup();
        const onChange = renderTable({
            primary: { code: 'A09', display: 'Infectious gastroenteritis' },
            secondary: [{ code: 'I10', display: 'Essential hypertension' }],
        });
        await user.click(screen.getByRole('button', { name: 'Make primary' }));
        expect(onChange).toHaveBeenLastCalledWith({
            primary: { code: 'I10', display: 'Essential hypertension' },
            secondary: [{ code: 'A09', display: 'Infectious gastroenteritis' }],
        });
        await user.click(screen.getByRole('button', { name: 'Edit A09' }));
        expect(
            screen.getByRole('button', { name: 'Update diagnosis' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('button', { name: 'Add diagnosis' }),
        ).toBeDisabled();
    });

    it('makes all controls read-only when disabled', () => {
        renderTable({
            disabled: true,
            primary: { code: 'A09', display: 'Infectious gastroenteritis' },
        });
        expect(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
        ).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Edit A09' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Remove A09' }),
        ).toBeDisabled();
    });

    it('defaults the next entry to secondary and blocks a new primary that would create a 21st secondary', async () => {
        const user = userEvent.setup();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ options: catalogue }),
            }),
        );
        const onChange = renderTable({
            primary: { code: 'A09', display: 'Infectious gastroenteritis' },
            secondary: Array.from({ length: 20 }, (_, index) => ({
                code: `S${index}`,
                display: `Secondary ${index}`,
            })),
        });
        expect(screen.getByLabelText('Type')).toHaveValue('SECONDARY');
        await user.type(
            screen.getByLabelText('Search ICD-10 code or diagnosis'),
            'I1',
        );
        await user.click(
            (await screen.findAllByRole('button', { name: /^I10/ }))[0],
        );
        await user.selectOptions(screen.getByLabelText('Type'), 'PRIMARY');
        await user.click(screen.getByRole('button', { name: 'Add diagnosis' }));
        expect(onChange).not.toHaveBeenCalled();
        vi.unstubAllGlobals();
    });
});
