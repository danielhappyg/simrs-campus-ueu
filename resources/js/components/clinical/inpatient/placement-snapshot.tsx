import { BedSingle, Building2, MapPin } from 'lucide-react';
import type { InpatientPlacementSnapshot } from './types';

type Props = {
    snapshot: InpatientPlacementSnapshot;
    compact?: boolean;
};

export function PlacementSnapshot({ snapshot, compact = false }: Props) {
    return (
        <dl
            className={
                compact
                    ? 'grid gap-2 text-xs sm:grid-cols-3'
                    : 'grid gap-3 text-sm sm:grid-cols-3'
            }
            aria-label="Snapshot penempatan"
        >
            <div className="min-w-0 rounded-md border border-border bg-background p-2.5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <Building2 aria-hidden="true" className="size-3.5" />
                    Bangsal
                </dt>
                <dd className="mt-1 truncate font-semibold text-foreground">
                    {snapshot.ward_display_name}
                </dd>
                <dd className="font-mono text-[0.7rem] text-muted-foreground">
                    {snapshot.ward_code}
                </dd>
            </div>
            <div className="min-w-0 rounded-md border border-border bg-background p-2.5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <MapPin aria-hidden="true" className="size-3.5" />
                    Ruang · kelas
                </dt>
                <dd className="mt-1 truncate font-semibold text-foreground">
                    {snapshot.room_label}
                </dd>
                <dd className="text-[0.7rem] text-muted-foreground">
                    {snapshot.service_class}
                </dd>
            </div>
            <div className="min-w-0 rounded-md border border-border bg-background p-2.5">
                <dt className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    <BedSingle aria-hidden="true" className="size-3.5" />
                    Tempat tidur
                </dt>
                <dd className="mt-1 truncate font-semibold text-foreground">
                    {snapshot.bed_display_name}
                </dd>
                <dd className="font-mono text-[0.7rem] text-muted-foreground">
                    {snapshot.bed_code}
                </dd>
            </div>
        </dl>
    );
}
