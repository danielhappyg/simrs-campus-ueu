import { cn } from '@/lib/utils';
import type { WorkTaskItem, WorkTaskType } from '@/types';

const stages: Array<{ label: string; taskTypes: WorkTaskType[] }> = [
    {
        label: 'Registrasi',
        taskTypes: ['SESSION_ORIENTATION', 'REGISTRATION'],
    },
    { label: 'Asesmen awal', taskTypes: ['NURSING_INTAKE'] },
    {
        label: 'Asesmen medis',
        taskTypes: [
            'MEDICAL_ASSESSMENT',
            'SYNTHETIC_RESULT_RELEASE',
            'RESULT_ACKNOWLEDGEMENT',
        ],
    },
    {
        label: 'Farmasi',
        taskTypes: [
            'PHARMACY_REVIEW',
            'PRESCRIPTION_INTERVENTION_RESPONSE',
            'DISPENSING',
        ],
    },
    {
        label: 'Penutupan & RMIK',
        taskTypes: [
            'ENCOUNTER_CLOSURE',
            'ENCOUNTER_CLOSURE_REVIEW',
            'RECORD_REVIEW',
            'RECORD_CORRECTION',
            'RECORD_QUALITY_REVIEW',
            'CODING',
            'CODING_SOURCE_CORRECTION',
            'PROCEDURE_SOURCE_CORRECTION',
            'CODING_REVIEW',
            'SUPERVISOR_REVIEW',
            'DEBRIEF',
        ],
    },
];

const actionableStatuses = new Set([
    'READY',
    'IN_PROGRESS',
    'CHANGES_REQUESTED',
]);

export function EncounterOrbit({ tasks }: { tasks: WorkTaskItem[] }) {
    const activeStage = stages.findIndex((stage) =>
        tasks.some(
            (task) =>
                stage.taskTypes.includes(task.type) &&
                actionableStatuses.has(task.status.code),
        ),
    );

    return (
        <section
            aria-labelledby="encounter-orbit-title"
            className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
        >
            <div className="flex flex-wrap items-end justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <p className="text-xs font-bold tracking-[0.12em] text-primary uppercase">
                        Encounter orbit
                    </p>
                    <h2
                        id="encounter-orbit-title"
                        className="mt-1 text-lg font-semibold"
                    >
                        Posisi tugas dalam alur rawat jalan
                    </h2>
                </div>
                <p className="max-w-sm text-xs leading-5 text-muted-foreground">
                    Penanda oranye menunjukkan tahap dengan tugas yang dapat
                    Anda tindak lanjuti sekarang.
                </p>
            </div>

            <div className="overflow-x-auto px-5 py-6">
                <ol className="relative grid min-w-[680px] grid-cols-5">
                    <div
                        className="absolute top-3.5 right-[10%] left-[10%] h-0.5 bg-[#9bc5d9]"
                        aria-hidden="true"
                    />
                    {stages.map((stage, index) => {
                        const assigned = tasks.some((task) =>
                            stage.taskTypes.includes(task.type),
                        );
                        const active = index === activeStage;

                        return (
                            <li
                                key={stage.label}
                                className="relative flex flex-col items-center px-2 text-center"
                                aria-current={active ? 'step' : undefined}
                            >
                                <span
                                    className={cn(
                                        'relative z-10 flex size-7 items-center justify-center rounded-full border-2 bg-white',
                                        active &&
                                            'size-8 -translate-y-0.5 border-white bg-signal shadow-[0_0_0_5px_rgb(240_88_40_/_0.16)]',
                                        assigned &&
                                            !active &&
                                            'border-primary bg-[#e8f4f9]',
                                        !assigned &&
                                            !active &&
                                            'border-[#b9cbd4] bg-[#f4f8fa]',
                                    )}
                                    aria-hidden="true"
                                >
                                    {!active && assigned && (
                                        <span className="size-2 rounded-full bg-primary" />
                                    )}
                                </span>
                                <span
                                    className={cn(
                                        'mt-3 text-xs font-semibold text-muted-foreground',
                                        active && 'text-[#733315]',
                                        assigned &&
                                            !active &&
                                            'text-foreground',
                                    )}
                                >
                                    {stage.label}
                                </span>
                                {active && (
                                    <span className="mt-1 text-[0.65rem] font-bold tracking-wide text-signal uppercase">
                                        Tugas aktif
                                    </span>
                                )}
                            </li>
                        );
                    })}
                </ol>
            </div>
        </section>
    );
}
