import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import LegacyFreeTextEncounterShow from '@/components/clinical/legacy-free-text-encounter-show';

const inertiaMock = vi.hoisted(() => ({
    post: vi.fn(),
    flash: {} as { error?: string; success?: string },
    entryErrors: {
        entry_type: 'Jenis catatan tidak valid.',
        body: 'Isi catatan wajib diisi.',
    },
    labOrderErrors: {
        test_code: 'Pemeriksaan tidak valid.',
        clinical_question: 'Pertanyaan klinis terlalu panjang.',
    },
}));

type MockPostOptions = {
    onError?: (errors: Record<string, string>) => void;
    onSuccess?: () => void;
};

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        Link: (
            props: Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & {
                children?: ReactNode;
                href: string | { url: string };
                prefetch?: boolean;
                preserveScroll?: boolean;
                preserveState?: boolean;
                replace?: boolean;
            },
        ) => {
            const anchorProps = { ...props };
            delete anchorProps.prefetch;
            delete anchorProps.preserveScroll;
            delete anchorProps.preserveState;
            delete anchorProps.replace;
            const { children, href, ...attributes } = anchorProps;

            return (
                <a
                    href={typeof href === 'string' ? href : href.url}
                    {...attributes}
                >
                    {children}
                </a>
            );
        },
        useForm: function useForm<T extends Record<string, unknown>>(
            initialData: T,
        ) {
            const [data, setDataState] = React.useState(initialData);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const isLabOrder = 'test_code' in initialData;

            const setData = (
                keyOrData: string | T | ((current: T) => T),
                value?: unknown,
            ) => {
                if (typeof keyOrData === 'function') {
                    setDataState(keyOrData);
                } else if (typeof keyOrData === 'string') {
                    setDataState((current) => ({
                        ...current,
                        [keyOrData]: value,
                    }));
                } else {
                    setDataState(keyOrData);
                }
            };

            return {
                data,
                errors,
                processing: false,
                setData,
                post: (url: string, options?: MockPostOptions) => {
                    const serverErrors = isLabOrder
                        ? inertiaMock.labOrderErrors
                        : inertiaMock.entryErrors;
                    inertiaMock.post(url);
                    setErrors(serverErrors);
                    options?.onError?.(serverErrors);
                },
                reset: vi.fn(),
                transform: vi.fn(),
            };
        },
        usePage: () => ({ props: { flash: inertiaMock.flash } }),
    };
});

