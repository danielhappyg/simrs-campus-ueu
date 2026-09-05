import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InpatientBedTransferPanel } from './inpatient-bed-transfer-panel';
import { InpatientLocationHistoryPanel } from './inpatient-location-history';
import type {
    InpatientBedTransferAction,
    InpatientLocationHistory,
    InpatientPlacementSnapshot,
} from './types';

const inertia = vi.hoisted(() => ({
    submissions: [] as Array<{ url: string; data: Record<string, unknown> }>,
    errors: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', () => ({
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setDataState] = React.useState(initial);
        const [errors, setErrors] = React.useState<Record<string, string>>({});
        const dataRef = React.useRef(data);
        dataRef.current = data;

        const setData = (
            keyOrUpdater: keyof T | T | ((current: T) => T),
            value?: T[keyof T],
        ) => {
            setDataState((current) => {
                const next =
                    typeof keyOrUpdater === 'function'
                        ? keyOrUpdater(current)
                        : typeof keyOrUpdater === 'object'
                          ? keyOrUpdater
                          : { ...current, [keyOrUpdater]: value };
                dataRef.current = next;

                return next;
            });
        };

        return {
            data,
            errors,
            processing: false,
            setData,
            setError: (key: string, message: string) =>
                setErrors((current) => ({ ...current, [key]: message })),
            clearErrors: (...keys: string[]) =>
                setErrors((current) =>
                    keys.length === 0
                        ? {}
                        : Object.fromEntries(
                              Object.entries(current).filter(
                                  ([key]) => !keys.includes(key),
                              ),
                          ),
                ),
            reset: (...keys: Array<keyof T>) =>
                setDataState((current) => ({
                    ...current,
                    ...Object.fromEntries(
                        keys.map((key) => [key, initial[key]]),
                    ),
                })),
            post: (
                url: string,
                options?: { onSuccess?: () => void; onError?: () => void },
            ) => {
                inertia.submissions.push({ url, data: { ...dataRef.current } });

                if (Object.keys(inertia.errors).length > 0) {
                    setErrors(inertia.errors);
                    options?.onError?.();
                } else {
                    options?.onSuccess?.();
                }
            },
        };
    },
}));

const source: InpatientPlacementSnapshot = {
    ward_public_id: '01WARD0000000000000000001',
    ward_code: 'MELATI',
    ward_display_name: 'Bangsal Melati',
    bed_public_id: '01BED00000000000000000001',
    bed_code: 'MEL-01',
    bed_display_name: 'Tempat Tidur 01',
    room_label: 'Ruang Melati',
    service_class: 'Kelas 1',
};

const target: InpatientPlacementSnapshot = {
    ...source,
    ward_public_id: '01WARD0000000000000000002',
    ward_code: 'ANGGREK',
    ward_display_name: 'Bangsal Anggrek',
    bed_public_id: '01BED00000000000000000002',
    bed_code: 'ANG-02',
    bed_display_name: 'Tempat Tidur 02',
    room_label: 'Ruang Anggrek',
};

function action(): InpatientBedTransferAction {
    return {
        allowed: true,
        url: '/pendaftaran/rawat-inap/episode/bed-transfer',
        expected_location_sequence: 2,
        expected_source_bed_public_id: source.bed_public_id,
        target_beds: [target],
    };
}

