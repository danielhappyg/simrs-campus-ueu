import { FlaskConical, ShieldCheck } from 'lucide-react';

type Props = {
    banner: string;
    restriction: string;
    mode: string;
    compact?: boolean;
};

export function SimulationBanner({
    banner,
    restriction,
    mode,
    compact = false,
}: Props) {
    return (
        <section
            aria-label="Batas keselamatan lingkungan"
            className="flex min-h-10 items-center justify-between gap-4 border-b border-orange-200 bg-[#fff5ef] px-4 py-2 text-[#733315] md:px-6"
        >
            <div className="flex min-w-0 items-center gap-2">
                <FlaskConical className="size-4 shrink-0" aria-hidden="true" />
                <p className="truncate text-xs font-bold tracking-[0.08em] uppercase">
                    {banner}
                </p>
                {!compact && (
                    <p className="hidden text-xs text-[#7c4b34] md:block">
                        {restriction}
                    </p>
                )}
            </div>
            <span className="flex shrink-0 items-center gap-1.5 font-mono text-[0.68rem] font-medium">
                <ShieldCheck className="size-3.5" aria-hidden="true" />
                {mode}
            </span>
        </section>
    );
}
