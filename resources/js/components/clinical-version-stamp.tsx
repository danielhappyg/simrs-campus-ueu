import { Fingerprint, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { ClinicalEntryStatusCode } from '@/types';

type Props = {
    versionNumber: number;
    schemaVersion: string;
    contentHash: string;
    status: {
        code: string;
        label: string;
    };
    compact?: boolean;
};

const statusStyles: Record<ClinicalEntryStatusCode, string> = {
    DRAFT: 'border-slate-200 bg-slate-50 text-slate-700',
    SUBMITTED: 'border-violet-200 bg-violet-50 text-violet-800',
    CHANGES_REQUESTED: 'border-amber-200 bg-amber-50 text-amber-900',
    APPROVED: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    AMENDED: 'border-sky-200 bg-sky-50 text-primary',
    ENTERED_IN_ERROR: 'border-red-200 bg-red-50 text-red-800',
};

export function ClinicalVersionStamp({
    versionNumber,
    schemaVersion,
    contentHash,
    status,
    compact = false,
}: Props) {
    return (
        <section
            aria-label={`Identitas versi klinis ${versionNumber}`}
            className={cn(
                'rounded-md border border-sky-200 bg-[#eaf4f8]',
                compact ? 'p-3' : 'p-4',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2 text-primary">
                    <ShieldCheck className="size-4" aria-hidden="true" />
                    <span className="font-display font-semibold">
                        Versi {versionNumber} · {schemaVersion}
                    </span>
                </div>
                <Badge
                    variant="outline"
                    className={cn(
                        'border',
                        statusStyles[status.code as ClinicalEntryStatusCode] ??
                            'border-slate-200 bg-slate-50 text-slate-700',
                    )}
                >
                    {status.label}
                </Badge>
            </div>
            <div className="mt-3 flex items-start gap-2">
                <Fingerprint
                    className="mt-0.5 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <p className="text-[0.68rem] font-bold tracking-wider text-[#174c68] uppercase">
                        SHA-256 isi versi
                    </p>
                    <code className="mt-1 block font-mono text-[0.7rem] leading-5 break-all text-[#174c68]">
                        {contentHash}
                    </code>
                </div>
            </div>
        </section>
    );
}
