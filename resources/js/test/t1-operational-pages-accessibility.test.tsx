import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import LegacyFreeTextEncounterShow from '@/components/clinical/legacy-free-text-encounter-show';
import PemeriksaanLaboratoriumIndex from '@/pages/pemeriksaan/laboratorium';
import PemeriksaanRawatJalanIndex from '@/pages/pemeriksaan/rawat-jalan';
import PendaftaranRawatInap from '@/pages/pendaftaran/rawat-inap';
import PendaftaranRawatJalan from '@/pages/pendaftaran/rawat-jalan';
import PendaftaranRekap from '@/pages/pendaftaran/rekap';

const mockInertia = vi.hoisted(() => ({
    formErrors: {} as Record<string, string>,
    post: vi.fn(
        (
            _url: string,
            options?: { onError?: () => void; onSuccess?: () => void },
        ) => {
            options?.onError?.();
        },
    ),
}));

vi.mock('@inertiajs/react', () => ({
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
    router: { get: vi.fn() },
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: mockInertia.formErrors,
        processing: false,
        setData: vi.fn(),
        post: mockInertia.post,
        reset: vi.fn(),
        transform: vi.fn(),
    }),
    usePage: () => ({
        props: {
            auth: { capabilities: ['inpatient.occupancy.view'] },
            flash: {},
        },
    }),
}));

const firstPage = {
    current_page: 1,
    last_page: 2,
    per_page: 50,
    total: 51,
    from: 1,
    to: 50,
    prev_page_url: null,
    next_page_url: '/next',
};

