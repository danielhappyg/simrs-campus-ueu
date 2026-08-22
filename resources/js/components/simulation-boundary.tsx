import { usePage } from '@inertiajs/react';

import { cn } from '@/lib/utils';

const FALLBACK_SIMULATION_LABEL = 'SIMULASI — DATA SINTETIS';

export function SimulationBoundary({ className }: { className?: string }) {
    const { environment } = usePage().props;
    const label = environment.banner.trim() || FALLBACK_SIMULATION_LABEL;

    return (
        <aside
            role="note"
            aria-label="Status lingkungan aplikasi"
            className={cn(
                'flex min-h-7 shrink-0 items-center justify-center border-b border-[#f2b17b] bg-[#fff4e8] px-3 py-1 text-[#74370f]',
                className,
            )}
        >
            <span className="inline-flex items-center gap-2 text-center font-mono text-[0.6875rem] leading-4 font-semibold tracking-[0.08em]">
                <span
                    aria-hidden="true"
                    className="size-1.5 shrink-0 rounded-full bg-[#f26a1b]"
                />
                {label}
            </span>
        </aside>
    );
}
