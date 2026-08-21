import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

type Props = {
    module: {
        key: string;
        title: string;
        description: string;
    };
    sessions: Array<{
        code: string;
        publicId: string;
        courseCode: string | null;
        scenarioTitle: string | null;
    }>;
    continueBaseUrl: string;
};

export default function HospitalSessionPicker({
    module,
    sessions,
    continueBaseUrl,
}: Props) {
    return (
        <>
            <Head title={`Pilih sesi · ${module.title}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header>
                    <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                        {module.title}
                    </p>
                    <h1 className="mt-1 text-3xl font-semibold">Pilih sesi</h1>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">
                        {module.description}
                    </p>
                </header>

                <ul className="divide-y divide-border rounded-2xl border border-border bg-white">
                    {sessions.map((session) => (
                        <li
                            key={session.code}
                            className="flex flex-wrap items-center justify-between gap-3 px-5 py-4"
                        >
                            <div>
                                <p className="font-semibold font-mono">
                                    {session.code}
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {session.scenarioTitle ?? session.courseCode}
                                </p>
                            </div>
                            <Button asChild size="sm">
                                <Link
                                    href={`${continueBaseUrl}?session=${encodeURIComponent(session.code)}`}
                                >
                                    Lanjutkan
                                    <ArrowRight className="size-4" aria-hidden />
                                </Link>
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

HospitalSessionPicker.layout = {
    breadcrumbs: [
        { title: 'Meja kerja', href: '/desk' },
        { title: 'Pilih sesi', href: '#' },
    ],
};
