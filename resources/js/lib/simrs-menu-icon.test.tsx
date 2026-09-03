import { describe, expect, it } from 'vitest';
import { resolveMenuIcon } from '@/lib/simrs-menu-icon';

describe('resolveMenuIcon', () => {
    it('maps clinical domains to distinct icon families', () => {
        expect(resolveMenuIcon('Bangsal', 'adm-bangsal').icon.displayName).toBe(
            'BedDouble',
        );
        expect(
            resolveMenuIcon('Pemeriksaan Lab', 'adm-lab').icon.displayName,
        ).toBe('FlaskConical');
        expect(
            resolveMenuIcon('Pemeriksaan Radiologi', 'adm-rad').icon.displayName,
        ).toBe('ScanLine');
        expect(resolveMenuIcon('Tarif', 'adm-tarif').icon.displayName).toBe(
            'Receipt',
        );
        expect(resolveMenuIcon('IGD', 'pemeriksaan-ugd').icon.displayName).toBe(
            'HeartPulse',
        );
        expect(resolveMenuIcon('Obat', 'apotek-obat').icon.displayName).toBe(
            'Pill',
        );
    });

    it('keeps soft UEU-toned plates instead of solid letter chips', () => {
        const bangsal = resolveMenuIcon('Bangsal');
        expect(bangsal.tone.plate).toContain('bg-[');
        expect(bangsal.tone.ink).toContain('text-[');
    });
});
