import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    BookOpenCheck,
    CheckCircle2,
    CircleDashed,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    FileCheck2,
    Fingerprint,
    IdCard,
    MessageSquareWarning,
    Pill,
    RotateCcw,
    ShieldCheck,
    Stethoscope,
    UserRoundSearch,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EncounterOrbit } from '@/components/encounter-orbit';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { work } from '@/routes';
import type {
    AssignmentContext,
    TaskStatusCode,
    WorkQueueSummary,
    WorkTaskItem,
    WorkTaskType,
} from '@/types';

type Props = {
    assignments: AssignmentContext[];
    tasks: WorkTaskItem[];
    summary: WorkQueueSummary;
};

const taskIcons: Record<WorkTaskType, LucideIcon> = {
    SESSION_ORIENTATION: ClipboardCheck,
    REGISTRATION: IdCard,
    NURSING_INTAKE: UserRoundSearch,
    MEDICAL_ASSESSMENT: Stethoscope,
    SYNTHETIC_RESULT_RELEASE: ClipboardCheck,
    RESULT_ACKNOWLEDGEMENT: CheckCircle2,
    PHARMACY_REVIEW: Pill,
    PRESCRIPTION_INTERVENTION_RESPONSE: MessageSquareWarning,
    DISPENSING: Pill,
    ENCOUNTER_CLOSURE: FileCheck2,
    ENCOUNTER_CLOSURE_REVIEW: ShieldCheck,
    RECORD_REVIEW: ClipboardList,
    RECORD_CORRECTION: RotateCcw,
    RECORD_QUALITY_REVIEW: ShieldCheck,
    CODING: Fingerprint,
    CODING_SOURCE_CORRECTION: RotateCcw,
    PROCEDURE_SOURCE_CORRECTION: RotateCcw,
    CODING_REVIEW: ShieldCheck,
    SUPERVISOR_REVIEW: ShieldCheck,
    SAFETY_DISPOSITION: AlertTriangle,
    DEBRIEF: BookOpenCheck,
};

const statusStyles: Record<TaskStatusCode, string> = {
    READY: 'border-sky-200 bg-sky-50 text-[#00598f]',
    IN_PROGRESS: 'border-orange-200 bg-orange-50 text-[#87401d]',
    WAITING: 'border-slate-200 bg-slate-50 text-slate-600',
    BLOCKED: 'border-red-200 bg-red-50 text-red-800',
    SUBMITTED: 'border-violet-200 bg-violet-50 text-violet-800',
    CHANGES_REQUESTED: 'border-amber-200 bg-amber-50 text-amber-900',
    COMPLETE: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    CANCELLED: 'border-slate-200 bg-slate-100 text-slate-600',
};

const statusIcons: Partial<Record<TaskStatusCode, LucideIcon>> = {
    READY: ArrowRight,
    IN_PROGRESS: CircleDashed,
    WAITING: Clock3,
    BLOCKED: Ban,
    CHANGES_REQUESTED: RotateCcw,
};

