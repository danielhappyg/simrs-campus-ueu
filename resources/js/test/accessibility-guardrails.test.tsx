import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { Checkbox } from '@/components/ui/checkbox';

function pageFiles(directory: string): string[] {
    return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
        const path = join(directory, entry.name);

        if (entry.isDirectory()) {
            return pageFiles(path);
        }

        return entry.isFile() && entry.name.endsWith('.tsx') ? [path] : [];
    });
}

describe('accessibility guardrails', () => {
    it('keeps the application layout as the sole main landmark owner', () => {
        const offenders = pageFiles(resolve('resources/js/pages')).filter(
            (file) => /<main\b/.test(readFileSync(file, 'utf8')),
        );

        expect(offenders).toEqual([]);
    });

    it('renders one named native checkbox and reports its checked state', async () => {
        const user = userEvent.setup();
        const onCheckedChange = vi.fn();

        render(
            <div>
                <Checkbox
                    id="remember"
                    name="remember"
                    onCheckedChange={onCheckedChange}
                />
                <label htmlFor="remember">Ingat sesi saya</label>
            </div>,
        );

        const checkbox = screen.getByRole('checkbox', {
            name: 'Ingat sesi saya',
        });

        expect(screen.getAllByRole('checkbox')).toHaveLength(1);
        await user.click(checkbox);
        expect(checkbox).toBeChecked();
        expect(onCheckedChange).toHaveBeenLastCalledWith(true);
    });

    it('preserves native form submission semantics for checked values', () => {
        render(
            <form aria-label="preferences">
                <Checkbox
                    id="remember-form"
                    name="remember"
                    value="yes"
                    defaultChecked
                />
                <label htmlFor="remember-form">Ingat sesi saya</label>
            </form>,
        );

        const form = screen.getByRole('form', {
            name: 'preferences',
        }) as HTMLFormElement;

        expect(new FormData(form).get('remember')).toBe('yes');
    });

    it('keeps the shared narrow and coarse-pointer touch-target rule', () => {
        const stylesheet = readFileSync(
            resolve('resources/css/app.css'),
            'utf8',
        );

        expect(stylesheet).toContain(
            '@media (max-width: 47.999rem), (pointer: coarse)',
        );
        expect(stylesheet).toContain('min-block-size: 2.75rem');
        expect(stylesheet).toContain('min-inline-size: 2.75rem');
        expect(stylesheet).toContain("[data-slot='breadcrumb-link']");
    });

});
