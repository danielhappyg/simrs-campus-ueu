import { Link } from '@inertiajs/react';
import type {
    BedOccupancyState,
    InpatientBedProjection,
    InpatientWardProjection,
    WardBedMasterAction,
} from '@/components/inpatient/ward-bed-types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const occupancyLabel: Record<BedOccupancyState, string> = {
    AVAILABLE: 'Tersedia',
    OCCUPIED: 'Terisi',
    RETIRED: 'Dinonaktifkan',
};

const occupancyBadge: Record<BedOccupancyState, string> = {
    AVAILABLE: 'border-[#a7f3d0] bg-[#ecfdf5] text-[#047857]',
    OCCUPIED: 'border-[#fed7aa] bg-[#fff7ed] text-[#c2410c]',
    RETIRED: 'border-[#e2e8f0] bg-[#f1f5f9] text-[#64748b]',
};

const occupancyRail: Record<BedOccupancyState, string> = {
    AVAILABLE: 'border-l-[#059669]',
    OCCUPIED: 'border-l-[#f26a1b]',
    RETIRED: 'border-l-[#94a3b8]',
};

function OccupantDetail({ bed }: { bed: InpatientBedProjection }) {
    if (bed.occupancy.state !== 'OCCUPIED') {
        return <span className="text-[#64748b]">—</span>;
    }

    const occupant = bed.occupancy.occupant;

    if (!occupant) {
        return (
            <span className="text-xs text-[#64748b]">
                Detail kunjungan tidak tersedia untuk peran ini.
            </span>
        );
    }

    const content = (
        <>
            <span className="block font-medium text-[#0f172a]">
                {occupant.patient_name || 'Pasien rawat inap'}
            </span>
            <span className="block font-mono text-xs text-[#64748b]">
                {occupant.medical_record_number ||
                    occupant.encounter_public_id ||
                    'Identitas tidak ditampilkan'}
            </span>
        </>
    );

    return occupant.open_url ? (
        <Link
            href={occupant.open_url}
            className="inline-flex min-h-11 items-center rounded-md text-left text-sm text-[#1b75bc] hover:underline focus-visible:outline-none"
        >
            <span>{content}</span>
        </Link>
    ) : (
        <span>{content}</span>
    );
}

function BedActions({
    ward,
    bed,
    canManage,
    onAction,
}: {
    ward: InpatientWardProjection;
    bed: InpatientBedProjection;
    canManage: boolean;
    onAction: (action: WardBedMasterAction, trigger: HTMLButtonElement) => void;
}) {
    if (!canManage) {
        return <span className="text-xs text-[#64748b]">Hanya lihat</span>;
    }

    const canUpdate = bed.state === 'ACTIVE' && bed.actions.update_url !== null;
    const canRetire = bed.state === 'ACTIVE' && bed.actions.retire_url !== null;
    const occupied = bed.occupancy.state === 'OCCUPIED';
    const retireHelpId = `bed-retire-help-${bed.public_id}`;

    if (!canUpdate && !canRetire) {
        return (
            <span className="text-xs text-[#64748b]">Riwayat tersimpan</span>
        );
    }

    return (
        <div className="flex flex-wrap justify-end gap-1.5">
            {canUpdate ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="min-h-11"
                    onClick={(event) =>
                        onAction(
                            {
                                kind: 'UPDATE_BED',
                                url: bed.actions.update_url as string,
                                ward,
                                bed,
                            },
                            event.currentTarget,
                        )
                    }
                >
                    Ubah
                </Button>
            ) : null}
            {canRetire ? (
                <>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="min-h-11 border-[#fecaca] text-[#b42318] hover:bg-[#fef2f2] hover:text-[#991b1b]"
                        disabled={occupied}
                        aria-describedby={occupied ? retireHelpId : undefined}
                        onClick={(event) =>
                            onAction(
                                {
                                    kind: 'RETIRE_BED',
                                    url: bed.actions.retire_url as string,
                                    ward,
                                    bed,
                                },
                                event.currentTarget,
                            )
                        }
                    >
                        Nonaktifkan
                    </Button>
                    {occupied ? (
                        <span
                            id={retireHelpId}
                            className="max-w-[11rem] text-left text-xs leading-4 text-[#9a3412]"
                        >
                            Sedang terisi; tidak dapat dinonaktifkan.
                        </span>
                    ) : null}
                </>
            ) : null}
        </div>
    );
}

