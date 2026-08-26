import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import axe from 'axe-core';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import PemeriksaanLaboratoriumIndex from '@/pages/pemeriksaan/laboratorium';
import PemeriksaanRawatJalanIndex from '@/pages/pemeriksaan/rawat-jalan';
import PendaftaranRawatJalan from '@/pages/pendaftaran/rawat-jalan';
import PendaftaranRekap from '@/pages/pendaftaran/rekap';

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
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        transform: vi.fn(),
    }),
    usePage: () => ({ props: { flash: {} } }),
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
                    orders={[
                        {
                            public_id: 'lab-order-1',
                            test_code: 'HB',
                            test_label: 'Hemoglobin',
                            clinical_question: 'Skrining anemia',
                            requested_at: '2026-08-27T08:00:00+07:00',
                            requested_by_name: 'Dr. Pengajar',
                            encounter: {
                                public_id: 'encounter-1',
                                clinic_name: 'Poli Umum',
                                status: 'REGISTERED',
                            },
                            patient: {
                                full_name: 'Pasien Sintetis',
                                medical_record_number: 'SYNTH-001',
                            },
                        },
                    ]}
                    pagination={firstPage}
                    filters={{ q: 'SYNTH-001' }}
                    canEnterResult
                />
            </main>,
        );

        await user.click(screen.getByRole('button', { name: 'Hasil' }));

        expectTableScrollContainment('Daftar order laboratorium aktif');

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

        expectTableScrollContainment('Daftar pasien pada worklist pemeriksaan');

        await expectNoWcag21Violations(container);
    });

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

        expectTableScrollContainment('Hasil pencarian pasien sintetis');
        expectTableScrollContainment('Daftar pendaftaran pasien hari ini');

        await expectNoWcag21Violations(container);
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
                            status: 'REGISTERED',
                            patient: {
                                medical_record_number: 'SYNTH-001',
                                full_name: 'Pasien Sintetis',
                            },
                        },
                    ]}
                    pagination={firstPage}
                    totals={{ all: 1, online: 0, walk_in: 1 }}
                    clinicOptions={[{ value: 'clinic-1', label: 'Poli Umum' }]}
                    payerOptions={[{ value: 'UMUM', label: 'Umum' }]}
                />
            </main>,
        );

        expectTableScrollContainment(
            'Rekap kunjungan berdasarkan filter pendaftaran',
        );
        expect(screen.getByLabelText('Dari').closest('form')).toHaveClass(
            'min-w-0',
            'grid-cols-1',
        );
        expect(
            screen.getByRole('button', { name: 'Cetak rekap' }).parentElement,
        ).toHaveClass('flex-wrap');

        await expectNoWcag21Violations(container);
    });
});
