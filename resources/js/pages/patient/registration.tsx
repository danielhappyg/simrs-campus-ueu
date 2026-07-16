import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarCheck,
    CheckCircle2,
    IdCard,
    Search,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RegistrationAppointment, SyntheticPatientSummary } from '@/types';

type Option = { value: string; label: string };

type Props = {
    session: {
        publicId: string;
        code: string;
        courseCode: string;
        scenarioTitle: string;
    };
    assignmentPublicId: string;
    searchUrl: string;
    storeUrl: string;
    searchQuery: string;
    candidates: SyntheticPatientSummary[];
    appointments: RegistrationAppointment[];
    canCreateRegistration: boolean;
    locations: Array<{ publicId: string; code: string; name: string }>;
    registrationKey: string;
    defaultScheduledAt: string;
    options: {
        administrativeSex: Option[];
        visitSources: Option[];
    };
};

type RegistrationForm = {
    request_key: string;
    existing_patient_public_id: string;
    full_name: string;
    birth_date: string;
    administrative_sex: string;
    location_public_id: string;
    scheduled_at: string;
    visit_reason: string;
    visit_source: string;
    identity_verification_method: string;
    consent_acknowledged: boolean;
    duplicate_decision: string;
    duplicate_reason: string;
};

function registrationDefaults(
    requestKey: string,
    locationPublicId: string,
    scheduledAt: string,
): RegistrationForm {
    return {
        request_key: requestKey,
        existing_patient_public_id: '',
        full_name: 'Pasien Sintetis ',
        birth_date: '',
        administrative_sex: 'UNKNOWN',
        location_public_id: locationPublicId,
        scheduled_at: scheduledAt,
        visit_reason: '',
        visit_source: 'SCHEDULED',
        identity_verification_method: 'SCENARIO_BRIEF',
        consent_acknowledged: false,
        duplicate_decision: 'NO_CANDIDATE',
        duplicate_reason: '',
    };
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function StatusBadge({
    appointment,
}: {
    appointment: RegistrationAppointment;
}) {
    const checkedIn = appointment.status.code === 'CHECKED_IN';

    return (
        <Badge
            variant="outline"
            className={
                checkedIn
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-sky-200 bg-sky-50 text-primary'
            }
        >
            {appointment.status.label}
        </Badge>
    );
}

export default function RegistrationWorkspace({
    session,
    searchUrl,
    storeUrl,
    searchQuery,
    candidates,
    appointments,
    canCreateRegistration,
    locations,
    registrationKey,
    defaultScheduledAt,
    options,
}: Props) {
    const [query, setQuery] = useState(searchQuery);
    const form = useForm<RegistrationForm>(
        registrationDefaults(
            registrationKey,
            locations[0]?.publicId ?? '',
            defaultScheduledAt,
        ),
    );
    const selectedCandidate = useMemo(
        () =>
            candidates.find(
                (candidate) =>
                    candidate.publicId === form.data.existing_patient_public_id,
            ),
        [candidates, form.data.existing_patient_public_id],
    );

    function search(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(
            searchUrl,
            { q: query },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function chooseExisting(candidate: SyntheticPatientSummary) {
        form.setData({
            ...form.data,
            existing_patient_public_id: candidate.publicId,
            full_name: '',
            birth_date: '',
            administrative_sex: '',
            identity_verification_method: 'TWO_SYNTHETIC_IDENTIFIERS',
            duplicate_decision: 'USE_EXISTING',
            duplicate_reason: '',
        });
    }

    function createSeparateRecord() {
        form.setData({
            ...form.data,
            existing_patient_public_id: '',
            full_name: 'Pasien Sintetis ',
            birth_date: '',
            administrative_sex: 'UNKNOWN',
            identity_verification_method: 'SCENARIO_BRIEF',
            duplicate_decision: 'CREATE_NEW',
        });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(storeUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const nextRegistrationKey =
                    typeof page.props.registrationKey === 'string'
                        ? page.props.registrationKey
                        : registrationKey;
                const nextScheduledAt =
                    typeof page.props.defaultScheduledAt === 'string'
                        ? page.props.defaultScheduledAt
                        : defaultScheduledAt;

                form.setData(
                    registrationDefaults(
                        nextRegistrationKey,
                        locations[0]?.publicId ?? '',
                        nextScheduledAt,
                    ),
                );
                form.clearErrors();
                setQuery('');
            },
        });
    }

    return (
        <>
            <Head title="Pencarian dan registrasi pasien" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Identitas bersama · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Pencarian &amp; Registrasi Pasien
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Cari terlebih dahulu, putuskan kandidat secara
                            eksplisit, lalu buat janji dan encounter yang akan
                            dipakai semua profesi dalam sesi ini.
                        </p>
                    </div>

                    <div className="rounded-md border border-sky-200 bg-[#eaf4f8] px-4 py-3 text-sm">
                        <p className="font-semibold text-primary">
                            {session.courseCode}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {session.scenarioTitle}
                        </p>
                    </div>
                </header>

                <section className="rounded-lg border border-orange-200 bg-orange-50 px-5 py-4">
                    <div className="flex gap-3">
                        <ShieldCheck
                            className="mt-0.5 size-5 shrink-0 text-[#a3471f]"
                            aria-hidden="true"
                        />
                        <div>
                            <h2 className="font-semibold text-[#743719]">
                                Batas keselamatan data
                            </h2>
                            <p className="mt-1 text-sm leading-6 text-[#743719]">
                                Hanya identitas rekaan yang diawali “Pasien
                                Sintetis” yang dapat dibuat. Sistem menerbitkan
                                MRN dan NIK-like pada namespace simulasi; tidak
                                ada transaksi BPJS atau endpoint produksi.
                            </p>
                        </div>
                    </div>
                </section>

                <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(32rem,1.1fr)]">
                    <div className="space-y-5">
                        <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 items-center justify-center rounded-md bg-sky-50 text-primary">
                                    <Search
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div>
                                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                        Langkah 1
                                    </p>
                                    <h2 className="text-xl font-semibold">
                                        Cari rekam sintetis
                                    </h2>
                                </div>
                            </div>

                            <form onSubmit={search} className="mt-5 flex gap-2">
                                <Label
                                    htmlFor="patient-search"
                                    className="sr-only"
                                >
                                    Nama atau identifier pasien sintetis
                                </Label>
                                <Input
                                    id="patient-search"
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    placeholder="Nama, MR-SIM, atau SYN-NIK"
                                    minLength={2}
                                    className="h-10"
                                />
                                <Button type="submit" variant="outline">
                                    Cari
                                </Button>
                            </form>

                            {searchQuery && (
                                <div className="mt-5 border-t border-border pt-4">
                                    <p className="text-xs text-muted-foreground">
                                        {candidates.length} kandidat untuk “
                                        {searchQuery}”
                                    </p>

                                    {candidates.length > 0 ? (
                                        <div className="mt-3 space-y-3">
                                            {candidates.map((candidate) => (
                                                <article
                                                    key={candidate.publicId}
                                                    className="rounded-md border border-border p-4"
                                                >
                                                    <div className="flex flex-wrap justify-between gap-3">
                                                        <div>
                                                            <h3 className="font-semibold">
                                                                {
                                                                    candidate.fullName
                                                                }
                                                            </h3>
                                                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                                {candidate.mrn}{' '}
                                                                ·{' '}
                                                                {
                                                                    candidate.birthDate
                                                                }
                                                            </p>
                                                        </div>
                                                        <Badge variant="outline">
                                                            DATA SINTETIS
                                                        </Badge>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={
                                                            !canCreateRegistration
                                                        }
                                                        className="mt-3"
                                                        onClick={() =>
                                                            chooseExisting(
                                                                candidate,
                                                            )
                                                        }
                                                    >
                                                        Gunakan pasien ini
                                                    </Button>
                                                </article>
                                            ))}

                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="w-full justify-start text-[#87401d]"
                                                disabled={
                                                    !canCreateRegistration
                                                }
                                                onClick={createSeparateRecord}
                                            >
                                                <UserPlus
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Buat rekam baru yang berbeda
                                            </Button>
                                        </div>
                                    ) : (
                                        <p className="mt-3 rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground">
                                            Tidak ada kandidat. Anda dapat
                                            membuat identitas sintetis baru.
                                        </p>
                                    )}
                                </div>
                            )}
                        </section>

                        <section className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white">
                            <div className="border-b border-border px-5 py-4">
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Janji sesi
                                </p>
                                <h2 className="mt-1 text-xl font-semibold">
                                    Siap untuk check-in
                                </h2>
                            </div>

                            {appointments.length === 0 ? (
                                <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                                    Belum ada janji sintetis pada sesi ini.
                                </p>
                            ) : (
                                <div className="divide-y divide-border">
                                    {appointments.map((appointment) => (
                                        <article
                                            key={appointment.publicId}
                                            className="p-5"
                                        >
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h3 className="font-semibold">
                                                        {
                                                            appointment.patient
                                                                .fullName
                                                        }
                                                    </h3>
                                                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                        {
                                                            appointment.appointmentCode
                                                        }{' '}
                                                        ·{' '}
                                                        {
                                                            appointment.patient
                                                                .mrn
                                                        }
                                                    </p>
                                                </div>
                                                <StatusBadge
                                                    appointment={appointment}
                                                />
                                            </div>
                                            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Jadwal
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {formatDateTime(
                                                            appointment.scheduledAt,
                                                        )}{' '}
                                                        WIB
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt className="text-xs text-muted-foreground">
                                                        Alasan kunjungan
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {
                                                            appointment.visitReason
                                                        }
                                                    </dd>
                                                </div>
                                            </dl>
                                            <div className="mt-4 flex flex-wrap gap-2">
                                                {appointment.canCheckIn && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                appointment.checkInUrl,
                                                            )
                                                        }
                                                    >
                                                        <CalendarCheck
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        Check-in
                                                    </Button>
                                                )}
                                                {appointment.encounter && (
                                                    <Button
                                                        asChild
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        <Link
                                                            href={
                                                                appointment
                                                                    .encounter
                                                                    .url
                                                            }
                                                        >
                                                            Buka encounter
                                                            <ArrowRight
                                                                className="size-4"
                                                                aria-hidden="true"
                                                            />
                                                        </Link>
                                                    </Button>
                                                )}
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>

                    <section className="clinical-shadow rounded-lg border border-border bg-white p-5">
                        <div className="flex items-center gap-3">
                            <div className="flex size-10 items-center justify-center rounded-md bg-sky-50 text-primary">
                                <IdCard className="size-5" aria-hidden="true" />
                            </div>
                            <div>
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Langkah 2
                                </p>
                                <h2 className="text-xl font-semibold">
                                    Buat janji &amp; encounter
                                </h2>
                            </div>
                        </div>

                        {canCreateRegistration && selectedCandidate && (
                            <div className="mt-5 flex items-start justify-between gap-3 rounded-md border border-emerald-200 bg-emerald-50 p-4">
                                <div>
                                    <p className="text-xs font-bold text-emerald-800 uppercase">
                                        Menggunakan rekam yang ada
                                    </p>
                                    <p className="mt-1 font-semibold text-emerald-950">
                                        {selectedCandidate.fullName}
                                    </p>
                                    <p className="font-mono text-xs text-emerald-800">
                                        {selectedCandidate.mrn}
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={createSeparateRecord}
                                >
                                    Batalkan pilihan
                                </Button>
                            </div>
                        )}

                        <form onSubmit={submit} className="mt-5">
                            {!canCreateRegistration && (
                                <div
                                    id="case-limit-message"
                                    className="mb-5 rounded-md border border-sky-200 bg-[#eaf4f8] p-4 text-sm leading-6 text-[#174c68]"
                                >
                                    Sesi ini sudah memiliki satu encounter
                                    bersama. Selesaikan/check-in kasus tersebut;
                                    gunakan clone sesi untuk kasus baru agar
                                    lingkup penugasan setiap profesi tetap aman.
                                </div>
                            )}
                            <InputError
                                message={form.errors.request_key}
                                className="mb-5"
                            />
                            <fieldset
                                disabled={!canCreateRegistration}
                                aria-describedby={
                                    !canCreateRegistration
                                        ? 'case-limit-message'
                                        : undefined
                                }
                                className="m-0 min-w-0 space-y-6 border-0 p-0 disabled:opacity-60"
                            >
                                {!selectedCandidate && (
                                    <fieldset className="space-y-4">
                                        <legend className="font-display text-base font-semibold">
                                            Identitas rekaan
                                        </legend>
                                        <div className="grid gap-4 md:grid-cols-2">
                                            <div className="md:col-span-2">
                                                <Label htmlFor="full-name">
                                                    Nama sintetis
                                                </Label>
                                                <Input
                                                    id="full-name"
                                                    value={form.data.full_name}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'full_name',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors.full_name,
                                                    )}
                                                    aria-describedby="full-name-error"
                                                    className="mt-1"
                                                />
                                                <InputError
                                                    id="full-name-error"
                                                    message={
                                                        form.errors.full_name
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="birth-date">
                                                    Tanggal lahir
                                                </Label>
                                                <Input
                                                    id="birth-date"
                                                    type="date"
                                                    value={form.data.birth_date}
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'birth_date',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={Boolean(
                                                        form.errors.birth_date,
                                                    )}
                                                    className="mt-1"
                                                />
                                                <InputError
                                                    message={
                                                        form.errors.birth_date
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                            <div>
                                                <Label htmlFor="administrative-sex">
                                                    Jenis kelamin administratif
                                                </Label>
                                                <select
                                                    id="administrative-sex"
                                                    value={
                                                        form.data
                                                            .administrative_sex
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'administrative_sex',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                >
                                                    {options.administrativeSex.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .administrative_sex
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                        </div>

                                        {form.data.duplicate_decision ===
                                            'CREATE_NEW' && (
                                            <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                                                <div className="flex gap-2 text-sm text-amber-900">
                                                    <AlertTriangle
                                                        className="mt-0.5 size-4 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    Rekam kandidat tidak akan
                                                    digabung otomatis. Jelaskan
                                                    alasan membuat pasien
                                                    sintetis baru.
                                                </div>
                                                <Label
                                                    htmlFor="duplicate-reason"
                                                    className="mt-3"
                                                >
                                                    Alasan rekam terpisah
                                                </Label>
                                                <Input
                                                    id="duplicate-reason"
                                                    value={
                                                        form.data
                                                            .duplicate_reason
                                                    }
                                                    onChange={(event) =>
                                                        form.setData(
                                                            'duplicate_reason',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="mt-1 bg-white"
                                                />
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .duplicate_reason
                                                    }
                                                    className="mt-1"
                                                />
                                            </div>
                                        )}
                                        <InputError
                                            message={
                                                form.errors.duplicate_decision
                                            }
                                        />
                                    </fieldset>
                                )}

                                <fieldset className="space-y-4 border-t border-border pt-5">
                                    <legend className="font-display text-base font-semibold">
                                        Konteks kunjungan
                                    </legend>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <Label htmlFor="location">
                                                Poliklinik
                                            </Label>
                                            <select
                                                id="location"
                                                value={
                                                    form.data.location_public_id
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'location_public_id',
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                {locations.map((location) => (
                                                    <option
                                                        key={location.publicId}
                                                        value={
                                                            location.publicId
                                                        }
                                                    >
                                                        {location.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={
                                                    form.errors
                                                        .location_public_id
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="scheduled-at">
                                                Jadwal simulasi
                                            </Label>
                                            <Input
                                                id="scheduled-at"
                                                type="datetime-local"
                                                value={form.data.scheduled_at}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'scheduled_at',
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-1"
                                            />
                                            <InputError
                                                message={
                                                    form.errors.scheduled_at
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                        <div>
                                            <Label htmlFor="visit-source">
                                                Sumber kunjungan
                                            </Label>
                                            <select
                                                id="visit-source"
                                                value={form.data.visit_source}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'visit_source',
                                                        event.target.value,
                                                    )
                                                }
                                                className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                {options.visitSources.map(
                                                    (option) => (
                                                        <option
                                                            key={option.value}
                                                            value={option.value}
                                                        >
                                                            {option.label}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        </div>
                                        <div>
                                            <Label htmlFor="identity-method">
                                                Metode verifikasi latihan
                                            </Label>
                                            <select
                                                id="identity-method"
                                                value={
                                                    form.data
                                                        .identity_verification_method
                                                }
                                                onChange={(event) =>
                                                    form.setData(
                                                        'identity_verification_method',
                                                        event.target.value,
                                                    )
                                                }
                                                disabled
                                                className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                            >
                                                <option value="TWO_SYNTHETIC_IDENTIFIERS">
                                                    Dua identifier sintetis
                                                </option>
                                                <option value="SCENARIO_BRIEF">
                                                    Brief skenario
                                                </option>
                                            </select>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Otomatis: brief skenario untuk
                                                identitas baru; dua identifier
                                                sintetis untuk rekam yang sudah
                                                ada.
                                            </p>
                                            <InputError
                                                message={
                                                    form.errors
                                                        .identity_verification_method
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <Label htmlFor="visit-reason">
                                                Alasan kunjungan skenario
                                            </Label>
                                            <Input
                                                id="visit-reason"
                                                value={form.data.visit_reason}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'visit_reason',
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder="Contoh: evaluasi keluhan rawat jalan pada skenario latihan"
                                                className="mt-1"
                                            />
                                            <InputError
                                                message={
                                                    form.errors.visit_reason
                                                }
                                                className="mt-1"
                                            />
                                        </div>
                                    </div>
                                </fieldset>

                                <div className="rounded-md border border-border bg-muted/40 p-4">
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            id="consent"
                                            checked={
                                                form.data.consent_acknowledged
                                            }
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'consent_acknowledged',
                                                    checked === true,
                                                )
                                            }
                                            aria-invalid={Boolean(
                                                form.errors
                                                    .consent_acknowledged,
                                            )}
                                        />
                                        <Label
                                            htmlFor="consent"
                                            className="text-sm leading-5 font-normal"
                                        >
                                            Saya mengonfirmasi bahwa seluruh
                                            identitas dan konteks ini dibuat
                                            khusus untuk pembelajaran dan bukan
                                            data pasien nyata.
                                        </Label>
                                    </div>
                                    <InputError
                                        message={
                                            form.errors.consent_acknowledged
                                        }
                                        className="mt-2"
                                    />
                                </div>

                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="w-full gap-2 sm:w-auto"
                                >
                                    {form.processing ? (
                                        'Menyimpan registrasi…'
                                    ) : (
                                        <>
                                            <CheckCircle2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Simpan janji &amp; encounter
                                            terencana
                                        </>
                                    )}
                                </Button>
                            </fieldset>
                        </form>
                    </section>
                </div>
            </div>
        </>
    );
}

RegistrationWorkspace.layout = {
    breadcrumbs: [
        { title: 'Antrean kerja', href: '/work' },
        { title: 'Registrasi pasien', href: '#' },
    ],
};