describe('inpatient bed transfer and location history', () => {
    beforeEach(() => {
        inertia.submissions = [];
        inertia.errors = {};
    });

    it('requires a deliberate target and reason, then posts only the frozen command fields', async () => {
        const user = userEvent.setup();
        render(
            <InpatientBedTransferPanel
                action={action()}
                disabledByUnsavedDocument={false}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Transfer Bed' }));
        const select = screen.getByLabelText('Destination bed');
        expect(select).toHaveValue('');
        expect(
            screen.getByRole('group', { name: 'Bangsal Anggrek (ANGGREK)' }),
        ).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Transfer Bed' }));
        expect(
            await screen.findByText('Select a destination bed.'),
        ).toBeVisible();
        expect(
            screen.getByText('The reason must contain 5–500 characters.'),
        ).toBeVisible();

        await user.selectOptions(select, target.bed_public_id);
        await user.type(
            screen.getByLabelText('Transfer reason'),
            'Kebutuhan pemantauan lebih dekat',
        );
        await user.click(screen.getByRole('button', { name: 'Transfer Bed' }));

        expect(inertia.submissions).toHaveLength(1);
        expect(inertia.submissions[0].url).toBe(action().url);
        expect(inertia.submissions[0].data).toMatchObject({
            expected_location_sequence: 2,
            expected_source_bed_public_id: source.bed_public_id,
            target_bed_public_id: target.bed_public_id,
            reason: 'Kebutuhan pemantauan lebih dekat',
        });
        expect(Object.keys(inertia.submissions[0].data).sort()).toEqual(
            [
                'expected_location_sequence',
                'expected_source_bed_public_id',
                'idempotency_key',
                'reason',
                'target_bed_public_id',
            ].sort(),
        );
    });

    it('blocks transfer while a daily document has unsaved changes', () => {
        render(
            <InpatientBedTransferPanel
                action={action()}
                disabledByUnsavedDocument
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Transfer Bed' }),
        ).toBeDisabled();
        expect(
            screen.getByText(
                'Save or discard daily-document changes before transferring the bed.',
            ),
        ).toBeVisible();
    });

    it('keeps a server denial visible when refreshed availability closes the form', async () => {
        const user = userEvent.setup();
        const view = render(
            <InpatientBedTransferPanel
                action={action()}
                disabledByUnsavedDocument={false}
            />,
        );
        await user.click(screen.getByRole('button', { name: 'Transfer Bed' }));
        await user.selectOptions(
            screen.getByLabelText('Destination bed'),
            target.bed_public_id,
        );
        await user.type(
            screen.getByLabelText('Transfer reason'),
            'Target berubah saat penyimpanan',
        );
        inertia.errors = {
            transfer: 'Lokasi telah berubah. Muat ulang sebelum melanjutkan.',
        };
        await user.click(screen.getByRole('button', { name: 'Transfer Bed' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Lokasi telah berubah. Muat ulang sebelum melanjutkan.',
        );

        view.rerender(
            <InpatientBedTransferPanel
                action={{
                    ...action(),
                    expected_location_sequence: 3,
                    target_beds: [],
                }}
                disabledByUnsavedDocument={false}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Lokasi telah berubah. Muat ulang sebelum melanjutkan.',
        );
        expect(
            screen.getByText(
                'No active bed is available in the same service class.',
            ),
        ).toBeVisible();
    });

    it('shows the honest legacy baseline and immutable from-to timeline accessibly', async () => {
        const history: InpatientLocationHistory = {
            history_baseline: 'LEGACY_CURRENT_PLACEMENT',
            history_complete: false,
            current_placement: target,
            current_sequence: 1,
            events: [
                {
                    public_id: '01EVENT0000000000000000001',
                    event_type: 'BED_TRANSFER',
                    sequence: 1,
                    from_placement: source,
                    to_placement: target,
                    actor: { public_id: '01ACTOR', name: 'Petugas Admisi' },
                    reason: 'Kebutuhan pemantauan lebih dekat',
                    request_correlation_id: null,
                    occurred_at: '2026-08-31T05:00:00+07:00',
                },
            ],
            transfer: action(),
        };
        const { container } = render(
            <InpatientLocationHistoryPanel history={history} />,
        );

        expect(
            screen.getByText('Starting point from the existing placement'),
        ).toBeVisible();
        expect(screen.getByText('Bed transfer')).toBeVisible();
        expect(screen.getByText('From')).toBeVisible();
        expect(screen.getByText('Ke')).toBeVisible();
        expect(
            screen.getByText('Kebutuhan pemantauan lebih dekat'),
        ).toBeVisible();

        const result = await axe.run(container, {
            rules: { 'color-contrast': { enabled: false } },
        });
        expect(result.violations).toEqual([]);
    });
});
