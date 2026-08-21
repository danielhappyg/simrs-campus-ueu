import { Head } from '@inertiajs/react';
import { FlaskConical, GitBranch, BookOpen } from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import type { BreadcrumbItem } from '@/types';

type Props = {
    phase: string;
    branch: string;
    docsPath: string;
    mode: string;
    syntheticOnly: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Beranda rebuild',
        href: '/',
    },
];

export default function RebuildHome({
    phase,
    branch,
    docsPath,
    mode,
    syntheticOnly,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SIMRS Campus UEU Rebuild" />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-8 px-4 py-10 md:px-6">
                <header className="space-y-3">
                    <Badge className="border-[#1b75bc]/20 bg-[#1b75bc]/10 text-[#123b63]">
                        Fondasi clean-slate
                    </Badge>
                    <h1 className="text-3xl font-semibold tracking-tight text-[#0f172a] md:text-4xl">
                        SIMRS Campus UEU Rebuild
                    </h1>
                    <p className="max-w-2xl text-base leading-relaxed text-[#64748b]">
                        Cabang ini adalah fondasi Phase {phase}: Laravel +
                        Inertia/React bersih untuk program parity, bukan MVP
                        rawat jalan teaching lama, dan bukan klon vendor SIMRS.
                    </p>
                </header>

                <section
                    aria-labelledby="rebuild-status-heading"
                    className="space-y-4 rounded-2xl border border-[#e2e8f0] bg-white p-6 shadow-sm"
                >
                    <h2
                        id="rebuild-status-heading"
                        className="text-lg font-semibold text-[#0f172a]"
                    >
                        Status fondasi
                    </h2>
                    <ul className="space-y-3 text-sm text-[#334155]">
                        <li className="flex gap-3">
                            <GitBranch
                                className="mt-0.5 size-4 shrink-0 text-[#1b75bc]"
                                aria-hidden
                            />
                            <span>
                                Branch kerja:{' '}
                                <code className="font-mono text-[#0f172a]">
                                    {branch}
                                </code>
                            </span>
                        </li>
                        <li className="flex gap-3">
                            <FlaskConical
                                className="mt-0.5 size-4 shrink-0 text-[#f26a1b]"
                                aria-hidden
                            />
                            <span>
                                Mode {mode}
                                {syntheticOnly
                                    ? ' · synthetic-only wajib'
                                    : ''}{' '}
                                — data sintetik saja; tidak untuk pelayanan
                                pasien nyata.
                            </span>
                        </li>
                        <li className="flex gap-3">
                            <BookOpen
                                className="mt-0.5 size-4 shrink-0 text-[#123b63]"
                                aria-hidden
                            />
                            <span>
                                Dokumentasi program:{' '}
                                <code className="font-mono text-[#0f172a]">
                                    {docsPath}
                                </code>{' '}
                                (di repositori lokal; bukan tautan produksi).
                            </span>
                        </li>
                    </ul>
                </section>

                <p className="text-sm leading-relaxed text-[#94a3b8]">
                    MVP outpatient teaching sebelumnya tetap ada di riwayat{' '}
                    <code className="font-mono">main</code>. Pola lama boleh
                    disalin ulang secara sengaja nanti; cabang ini mulai dari
                    fondasi kosong sesuai ADR-015 / ADR-016.
                </p>
            </div>
        </AppLayout>
    );
}
