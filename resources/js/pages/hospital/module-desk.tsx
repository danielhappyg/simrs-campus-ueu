import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    module: {
        key: string;
        title: string;
        educationalNote?: string | null;
    };
    session: {
        publicId: string;
        code: string;
        courseCode: string | null;
        scenarioTitle: string | null;
    };
    sessions: Array<{
        code: string;
        publicId: string;
        courseCode: string | null;
        scenarioTitle: string | null;
    }>;
    encounters: Array<{
        publicId: string;
        number: string;
        status: { code: string; label: string };
        patientName: string;
        mrn: string | null;
        location: string | null;
        appointmentStatus: string | null;
        overviewUrl: string;
        actions: Array<{ label: string; url: string }>;
    }>;
};

export default function HospitalModuleDesk({
    module,
    session,
    encounters,
}: Props) {
    return (
        <>
            <Head title={module.title} />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header>
                    <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                        Modul · {session.code}
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold">{module.title}</h1>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                        Tampilan tipis yang terhubung ke encounter sintetis yang
                        sama. Penulisan mendalam tetap di workspace masing-masing.
                    </p>
                </header>

                {module.educationalNote && (
                    <section className="rounded-2xl border border-[color:var(--signal)]/30 bg-[#fdeee3] px-5 py-4 text-sm leading-6 text-[#7a3410]">
                        {module.educationalNote}
                    </section>
                )}

                <section className="rounded-2xl border border-border bg-white">
                    <div className="border-b border-border px-5 py-4">
                        <h2 className="text-lg font-semibold">
                            Encounter pada sesi
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {session.scenarioTitle ?? session.courseCode}
                        </p>
                    </div>

                    {encounters.length === 0 ? (
                        <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                            Belum ada encounter. Mulai dari Pendaftaran.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {encounters.map((encounter) => (
                                <li key={encounter.publicId} className="px-5 py-4">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold">
                                                {encounter.patientName}
                                            </p>
                                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                                {encounter.number}
                                                {encounter.mrn
                                                    ? ` · ${encounter.mrn}`
                                                    : ''}
                                                {encounter.location
                                                    ? ` · ${encounter.location}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <Badge variant="outline">
                                            {encounter.status.label}
                                        </Badge>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <Button asChild size="sm" variant="outline">
                                            <Link href={encounter.overviewUrl}>
                                                Ringkasan
                                            </Link>
                                        </Button>
                                        {encounter.actions.map((action) => (
                                            <Button
                                                key={action.url}
                                                asChild
                                                size="sm"
                                            >
                                                <Link href={action.url}>
                                                    {action.label}
                                                    <ArrowRight
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Link>
                                            </Button>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

HospitalModuleDesk.layout = {
    breadcrumbs: [
        { title: 'Meja kerja', href: '/desk' },
        { title: 'Modul', href: '#' },
    ],
};