export function WardBedCensus({
    wards,
    totals,
    canManage,
    hasFilters,
    onClearFilters,
    onAction,
}: {
    wards: InpatientWardProjection[];
    totals: {
        active_wards: number;
        active_beds: number;
        occupied_beds: number;
        available_beds: number;
    };
    canManage: boolean;
    hasFilters: boolean;
    onClearFilters: () => void;
    onAction: (action: WardBedMasterAction, trigger: HTMLButtonElement) => void;
}) {
    const beds = wards.flatMap((ward) =>
        ward.beds.map((bed) => ({ ward, bed })),
    );

    return (
        <>
            <section aria-labelledby="bed-census-summary-heading">
                <h2 id="bed-census-summary-heading" className="sr-only">
                    Ringkasan ketersediaan tempat tidur
                </h2>
                <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {[
                        ['Bangsal aktif', totals.active_wards],
                        ['Tempat tidur aktif', totals.active_beds],
                        ['Terisi', totals.occupied_beds],
                        ['Tersedia', totals.available_beds],
                    ].map(([label, value]) => (
                        <div
                            key={label}
                            className="rounded-xl border border-[#e2e8f0] bg-white px-4 py-3 shadow-sm"
                        >
                            <dt className="text-xs font-medium tracking-wide text-[#64748b] uppercase">
                                {label}
                            </dt>
                            <dd className="mt-1 font-mono text-2xl font-semibold text-[#0f172a]">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </section>

            <section
                aria-labelledby="bed-census-table-heading"
                className="min-w-0 rounded-xl border border-[#e2e8f0] bg-white p-3 shadow-sm"
            >
                <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h2
                            id="bed-census-table-heading"
                            className="text-sm font-semibold text-[#0f172a]"
                        >
                            Sensus tempat tidur saat ini
                        </h2>
                        <p className="mt-0.5 text-xs text-[#64748b]">
                            Status terisi berasal dari kunjungan rawat inap
                            aktif, bukan dari isian manual.
                        </p>
                    </div>
                </div>

                {beds.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-[#cbd5e1] bg-[#f8fafc] px-4 py-8 text-center">
                        <p className="text-sm font-medium text-[#334155]">
                            {hasFilters
                                ? 'Tidak ada tempat tidur sesuai filter.'
                                : canManage
                                  ? 'Belum ada bangsal dan tempat tidur.'
                                  : 'Data bangsal belum tersedia. Hubungi pengelola data.'}
                        </p>
                        {hasFilters ? (
                            <Button
                                type="button"
                                variant="outline"
                                className="mt-3 min-h-11"
                                onClick={onClearFilters}
                            >
                                Hapus filter
                            </Button>
                        ) : null}
                    </div>
                ) : (
                    <div className="min-w-0 overflow-x-auto">
                        <table className="w-full min-w-[76rem] text-left text-sm">
                            <caption className="sr-only">
                                Daftar bangsal, tempat tidur, status data, dan
                                keterisian saat ini
                            </caption>
                            <thead className="border-b border-[#e2e8f0] text-[0.7rem] tracking-wide text-[#64748b] uppercase">
                                <tr>
                                    <th scope="col" className="px-2 py-2">
                                        Bangsal
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Ruang
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Kelas
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Tempat tidur
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Status data
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Status saat ini
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Pasien / kunjungan
                                    </th>
                                    <th scope="col" className="px-2 py-2">
                                        Versi
                                    </th>
                                    <th
                                        scope="col"
                                        className="px-2 py-2 text-right"
                                    >
                                        Aksi
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {beds.map(({ ward, bed }) => (
                                    <tr
                                        key={bed.public_id}
                                        className={cn(
                                            'border-b border-l-4 border-b-[#f1f5f9] last:border-b-0',
                                            occupancyRail[bed.occupancy.state],
                                        )}
                                    >
                                        <td className="px-2 py-2.5 align-top">
                                            <span className="block font-medium text-[#0f172a]">
                                                {ward.display_name}
                                            </span>
                                            <span className="font-mono text-xs text-[#64748b]">
                                                {ward.code}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2.5 align-top">
                                            {bed.room_label}
                                        </td>
                                        <td className="px-2 py-2.5 align-top">
                                            {bed.service_class}
                                        </td>
                                        <td className="px-2 py-2.5 align-top">
                                            <span className="block font-medium text-[#0f172a]">
                                                {bed.display_name}
                                            </span>
                                            <span className="font-mono text-xs text-[#64748b]">
                                                {bed.code}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2.5 align-top">
                                            <span className="inline-flex rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-2 py-1 text-xs font-medium text-[#475569]">
                                                {bed.state === 'ACTIVE'
                                                    ? 'Aktif'
                                                    : 'Dinonaktifkan'}
                                            </span>
                                        </td>
                                        <td className="px-2 py-2.5 align-top">
                                            <span
                                                className={cn(
                                                    'inline-flex rounded-md border px-2 py-1 text-xs font-semibold',
                                                    occupancyBadge[
                                                        bed.occupancy.state
                                                    ],
                                                )}
                                            >
                                                {
                                                    occupancyLabel[
                                                        bed.occupancy.state
                                                    ]
                                                }
                                            </span>
                                        </td>
                                        <td className="max-w-[18rem] px-2 py-2.5 align-top">
                                            <OccupantDetail bed={bed} />
                                        </td>
                                        <td className="px-2 py-2.5 align-top font-mono text-xs text-[#64748b]">
                                            v{bed.version}
                                        </td>
                                        <td className="px-2 py-2.5 text-right align-top">
                                            <BedActions
                                                ward={ward}
                                                bed={bed}
                                                canManage={canManage}
                                                onAction={onAction}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </>
    );
}