function formatAvailableAt(value: string | null): string | null {
    if (!value) {
        return null;
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

export function TaskRow({ task }: { task: WorkTaskItem }) {
    const TaskIcon = taskIcons[task.type] ?? ClipboardList;
    const StatusIcon = statusIcons[task.status.code];
    const caseLabel =
        typeof task.context?.caseLabel === 'string'
            ? task.context.caseLabel
            : null;
    const availableAt = formatAvailableAt(task.availableAt);

    return (
        <article className="group grid gap-4 border-b border-border px-5 py-5 last:border-b-0 md:grid-cols-[auto_1fr_auto] md:items-start">
            <div className="flex size-10 items-center justify-center rounded-md border border-sky-200 bg-sky-50 text-primary">
                <TaskIcon className="size-5" aria-hidden="true" />
            </div>

            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-display text-base font-semibold">
                        {task.title}
                    </h3>
                    <Badge
                        variant="outline"
                        className={cn(
                            'gap-1 border',
                            statusStyles[task.status.code],
                        )}
                    >
                        {StatusIcon && (
                            <StatusIcon className="size-3" aria-hidden="true" />
                        )}
                        {task.status.label}
                    </Badge>
                </div>

                {task.description && (
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">
                        {task.description}
                    </p>
                )}

                <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground">
                    <div className="flex gap-1.5">
                        <dt>Prioritas</dt>
                        <dd className="font-mono font-medium text-foreground">
                            P{task.priority}
                        </dd>
                    </div>
                    {task.sourceProgram && (
                        <div className="flex gap-1.5">
                            <dt>Sumber</dt>
                            <dd className="font-medium text-foreground">
                                {task.sourceProgram}
                            </dd>
                        </div>
                    )}
                    {availableAt && (
                        <div className="flex gap-1.5">
                            <dt>Tersedia</dt>
                            <dd className="font-medium text-foreground">
                                {availableAt} WIB
                            </dd>
                        </div>
                    )}
                </dl>
            </div>

            <div className="flex items-center gap-2 md:justify-end">
                {caseLabel && (
                    <span className="rounded border border-border bg-muted px-2 py-1 font-mono text-[0.68rem] text-muted-foreground">
                        {caseLabel}
                    </span>
                )}
                {task.actionUrl && (
                    <Button asChild size="sm" className="gap-2">
                        <Link href={task.actionUrl}>
                            Buka tugas
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                )}
            </div>
        </article>
    );
}

function EmptyAssignment() {
    return (
        <section className="clinical-shadow rounded-lg border border-border bg-white px-6 py-16 text-center">
            <AlertTriangle
                className="mx-auto size-9 text-warning"
                aria-hidden="true"
            />
            <h2 className="mt-4 text-xl font-semibold">
                Belum ada penugasan aktif
            </h2>
            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-muted-foreground">
                Akun Anda sudah aktif, tetapi belum ditempatkan pada sesi
                simulasi. Hubungi fasilitator mata kuliah untuk memperoleh
                penugasan.
            </p>
        </section>
    );
}

export default function WorkQueue({ assignments, tasks, summary }: Props) {
    const { auth, requestId } = usePage().props;
    const assignment = assignments[0];
    const summaryItems = [
        { label: 'Siap', value: summary.ready, color: 'bg-primary' },
        { label: 'Dikerjakan', value: summary.inProgress, color: 'bg-signal' },
        {
            label: 'Perlu perbaikan',
            value: summary.changesRequested,
            color: 'bg-amber-600',
        },
        { label: 'Menunggu', value: summary.waiting, color: 'bg-slate-400' },
        { label: 'Diblokir', value: summary.blocked, color: 'bg-red-700' },
    ];

    return (
        <>
            <Head title="Antrean kerja" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-5 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Ruang kerja terarah
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Antrean kerja
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Selamat datang, {auth.user?.name ?? 'pengguna'}.
                            Sistem hanya menampilkan tugas yang sesuai dengan
                            sesi, program, peran, dan kapabilitas aktif Anda.
                        </p>
                    </div>

                    <dl className="flex flex-wrap gap-x-5 gap-y-2 rounded-md border border-border bg-white px-4 py-3 text-xs">
                        {summaryItems.map((item) => (
                            <div
                                key={item.label}
                                className="flex items-center gap-2"
                            >
                                <span
                                    className={cn(
                                        'size-2 rounded-full',
                                        item.color,
                                    )}
                                    aria-hidden="true"
                                />
                                <dt className="text-muted-foreground">
                                    {item.label}
                                </dt>
                                <dd className="font-mono font-semibold">
                                    {item.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </header>

                {!assignment ? (
                    <EmptyAssignment />
                ) : (
                    <>
                        <section
                            aria-label="Konteks penugasan aktif"
                            className="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 xl:grid-cols-4"
                        >
                            {[
                                ['Sesi', assignment.session.code],
                                ['Skenario', assignment.session.scenarioTitle],
                                ['Program', assignment.program.label],
                                ['Peran', assignment.role.label],
                            ].map(([label, value]) => (
                                <div key={label} className="bg-white px-4 py-3">
                                    <p className="text-[0.68rem] font-bold tracking-wider text-muted-foreground uppercase">
                                        {label}
                                    </p>
                                    <p className="mt-1 truncate text-sm font-semibold">
                                        {value}
                                    </p>
                                </div>
                            ))}
                        </section>

                        <EncounterOrbit tasks={tasks} />

                        <div className="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                            <section
                                aria-labelledby="task-list-title"
                                className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white"
                            >
                                <div className="flex flex-wrap items-end justify-between gap-3 border-b border-border px-5 py-4">
                                    <div>
                                        <p className="text-xs font-bold tracking-[0.12em] text-primary uppercase">
                                            Antrean personal
                                        </p>
                                        <h2
                                            id="task-list-title"
                                            className="mt-1 text-xl font-semibold"
                                        >
                                            Tugas yang tersedia
                                        </h2>
                                    </div>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {tasks.length} tugas terbuka
                                    </span>
                                </div>

                                {tasks.length > 0 ? (
                                    <div>
                                        {tasks.map((task) => (
                                            <TaskRow
                                                key={task.publicId}
                                                task={task}
                                            />
                                        ))}
                                    </div>
                                ) : (
                                    <div className="px-6 py-14 text-center">
                                        <CheckCircle2
                                            className="mx-auto size-8 text-success"
                                            aria-hidden="true"
                                        />
                                        <h3 className="mt-3 text-lg font-semibold">
                                            Tidak ada tugas terbuka
                                        </h3>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            Tugas berikutnya akan muncul setelah
                                            alur sebelumnya selesai atau
                                            fasilitator membuka tahap baru.
                                        </p>
                                    </div>
                                )}
                            </section>

                            <aside className="clinical-shadow overflow-hidden rounded-lg border border-border bg-white">
                                <div className="border-b border-border bg-[#eaf4f8] px-5 py-4">
                                    <div className="flex items-center justify-between gap-3">
                                        <h2 className="text-lg font-semibold">
                                            Konteks sesi
                                        </h2>
                                        <Badge className="bg-[#286a4f] text-white">
                                            {assignment.session.status.label}
                                        </Badge>
                                    </div>
                                </div>

                                <dl className="space-y-3 px-5 py-5 text-sm">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Mata kuliah
                                        </dt>
                                        <dd className="mt-0.5 font-mono font-medium">
                                            {assignment.session.courseCode}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            Kohort
                                        </dt>
                                        <dd className="mt-0.5 font-mono font-medium">
                                            {assignment.session.cohortCode}
                                        </dd>
                                    </div>
                                </dl>

                                <div className="border-t border-border px-5 py-5">
                                    <h3 className="text-sm font-semibold">
                                        Batas kapabilitas
                                    </h3>
                                    <ul className="mt-3 space-y-2">
                                        {assignment.capabilities.map(
                                            (capability) => (
                                                <li
                                                    key={capability.code}
                                                    className="flex gap-2 text-xs leading-5 text-muted-foreground"
                                                >
                                                    <ShieldCheck
                                                        className="mt-0.5 size-3.5 shrink-0 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                    {capability.label}
                                                </li>
                                            ),
                                        )}
                                    </ul>
                                </div>
                            </aside>
                        </div>
                    </>
                )}

                <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3 text-[0.68rem] text-muted-foreground">
                    <p>Data sintetis · bukan untuk pelayanan pasien nyata</p>
                    {requestId && (
                        <p className="font-mono">Request {requestId}</p>
                    )}
                </footer>
            </div>
        </>
    );
}

WorkQueue.layout = {
    breadcrumbs: [
        {
            title: 'Antrean kerja',
            href: work(),
        },
    ],
};
