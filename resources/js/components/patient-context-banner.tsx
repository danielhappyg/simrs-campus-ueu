import { AlertTriangle, CalendarDays, MapPin, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import type { EncounterContext, PatientContext } from '@/types';

type Props = {
    patient: PatientContext;
    encounter: EncounterContext;
    actingAs: string;
};

function formatBirthDate(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(`${value}T00:00:00+07:00`));
}

export function PatientContextBanner({ patient, encounter, actingAs }: Props) {
    return (
        <section
            aria-label="Konteks pasien dan encounter aktif"
            className="clinical-shadow overflow-hidden rounded-lg border border-sky-200 bg-white"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-sky-200 bg-[#eaf4f8] px-5 py-3">
                <div className="flex flex-wrap items-center gap-2">
                    <Badge className="bg-primary text-white">
                        SIMULASI — DATA SINTETIS
                    </Badge>
                    <Badge
                        variant="outline"
                        className="border-sky-300 bg-white"
                    >
                        {encounter.status.label}
                    </Badge>
                </div>
                <span className="font-mono text-xs font-semibold text-primary">
                    {encounter.number}
                </span>
            </div>

            <div className="grid gap-px bg-border md:grid-cols-[minmax(0,1.3fr)_repeat(3,minmax(0,1fr))]">
                <div className="bg-white px-5 py-4">
                    <p className="text-xs font-bold tracking-wider text-primary uppercase">
                        Pasien aktif
                    </p>
                    <h2 className="mt-1 text-xl font-semibold">
                        {patient.fullName}
                    </h2>
                    <p className="mt-1 font-mono text-xs text-muted-foreground">
                        MRN {patient.mrn ?? 'belum tersedia'}
                    </p>
                </div>

                <div className="bg-white px-5 py-4">
                    <div className="flex items-center gap-2 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        <CalendarDays className="size-4" aria-hidden="true" />
                        Identitas kedua
                    </div>
                    <p className="mt-2 text-sm font-semibold">
                        {formatBirthDate(patient.birthDate)}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {patient.administrativeSex}
                    </p>
                </div>

                <div className="bg-white px-5 py-4">
                    <div className="flex items-center gap-2 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        <AlertTriangle className="size-4" aria-hidden="true" />
                        Alergi / peringatan
                    </div>
                    <p className="mt-2 text-sm font-semibold">
                        {patient.allergyStatus}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        Tidak boleh dianggap “tidak ada alergi”.
                    </p>
                </div>

                <div className="bg-white px-5 py-4">
                    <div className="flex items-center gap-2 text-xs font-bold tracking-wider text-muted-foreground uppercase">
                        <MapPin className="size-4" aria-hidden="true" />
                        Konteks kerja
                    </div>
                    <p className="mt-2 text-sm font-semibold">
                        {encounter.location}
                    </p>
                    <p className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                        <ShieldCheck className="size-3" aria-hidden="true" />
                        {actingAs}
                    </p>
                </div>
            </div>
        </section>
    );
}
