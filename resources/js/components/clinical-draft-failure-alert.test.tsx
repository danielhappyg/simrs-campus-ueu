import { render, screen } from '@testing-library/react';
import axe from 'axe-core';
import { describe, expect, it } from 'vitest';
import { ClinicalDraftFailureAlert } from '@/components/clinical-draft-failure-alert';

describe('ClinicalDraftFailureAlert', () => {
    it('keeps ordinary save failures generic and does not offer reauthentication', async () => {
        const { container } = render(
            <ClinicalDraftFailureAlert failure="GENERIC" />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Draf belum tersimpan',
        );
        expect(screen.getByRole('alert')).not.toHaveTextContent(
            'Sesi masuk perlu dipulihkan',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect((await axe.run(container)).violations).toHaveLength(0);
    });

    it('offers a separate-tab login without copying clinical content', async () => {
        const { container } = render(
            <ClinicalDraftFailureAlert failure="REAUTHENTICATION_REQUIRED" />,
        );

        const alert = screen.getByRole('alert');
        const loginLink = screen.getByRole('link', {
            name: 'Buka halaman masuk di tab baru',
        });

        expect(alert).toHaveTextContent('Sesi masuk perlu dipulihkan');
        expect(alert).toHaveTextContent(
            'Perubahan tetap berada di memori tab ini',
        );
        expect(alert).toHaveTextContent(
            'Server akan memeriksa kembali peran, sesi, encounter, dan versi saat ini',
        );
        expect(loginLink).toHaveAttribute('href', '/login');
        expect(loginLink).toHaveAttribute('target', '_blank');
        expect(loginLink).toHaveAttribute('rel', 'noopener noreferrer');
        expect((await axe.run(container)).violations).toHaveLength(0);
    });
});
