import { cn } from '@/lib/utils';

export function SimulationBoundary({ className }: { className?: string }) {
    return (
        <aside
            role="note"
            aria-label="Status operasional sistem"
            title="Lingkungan pembelajaran terkontrol"
            className={cn(
                'inline-flex min-h-7 shrink-0 items-center rounded-full border border-[#c8dceb] bg-[#edf5fb] px-2.5 py-1 text-[#24506f]',
                className,
            )}
        >
            <span className="inline-flex items-center gap-1.5 text-center text-[0.6875rem] leading-4 font-semibold tracking-wide">
                <span
                    aria-hidden="true"
                    className="size-1.5 shrink-0 rounded-full bg-[#2f7ea8]"
                />
                Mode Kampus
            </span>
        </aside>
    );
}
