import { describe, expect, it } from 'vitest';
import {
    dateTimeFormValue,
    dateTimeLocalDisplay,
} from '@/lib/clinical-date-time';

describe('clinical date-time form values', () => {
    const exactInstant = '2026-07-16T14:52:37+07:00';

    it('keeps the exact instant in locked correction form state', () => {
        expect(dateTimeFormValue(exactInstant, true)).toBe(exactInstant);
    });

    it('uses minute precision for editable datetime-local state', () => {
        expect(dateTimeFormValue(exactInstant, false)).toBe('2026-07-16T14:52');
    });

    it('shows a datetime-local compatible value without mutating stored state', () => {
        expect(dateTimeLocalDisplay(exactInstant)).toBe('2026-07-16T14:52');
    });
});