async function expectNoWcag21Violations(container: HTMLElement) {
    const result = await axe.run(container, {
        runOnly: {
            type: 'tag',
            values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
        },
        rules: {
            // jsdom has no layout/rendering engine, so axe cannot calculate reliable color contrast here.
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

function expectTableScrollContainment(name: string) {
    const table = screen.getByRole('table', { name });
    const scrollContainer = table.parentElement;
    const section = table.closest('section');

    expect(scrollContainer).not.toBeNull();
    expect(scrollContainer).toHaveClass('min-w-0', 'overflow-x-auto');
    expect(section).not.toBeNull();
    expect(section).toHaveClass('min-w-0');
}

describe('T1 operational pages accessibility', () => {
    it('has no deterministic WCAG 2.1 A/AA violations on the laboratory worklist and result form', async () => {
        const user = userEvent.setup();
        const { container } = render(
            <main>
                <PemeriksaanLaboratoriumIndex
                    generated_at="2026-09-01T08:30:00+07:00"
                    orders={[
                        {
                            public_id: 'lab-order-1',
                            version: 3,
                            state: 'SPECIMEN_ACCEPTED',
                            priority: 'ROUTINE',
                            ordered_at: '2026-08-27T08:00:00+07:00',
                            ordering_physician_public_id: 'physician-1',
                            ordering_physician_name: 'Dr. Pengajar',
                            care_setting: 'OUTPATIENT',
                            care_location_label: 'Poli Umum',
                            encounter_number: 'RJ-001',
                            encounter_url:
                                '/pemeriksaan/rawat-jalan/encounter-1',
                            patient: {
                                medical_record_number: 'SYNTH-001',
                                display_name: 'Pasien Contoh',
                            },
                            examination: {
                                public_id: 'exam-hb',
                                code: 'HB',
                                display_name: 'Hemoglobin',
                                specimen_type: 'Darah EDTA',
                                collection_instruction: null,
                                components: [
                                    {
                                        code: 'HB',
                                        display_name: 'Hemoglobin',
                                        value_kind: 'NUMERIC',
                                        unit_text: 'g/dL',
                                        reference_text: '12–16',
                                        critical_allowed: true,
                                    },
                                ],
                            },
                            clinical_question: 'Skrining anemia',
                            cancellation: null,
                            specimens: [
                                {
                                    public_id: 'specimen-1',
                                    attempt_number: 1,
                                    label_identifier: 'LAB-0001',
                                    state: 'ACCEPTED',
                                    collected_at: '2026-08-27T08:05:00+07:00',
                                    collector_name: 'Perawat Sinta',
                                    collection_note: null,
                                    received_at: '2026-08-27T08:15:00+07:00',
                                    receiver_name: 'Analis Budi',
                                    assessed_at: '2026-08-27T08:16:00+07:00',
                                    assessor_name: 'Analis Budi',
                                    rejection_reason_label: null,
                                    rejection_note: null,
                                },
                            ],
                            accepted_specimen_public_id: 'specimen-1',
                            result: null,
                            actions: {
                                cancel_url: null,
                                collect_url: null,
                                receive_url: null,
                                accept_url: null,
                                reject_url: null,
                                save_result_url:
                                    '/pemeriksaan/laboratorium/lab-order-1/results',
                                verify_result_url: null,
                                amend_result_url: null,
                                acknowledge_url: null,
                            },
                        },
                    ]}
                    filters={{
                        q: 'SYNTH-001',
                        care_setting: '',
                        state: '',
                        priority: '',
                    }}
                    filter_options={{
                        care_settings: [],
                        states: [],
                        priorities: [],
                    }}
                    permissions={{
                        can_collect: false,
                        can_process_specimen: false,
                        can_save_result: true,
                        can_verify_result: false,
                    }}
                    rejection_reason_options={[]}
                    interpretation_options={[
                        { value: 'NORMAL', label: 'Normal' },
                        { value: 'ABNORMAL', label: 'Abnormal' },
                        { value: 'CRITICAL', label: 'Kritis' },
                    ]}
                    communication_method_options={[]}
                    communication_outcome_options={[]}
                    critical_communication_recipient_options={[]}
                    amendment_reason_options={[]}
                />
            </main>,
        );

        await user.click(
            screen.getByRole('button', { name: 'Compose Results' }),
        );

        await expectNoWcag21Violations(container);
    });

    it('has no deterministic WCAG 2.1 A/AA violations on the outpatient examination worklist', async () => {
        const { container } = render(
            <main>
                <PemeriksaanRawatJalanIndex
                    encounters={[
                        {
                            public_id: 'encounter-1',
                            status: 'REGISTERED',
                            clinic_name: 'Poli Umum',
                            doctor_name: 'Dr. Pengajar',
                            schedule_label: 'Kamis 08.00-12.00',
                            payer_type: 'UMUM',
                            queue_number: 1,
                            registered_at: '2026-08-27T07:45:00+07:00',
                            visit_date: '2026-08-27',
                            chief_complaint: 'Pusing ringan',
                            patient: {
                                public_id: 'patient-1',
                                medical_record_number: 'SYNTH-001',
                                full_name: 'Pasien Sintetis',
                                date_of_birth: '1990-01-01',
                                sex: 'LAKI_LAKI',
                            },
                        },
                    ]}
                    pagination={firstPage}
                    clinics={[{ value: 'clinic-1', label: 'Poli Umum' }]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                    filters={{
                        q: 'SYNTH-001',
                        clinic: 'clinic-1',
                        date_from: '2026-08-27',
                        date_to: '2026-08-27',
                        payer: 'UMUM',
                    }}
                    canOpen
                />
            </main>,
        );

        expectTableScrollContainment('Patients in the examination worklist');

        await expectNoWcag21Violations(container);
    });

    it.each([
        {
            desk: 'IGD',
            variant: 'igd' as const,
            clinicName: 'Instalasi Gawat Darurat',
            clinicOptions: [
                { value: 'igd-1', label: 'Instalasi Gawat Darurat' },
            ],
            continueFrom: null,
            wardName: null,
            wardClass: null,
            bedCode: null,
        },
        {
            desk: 'triage read-only',
            variant: 'triage' as const,
            clinicName: 'Instalasi Gawat Darurat',
            clinicOptions: [],
            continueFrom: null,
            wardName: null,
            wardClass: null,
            bedCode: null,
        },
        {
            desk: 'rawat inap',
            variant: 'rawat-inap' as const,
            clinicName: 'Bangsal Anggrek',
            clinicOptions: [
                { value: 'Bangsal Anggrek', label: 'Bangsal Anggrek' },
            ],
            continueFrom: 'LANGSUNG',
            wardName: 'Bangsal Anggrek',
            wardClass: 'Kelas 1',
            bedCode: 'ANG-101-A',
        },
    ])(
        'has no deterministic WCAG 2.1 A/AA violations on the $desk worklist variant',
        async ({
            variant,
            clinicName,
            clinicOptions,
            continueFrom,
            wardName,
            wardClass,
            bedCode,
        }) => {
            const { container } = render(
                <main>
                    <PemeriksaanRawatJalanIndex
                        variant={variant}
                        encounters={[
                            {
                                public_id: `encounter-${variant}`,
                                status: 'REGISTERED',
                                clinic_name: clinicName,
                                doctor_name: 'Dr. Sintetis',
                                schedule_label: 'Shift pagi',
                                ward_name: wardName,
                                ward_class: wardClass,
                                bed_code: bedCode,
                                continue_from: continueFrom,
                                payer_type: 'UMUM',
                                case_type: 'NON_TRAUMA',
                                accident_type: null,
                                queue_number: 2,
                                registered_at: '2026-08-27T08:15:00+07:00',
                                visit_date: '2026-08-27',
                                chief_complaint: 'Keluhan sintetis',
                                patient: {
                                    public_id: 'patient-variant-1',
                                    medical_record_number: 'SYNTH-002',
                                    full_name: 'Pasien Varian Sintetis',
                                    date_of_birth: '1992-02-02',
                                    sex: 'PEREMPUAN',
                                },
                            },
                        ]}
                        pagination={firstPage}
                        clinics={clinicOptions}
                        payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                        continueFromOptions={[
                            { value: 'LANGSUNG', label: 'Langsung' },
                        ]}
                        filters={{
                            q: 'SYNTH-002',
                            clinic:
                                variant === 'rawat-inap'
                                    ? 'Bangsal Anggrek'
                                    : 'igd-1',
                            date_from: '2026-08-27',
                            date_to: '2026-08-27',
                            payer: 'UMUM',
                            continue_from:
                                variant === 'rawat-inap' ? 'LANGSUNG' : '',
                        }}
                        canOpen
                        canAccessCorrections={variant === 'rawat-inap'}
                    />
                </main>,
            );

            expectTableScrollContainment(
                'Patients in the examination worklist',
            );

            if (variant === 'rawat-inap') {
                expect(
                    screen.getByRole('link', {
                        name: 'View bed availability',
                    }),
                ).toHaveAttribute('href', '/manajemen-data/bangsal');
                expect(
                    screen.getByRole('link', {
                        name: 'Correct discharge summary',
                    }),
                ).toHaveAttribute(
                    'href',
                    '/pemeriksaan/rawat-inap?scope=correction',
                );
            }

            await expectNoWcag21Violations(container);
        },
    );

    it('shows a bounded closed-episode correction worklist for physicians', async () => {
        const { container } = render(
            <main>
                <PemeriksaanRawatJalanIndex
                    variant="rawat-inap"
                    indexPath="/pemeriksaan/rawat-inap"
                    showPathPrefix="/pemeriksaan/rawat-inap"
                    encounters={[
                        {
                            public_id: 'encounter-closed-correction',
                            status: 'CLOSED',
                            clinic_name: 'Bangsal Anggrek',
                            doctor_name: 'Dr. Pengajar',
                            schedule_label: null,
                            ward_name: 'Bangsal Anggrek',
                            ward_class: 'Kelas 1',
                            bed_code: 'ANG-101-A',
                            continue_from: 'LANGSUNG',
                            payer_type: 'UMUM',
                            queue_number: null,
                            registered_at: '2026-08-27T08:15:00+07:00',
                            visit_date: '2026-08-27',
                            chief_complaint: 'Demam',
                            patient: {
                                public_id: 'patient-closed-correction',
                                medical_record_number: 'RM-0003',
                                full_name: 'Pasien Koreksi',
                                date_of_birth: '1992-02-02',
                                sex: 'PEREMPUAN',
                            },
                        },
                    ]}
                    clinics={[
                        {
                            value: 'Bangsal Anggrek',
                            label: 'Bangsal Anggrek',
                        },
                    ]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                    continueFromOptions={[
                        { value: 'LANGSUNG', label: 'Langsung' },
                    ]}
                    filters={{
                        q: '',
                        clinic: '',
                        date_from: '',
                        date_to: '',
                        payer: '',
                        continue_from: '',
                        scope: 'correction',
                    }}
                    canOpen
                    canAccessCorrections
                />
            </main>,
        );

        expect(
            screen.getByRole('table', {
                name: 'Episodes available for discharge-summary correction',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Closed')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open for Pasien Koreksi' }),
        ).toHaveAttribute(
            'href',
            '/pemeriksaan/rawat-inap/encounter-closed-correction',
        );

        await expectNoWcag21Violations(container);
    });

    it.each([
        {
            desk: 'IGD',
            variant: 'igd' as const,
            clinicName: 'Instalasi Gawat Darurat',
            wardName: null,
            wardClass: null,
            bedCode: null,
            continueFrom: null,
        },
        {
            desk: 'rawat inap',
            variant: 'rawat-inap' as const,
            clinicName: 'Bangsal Anggrek',
            wardName: 'Bangsal Anggrek',
            wardClass: 'Kelas 1',
            bedCode: 'ANG-101-A',
            continueFrom: 'LANGSUNG',
        },
    ])(
        'has no deterministic WCAG 2.1 A/AA violations on the $desk clinical-note scaffold',
        async ({
            variant,
            clinicName,
            wardName,
            wardClass,
            bedCode,
            continueFrom,
        }) => {
            const { container } = render(
                <main>
                    <LegacyFreeTextEncounterShow
                        variant={variant}
                        indexPath={`/pemeriksaan/${variant}`}
                        storeEntryPath={`/pemeriksaan/${variant}/encounter-1/entries`}
                        encounter={{
                            public_id: 'encounter-1',
                            status: 'IN_EXAMINATION',
                            clinic_name: clinicName,
                            doctor_name: 'Dr. Sintetis',
                            schedule_label: 'Shift pagi',
                            ward_name: wardName,
                            ward_class: wardClass,
                            bed_code: bedCode,
                            continue_from: continueFrom,
                            payer_type: 'UMUM',
                            case_type: 'NON_TRAUMA',
                            accident_type: null,
                            queue_number: 4,
                            registered_at: '2026-08-27T09:00:00+07:00',
                            visit_date: '2026-08-27',
                            chief_complaint: 'Keluhan sintetis',
                            patient: {
                                public_id: 'patient-1',
                                medical_record_number: 'SYNTH-004',
                                full_name: 'Pasien Klinis Sintetis',
                                date_of_birth: '1980-04-04',
                                sex: 'PEREMPUAN',
                                nik: '3173000000000004',
                            },
                            entries: [
                                {
                                    public_id: 'entry-1',
                                    entry_type: 'NURSING_INTAKE',
                                    body: 'Catatan keperawatan sintetis.',
                                    created_at: '2026-08-27T09:05:00+07:00',
                                    author_name: 'Perawat Sintetis',
                                },
                            ],
                        }}
                        entryTypeOptions={[
                            {
                                value: 'NURSING_INTAKE',
                                label: 'Asesmen keperawatan',
                                allowed: true,
                            },
                            {
                                value: 'MEDICAL_ASSESSMENT',
                                label: 'Asesmen medis',
                                allowed: false,
                            },
                        ]}
                        canWriteNursing
                        canWriteMedical={false}
                    />
                </main>,
            );

            if (variant === 'igd') {
                expect(
                    screen.getByText(
                        /Legacy emergency notes remain available as read-only history/i,
                    ),
                ).toBeInTheDocument();
                expect(
                    screen.queryByRole('combobox', {
                        name: 'Note type',
                    }),
                ).not.toBeInTheDocument();
                expect(
                    screen.queryByRole('textbox', { name: 'Note content' }),
                ).not.toBeInTheDocument();
            } else {
                expect(
                    screen.getByRole('combobox', {
                        name: 'Note type',
                    }),
                ).toBeInTheDocument();
                expect(
                    screen.getByRole('textbox', { name: 'Note content' }),
                ).toBeInTheDocument();
            }

            expect(
                screen.getByRole('tab', { name: 'Diagnosis' }),
            ).toBeDisabled();

            await expectNoWcag21Violations(container);
        },
    );

    it('has no deterministic WCAG 2.1 A/AA violations on outpatient registration', async () => {
        const { container } = render(
            <main>
                <PendaftaranRawatJalan
                    q="SYNTH-001"
                    searchResults={[
                        {
                            public_id: 'patient-1',
                            medical_record_number: 'SYNTH-001',
                            nik: '3173000000000001',
                            full_name: 'Pasien Sintetis',
                            place_of_birth: 'Jakarta',
                            date_of_birth: '1990-01-01',
                            sex: 'LAKI_LAKI',
                            religion: 'ISLAM',
                            education: 'S1',
                            occupation: 'DOSEN',
                            province_code: null,
                            province: 'DKI Jakarta',
                            city_code: null,
                            city: 'Jakarta Barat',
                            district_code: null,
                            district: 'Kebon Jeruk',
                            village_code: null,
                            village: 'Duri Kepa',
                            address_line: 'Alamat sintetis',
                            domicile: 'Jakarta',
                            phone: '080000000000',
                            email: 'pasien@example.test',
                            ethnicity: 'LAINNYA',
                            marital_status: 'MENIKAH',
                            language: 'INDONESIA',
                            notes: null,
                            responsible_party_name: 'Penanggung Jawab',
                        },
                    ]}
                    todaysEncounters={[
                        {
                            public_id: 'encounter-1',
                            status: 'REGISTERED',
                            clinic_name: 'Poli Umum',
                            doctor_name: 'Dr. Pengajar',
                            schedule_label: 'Kamis 08.00-12.00',
                            payer_type: 'UMUM',
                            queue_number: 1,
                            registered_at: '2026-08-27T07:45:00+07:00',
                            patient: {
                                public_id: 'patient-1',
                                medical_record_number: 'SYNTH-001',
                                full_name: 'Pasien Sintetis',
                            },
                        },
                    ]}
                    todaysEncountersPagination={firstPage}
                    clinics={[
                        {
                            public_id: 'clinic-1',
                            code: 'UMUM',
                            name: 'Poli Umum',
                            doctors: [
                                {
                                    public_id: 'doctor-1',
                                    name: 'Dr. Pengajar',
                                    specialty: 'Umum',
                                    schedules: [
                                        {
                                            public_id: 'schedule-1',
                                            label: 'Kamis 08.00-12.00',
                                            day_label: 'Kamis',
                                        },
                                    ],
                                },
                            ],
                        },
                    ]}
                    sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                    maritalOptions={[{ value: 'MENIKAH', label: 'Menikah' }]}
                    religionOptions={[{ value: 'ISLAM', label: 'Islam' }]}
                    educationOptions={[{ value: 'S1', label: 'S1' }]}
                    occupationOptions={[{ value: 'DOSEN', label: 'Dosen' }]}
                    ethnicityOptions={[{ value: 'LAINNYA', label: 'Lainnya' }]}
                    languageOptions={[
                        { value: 'INDONESIA', label: 'Indonesia' },
                    ]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                    admissionOptions={[
                        { value: 'DATANG_SENDIRI', label: 'Datang sendiri' },
                    ]}
                    wilayahProvinces={[{ value: '31', label: 'DKI Jakarta' }]}
                    canRegister
                />
            </main>,
        );

        expectTableScrollContainment('Patient search results');
        expectTableScrollContainment('Today’s patient registration list');

        await expectNoWcag21Violations(container);
    });

    it('announces and refocuses a multi-error IGD registration failure', async () => {
        const user = userEvent.setup();
        mockInertia.formErrors = {
            full_name: 'Nama pasien wajib diisi.',
            insurance_number: 'Nomor asuransi tidak valid.',
            chief_complaint: 'Catatan kunjungan wajib diisi.',
        };

        const { container } = render(
            <main>
                <PendaftaranRawatJalan
                    q=""
                    searchResults={[]}
                    todaysEncounters={[]}
                    clinics={[
                        {
                            public_id: 'clinic-igd',
                            code: 'IGD',
                            name: 'Instalasi Gawat Darurat',
                            doctors: [],
                        },
                    ]}
                    sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                    maritalOptions={[]}
                    religionOptions={[]}
                    educationOptions={[]}
                    occupationOptions={[]}
                    ethnicityOptions={[]}
                    languageOptions={[]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                    admissionOptions={[
                        {
                            value: 'DATANG_SENDIRI',
                            label: 'Datang sendiri',
                        },
                    ]}
                    caseTypeOptions={[
                        { value: 'NON_BEDAH', label: 'Non-bedah' },
                    ]}
                    accidentTypeOptions={[
                        {
                            value: 'BUKAN_KECELAKAAN',
                            label: 'Bukan kecelakaan',
                        },
                    ]}
                    wilayahProvinces={[]}
                    variant="igd"
                    canRegister
                />
            </main>,
        );

        const summary = screen.getByRole('alert', {
            name: 'Registration cannot be saved yet.',
        });

        await waitFor(() => expect(summary).toHaveFocus());
        expect(summary).toHaveAttribute(
            'aria-labelledby',
            'registration-error-summary-title',
        );
        expect(summary).toHaveAttribute('tabindex', '-1');
        expect(
            screen.getByRole('link', {
                name: 'Patient name: Nama pasien wajib diisi.',
            }),
        ).toHaveAttribute('href', '#full_name');
        expect(screen.getByLabelText('Patient name')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(screen.getByLabelText('Patient name')).toHaveAttribute(
            'aria-describedby',
            'full_name-error',
        );
        expect(screen.getByText('Nama pasien wajib diisi.')).toHaveAttribute(
            'id',
            'full_name-error',
        );
        expect(screen.getByLabelText('Insurance number')).toHaveAttribute(
            'aria-describedby',
            'insurance_number-error',
        );
        expect(screen.getByLabelText('Visit notes')).toHaveAttribute(
            'aria-describedby',
            'chief_complaint-error',
        );

        await user.click(
            screen.getByRole('link', {
                name: 'Patient name: Nama pasien wajib diisi.',
            }),
        );
        expect(screen.getByLabelText('Patient name')).toHaveFocus();

        fireEvent.submit(
            screen.getByRole('button', { name: 'Save' }).closest('form')!,
        );
        await waitFor(() => expect(summary).toHaveFocus());

        await expectNoWcag21Violations(container);
        mockInertia.formErrors = {};
    });

    it('has no deterministic WCAG 2.1 A/AA violations on inpatient registration', async () => {
        const { container } = render(
            <main>
                <PendaftaranRawatInap
                    q="SYNTH-003"
                    searchResults={[
                        {
                            public_id: 'patient-inpatient-1',
                            medical_record_number: 'SYNTH-003',
                            nik: '3173000000000003',
                            full_name: 'Pasien Rawat Inap Sintetis',
                            date_of_birth: '1988-03-03',
                            sex: 'LAKI_LAKI',
                            phone: '080000000003',
                        },
                    ]}
                    todaysEncounters={[
                        {
                            public_id: 'encounter-inpatient-1',
                            status: 'REGISTERED',
                            ward_name: 'Bangsal Anggrek',
                            ward_class: 'Kelas 1',
                            bed_code: 'ANG-101-A',
                            continue_from: 'LANGSUNG',
                            payer_type: 'UMUM',
                            queue_number: 3,
                            registered_at: '2026-08-27T08:30:00+07:00',
                            chief_complaint: 'Observasi sintetis',
                            patient: {
                                public_id: 'patient-inpatient-1',
                                medical_record_number: 'SYNTH-003',
                                full_name: 'Pasien Rawat Inap Sintetis',
                            },
                        },
                    ]}
                    wards={[
                        {
                            public_id: 'ward-anggrek',
                            code: 'ANGGREK',
                            display_name: 'Bangsal Anggrek',
                            beds: [
                                {
                                    public_id: 'bed-ang-101-a',
                                    code: 'ANG-101-A',
                                    display_name: 'Tempat Tidur ANG-101-A',
                                    service_class: 'Kelas 1',
                                },
                                {
                                    public_id: 'bed-ang-101-b',
                                    code: 'ANG-101-B',
                                    display_name: 'Tempat Tidur ANG-101-B',
                                    service_class: 'Kelas 1',
                                },
                            ],
                        },
                    ]}
                    wardOptions={[
                        {
                            value: 'Bangsal Anggrek',
                            label: 'Bangsal Anggrek',
                        },
                    ]}
                    sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                    continueFromOptions={[
                        { value: 'LANGSUNG', label: 'Langsung' },
                    ]}
                    filters={{
                        q: 'SYNTH-003',
                        ward: 'Bangsal Anggrek',
                        payer: 'UMUM',
                        continue_from: 'LANGSUNG',
                        date_from: '2026-08-27',
                        date_to: '2026-08-27',
                    }}
                    canRegister
                    canOpen
                />
            </main>,
        );

        expectTableScrollContainment('Inpatient patient search results');
        expectTableScrollContainment('Inpatient registration list');
        expect(
            screen.getByRole('link', {
                name: 'Open examination for Pasien Rawat Inap Sintetis',
            }),
        ).toHaveAttribute(
            'href',
            '/pemeriksaan/rawat-inap/encounter-inpatient-1',
        );
        expect(
            screen.getByRole('link', {
                name: 'Print for Pasien Rawat Inap Sintetis',
            }),
        ).toBeInTheDocument();

        await expectNoWcag21Violations(container);
    });

    it('keeps inpatient print available while hiding the examination handoff without permission', () => {
        render(
            <PendaftaranRawatInap
                q=""
                searchResults={[]}
                todaysEncounters={[
                    {
                        public_id: 'encounter-inpatient-denied',
                        status: 'REGISTERED',
                        ward_name: 'Bangsal Anggrek',
                        ward_class: 'Kelas 1',
                        bed_code: 'ANG-101-A',
                        continue_from: 'LANGSUNG',
                        payer_type: 'UMUM',
                        queue_number: 4,
                        registered_at: '2026-08-27T08:45:00+07:00',
                        chief_complaint: 'Observasi sintetis',
                        patient: {
                            public_id: 'patient-inpatient-denied',
                            medical_record_number: 'SYNTH-005',
                            full_name: 'Pasien Tanpa Akses Buka',
                        },
                    },
                ]}
                wards={[
                    {
                        public_id: 'ward-anggrek',
                        code: 'ANGGREK',
                        display_name: 'Bangsal Anggrek',
                        beds: [
                            {
                                public_id: 'bed-ang-101-a',
                                code: 'ANG-101-A',
                                display_name: 'Tempat Tidur ANG-101-A',
                                service_class: 'Kelas 1',
                            },
                        ],
                    },
                ]}
                wardOptions={[
                    { value: 'Bangsal Anggrek', label: 'Bangsal Anggrek' },
                ]}
                sexOptions={[{ value: 'LAKI_LAKI', label: 'Laki-laki' }]}
                payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                continueFromOptions={[{ value: 'LANGSUNG', label: 'Langsung' }]}
                filters={{
                    q: '',
                    ward: '',
                    payer: '',
                    continue_from: '',
                    date_from: '',
                    date_to: '',
                }}
                canRegister={false}
                canOpen={false}
            />,
        );

        expect(
            screen.queryByRole('link', { name: /Open examination for/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', {
                name: 'Print for Pasien Tanpa Akses Buka',
            }),
        ).toBeInTheDocument();
    });

    it('shows a neutral completed-documentation label on the shared worklist', () => {
        render(
            <PemeriksaanRawatJalanIndex
                variant="rawat-inap"
                encounters={[
                    {
                        public_id: 'encounter-ready-for-rm',
                        status: 'READY_FOR_RM',
                        clinic_name: 'Bangsal Anggrek',
                        doctor_name: 'Dr. Sintetis',
                        schedule_label: 'Shift pagi',
                        ward_name: 'Bangsal Anggrek',
                        ward_class: 'Kelas 1',
                        bed_code: 'ANG-101-A',
                        continue_from: 'LANGSUNG',
                        payer_type: 'UMUM',
                        queue_number: 5,
                        registered_at: '2026-08-27T09:00:00+07:00',
                        visit_date: '2026-08-27',
                        chief_complaint: 'Observasi sintetis',
                        patient: {
                            public_id: 'patient-ready-for-rm',
                            medical_record_number: 'SYNTH-006',
                            full_name: 'Pasien Dokumentasi Selesai',
                            date_of_birth: '1990-01-01',
                            sex: 'PEREMPUAN',
                        },
                    },
                ]}
                pagination={firstPage}
                clinics={[
                    { value: 'Bangsal Anggrek', label: 'Bangsal Anggrek' },
                ]}
                payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                continueFromOptions={[{ value: 'LANGSUNG', label: 'Langsung' }]}
                filters={{
                    q: '',
                    clinic: '',
                    date_from: '',
                    date_to: '',
                    payer: '',
                    continue_from: '',
                }}
                canOpen
            />,
        );

        expect(screen.getByText('Documentation complete')).toBeInTheDocument();
        expect(
            screen.queryByText('Ready for Medical Records'),
        ).not.toBeInTheDocument();
    });

    it('has no deterministic WCAG 2.1 A/AA violations on the registration recap', async () => {
        const { container } = render(
            <main>
                <PendaftaranRekap
                    filters={{
                        date_from: '2026-08-27',
                        date_to: '2026-08-27',
                        clinic: 'clinic-1',
                        payer: 'UMUM',
                        origin: 'WALK_IN',
                        care_setting: 'OUTPATIENT',
                        status: 'CANCELLED',
                    }}
                    rows={[
                        {
                            public_id: 'encounter-1',
                            registered_at: '2026-08-27T07:45:00+07:00',
                            visit_date: '2026-08-27',
                            queue_number: 1,
                            care_setting: 'OUTPATIENT',
                            care_setting_label: 'Rawat Jalan',
                            clinic_name: 'Poli Umum',
                            doctor_name: 'Dr. Pengajar',
                            payer_type: 'UMUM',
                            payer_label: 'Umum',
                            booking_code: null,
                            origin: 'WALK_IN',
                            origin_label: 'Walk-in',
                            status: 'CANCELLED',
                            status_label: 'Dibatalkan',
                            patient: {
                                medical_record_number: 'SYNTH-001',
                                full_name: 'Pasien Sintetis',
                            },
                        },
                    ]}
                    pagination={firstPage}
                    totals={{
                        all: 1,
                        online: 0,
                        walk_in: 1,
                        cancelled: 1,
                    }}
                    clinicOptions={[{ value: 'clinic-1', label: 'Poli Umum' }]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                />
            </main>,
        );

        expectTableScrollContainment(
            'Visit summary based on registration filters',
        );
        expect(screen.getByLabelText('From').closest('form')).toHaveClass(
            'min-w-0',
            'grid-cols-1',
        );
        expect(
            screen.getByRole('button', { name: 'Print summary' }).parentElement,
        ).toHaveClass('flex-wrap');
        expect(
            screen.getByRole('combobox', { name: 'Visit status' }),
        ).toHaveValue('CANCELLED');
        expect(screen.getAllByText('Cancelled')).not.toHaveLength(0);
        expect(screen.getByText('Inactive')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Cetak' }),
        ).not.toBeInTheDocument();

        await expectNoWcag21Violations(container);
    });
});
