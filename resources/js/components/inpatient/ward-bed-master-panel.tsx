import type {
    InpatientWardProjection,
    WardBedMasterAction,
} from '@/components/inpatient/ward-bed-types';
import { Button } from '@/components/ui/button';

export function WardBedMasterPanel({
    wards,
    createWardUrl,
    canManage,
    onAction,
}: {
    wards: InpatientWardProjection[];
    createWardUrl: string | null;
    canManage: boolean;
    onAction: (action: WardBedMasterAction, trigger: HTMLButtonElement) => void;
}) {
    if (!canManage) {
        return null;
    }

    return (
        <section
            aria-labelledby="ward-master-heading"
            className="rounded-xl border border-[#d7e6f3] bg-[#f5f9fc] p-3 md:p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2
                        id="ward-master-heading"
                        className="text-sm font-semibold text-[#123b63]"
                    >
                        Manage Ward Data
                    </h2>
                    <p className="mt-0.5 max-w-2xl text-xs text-[#64748b]">
                        Ward and bed codes cannot be changed or reused. Each
                        change creates a new historical version.
                    </p>
                </div>
                {createWardUrl ? (
                    <Button
                        type="button"
                        className="min-h-11"
                        onClick={(event) =>
                            onAction(
                                {
                                    kind: 'CREATE_WARD',
                                    url: createWardUrl,
                                },
                                event.currentTarget,
                            )
                        }
                    >
                        Add Ward
                    </Button>
                ) : null}
            </div>

            {wards.length > 0 ? (
                <ul className="mt-3 grid gap-2 lg:grid-cols-2">
                    {wards.map((ward) => {
                        const canUpdate =
                            ward.state === 'ACTIVE' &&
                            ward.actions.update_url !== null;
                        const canCreateBed =
                            ward.state === 'ACTIVE' &&
                            ward.actions.create_bed_url !== null;
                        const canRetire =
                            ward.state === 'ACTIVE' &&
                            ward.actions.retire_url !== null;
                        const retirementBlocked =
                            ward.summary.occupied_beds > 0 ||
                            ward.beds.some((bed) => bed.state === 'ACTIVE');
                        const retireHelpId = `ward-retire-help-${ward.public_id}`;

                        return (
                            <li
                                key={ward.public_id}
                                className="rounded-lg border border-[#e2e8f0] bg-white p-3"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-medium text-[#0f172a]">
                                            {ward.display_name}
                                        </p>
                                        <p className="font-mono text-xs text-[#64748b]">
                                            {ward.code} · v{ward.version} ·{' '}
                                            {ward.state === 'ACTIVE'
                                                ? 'Active'
                                                : 'Dinonaktifkan'}
                                        </p>
                                        <p className="mt-1 text-xs text-[#64748b]">
                                            {ward.summary.active_beds} active ·{' '}
                                            {ward.summary.occupied_beds}{' '}
                                            occupied ·{' '}
                                            {ward.summary.available_beds}{' '}
                                            available
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap justify-end gap-1.5">
                                        {canCreateBed ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                className="min-h-11"
                                                onClick={(event) =>
                                                    onAction(
                                                        {
                                                            kind: 'CREATE_BED',
                                                            url: ward.actions
                                                                .create_bed_url as string,
                                                            ward,
                                                        },
                                                        event.currentTarget,
                                                    )
                                                }
                                            >
                                                Add Bed
                                            </Button>
                                        ) : null}
                                        {canUpdate ? (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={(event) =>
                                                    onAction(
                                                        {
                                                            kind: 'UPDATE_WARD',
                                                            url: ward.actions
                                                                .update_url as string,
                                                            ward,
                                                        },
                                                        event.currentTarget,
                                                    )
                                                }
                                            >
                                                Edit
                                            </Button>
                                        ) : null}
                                        {canRetire ? (
                                            <>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="min-h-11 border-[#fecaca] text-[#b42318] hover:bg-[#fef2f2] hover:text-[#991b1b]"
                                                    disabled={retirementBlocked}
                                                    aria-describedby={
                                                        retirementBlocked
                                                            ? retireHelpId
                                                            : undefined
                                                    }
                                                    onClick={(event) =>
                                                        onAction(
                                                            {
                                                                kind: 'RETIRE_WARD',
                                                                url: ward
                                                                    .actions
                                                                    .retire_url as string,
                                                                ward,
                                                            },
                                                            event.currentTarget,
                                                        )
                                                    }
                                                >
                                                    Retire
                                                </Button>
                                                {retirementBlocked ? (
                                                    <span
                                                        id={retireHelpId}
                                                        className="max-w-[13rem] text-left text-xs leading-4 text-[#9a3412]"
                                                    >
                                                        Retire every bed and
                                                        ensure that none are
                                                        occupied.
                                                    </span>
                                                ) : null}
                                            </>
                                        ) : null}
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            ) : null}
        </section>
    );
}