function renderEncounter() {
    return render(
        <main>
            <LegacyFreeTextEncounterShow
                encounter={{
                    public_id: 'encounter-1',
                    status: 'IN_EXAMINATION',
                    clinic_name: 'Poli Umum',
                    doctor_name: 'Dr. Sintetis',
                    schedule_label: 'Kamis 08.00–12.00',
                    payer_type: 'UMUM',
                    queue_number: 7,
                    registered_at: '2026-08-27T08:00:00+07:00',
                    visit_date: '2026-08-27',
                    chief_complaint: 'Keluhan sintetis',
                    patient: {
                        public_id: 'patient-1',
                        medical_record_number: 'SYNTH-001',
                        full_name: 'Pasien Sintetis',
                        date_of_birth: '1990-01-01',
                        sex: 'LAKI_LAKI',
                        nik: '3173000000000001',
                    },
                    entries: [],
                    lab_orders: [],
                }}
                entryTypeOptions={[
                    {
                        value: 'NURSING_INTAKE',
                        label: 'Asesmen keperawatan',
                        allowed: true,
                    },
                ]}
                labTestOptions={[{ code: 'HB', label: 'Hemoglobin' }]}
                canCreateLabOrder
                canWriteNursing
                canWriteMedical={false}
            />
        </main>,
    );
}

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: {
            'color-contrast': { enabled: false },
        },
    });

    expect(
        result.violations,
        result.violations
            .map(
                (violation) =>
                    `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
            )
            .join('\n'),
    ).toHaveLength(0);
}

describe('legacy free-text encounter accessibility', () => {
    beforeEach(() => {
        inertiaMock.post.mockClear();
        inertiaMock.flash = {};
    });

    it('announces flash messages and exposes keyboard-operable live tabs without activating stubs', async () => {
        inertiaMock.flash = {
            error: 'Tindakan belum dapat disimpan.',
            success: 'Data sintetis tersimpan.',
        };
        const { container } = renderEncounter();

        const flashAlert = screen.getByRole('alert');
        const flashStatus = screen.getByRole('status');
        expect(flashAlert).toHaveTextContent('Tindakan belum dapat disimpan.');
        expect(flashAlert).toHaveAttribute('aria-live', 'assertive');
        expect(flashStatus).toHaveTextContent('Data sintetis tersimpan.');
        expect(flashStatus).toHaveAttribute('aria-live', 'polite');

        expect(
            screen.getByRole('tablist', {
                name: 'Bagian pemeriksaan klinis',
            }),
        ).toBeInTheDocument();
        const assessmentTab = screen.getByRole('tab', { name: 'Asesmen' });
        const labTab = screen.getByRole('tab', { name: 'Order Lab' });
        const diagnosisStub = screen.getByRole('tab', {
            name: /Diagnosa.*stub/,
        });

        expect(assessmentTab).toHaveAttribute('aria-selected', 'true');
        expect(assessmentTab).toHaveAttribute(
            'aria-controls',
            'clinical-tabpanel-asesmen',
        );
        expect(diagnosisStub).toBeDisabled();
        expect(diagnosisStub).toHaveAttribute('aria-disabled', 'true');
        expect(diagnosisStub).toHaveAttribute('aria-selected', 'false');

        assessmentTab.focus();
        fireEvent.keyDown(assessmentTab, { key: 'ArrowRight' });

        expect(labTab).toHaveFocus();
        expect(labTab).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.getByRole('tabpanel', { name: 'Order Lab' }),
        ).toHaveAttribute('id', 'clinical-tabpanel-order-lab');
        expect(diagnosisStub).toBeDisabled();

        await expectNoWcag21Violations(container);
    });

    it('associates clinical-note errors and refocuses its linked summary after repeated failures', async () => {
        const { container } = renderEncounter();
        const saveButton = screen.getByRole('button', {
            name: 'Simpan catatan',
        });
        const form = saveButton.closest('form');

        if (!form) {
            throw new Error('Clinical note form was not rendered.');
        }

        fireEvent.submit(form);

        const summary = await screen.findByRole('alert', {
            name: 'Catatan klinis belum dapat disimpan.',
        });
        const entryType = screen.getByRole('combobox', {
            name: 'Jenis catatan',
        });
        const body = screen.getByRole('textbox', { name: 'Isi catatan' });

        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(summary).toHaveFocus();
        expect(entryType).toHaveAttribute('aria-invalid', 'true');
        expect(entryType).toHaveAttribute(
            'aria-describedby',
            'clinical-entry-type-error',
        );
        expect(body).toHaveAttribute('aria-invalid', 'true');
        expect(body).toHaveAttribute(
            'aria-describedby',
            'clinical-entry-body-error',
        );
        expect(
            document.getElementById('clinical-entry-body-error'),
        ).toHaveTextContent('Isi catatan wajib diisi.');

        const bodyErrorLink = within(summary).getByRole('link', {
            name: 'Isi catatan: Isi catatan wajib diisi.',
        });
        expect(bodyErrorLink).toHaveAttribute('href', '#body');
        fireEvent.click(bodyErrorLink);
        expect(body).toHaveFocus();

        fireEvent.submit(form);
        await waitFor(() => expect(summary).toHaveFocus());
        expect(inertiaMock.post).toHaveBeenCalledTimes(2);

        await expectNoWcag21Violations(container);
    });

    it('associates laboratory-order errors and refocuses its linked summary after repeated failures', async () => {
        const { container } = renderEncounter();
        fireEvent.click(screen.getByRole('tab', { name: 'Order Lab' }));

        const saveButton = screen.getByRole('button', {
            name: 'Simpan order lab',
        });
        const form = saveButton.closest('form');

        if (!form) {
            throw new Error('Laboratory order form was not rendered.');
        }

        fireEvent.submit(form);

        const summary = await screen.findByRole('alert', {
            name: 'Order laboratorium belum dapat disimpan.',
        });
        const testCode = screen.getByRole('combobox', {
            name: 'Pemeriksaan',
        });
        const clinicalQuestion = screen.getByRole('textbox', {
            name: 'Pertanyaan klinis (opsional)',
        });

        expect(summary).toHaveFocus();
        expect(testCode).toHaveAttribute('aria-invalid', 'true');
        expect(testCode).toHaveAttribute(
            'aria-describedby',
            'lab-order-test-code-error',
        );
        expect(clinicalQuestion).toHaveAttribute('aria-invalid', 'true');
        expect(clinicalQuestion).toHaveAttribute(
            'aria-describedby',
            'lab-order-clinical-question-error',
        );

        const questionErrorLink = within(summary).getByRole('link', {
            name: 'Pertanyaan klinis: Pertanyaan klinis terlalu panjang.',
        });
        fireEvent.click(questionErrorLink);
        expect(clinicalQuestion).toHaveFocus();

        fireEvent.submit(form);
        await waitFor(() => expect(summary).toHaveFocus());
        expect(inertiaMock.post).toHaveBeenCalledTimes(2);

        await expectNoWcag21Violations(container);
    });
});
