import { Head, Link, router, usePage } from '@inertiajs/react';
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
    LaboratorySessionMonitor,
    LaboratorySessionMonitorPhase,
    TaskStatusCode,
    WorkQueueSummary,
    WorkTaskItem,
    WorkTaskType,
} from '@/types';

type Props = {
    assignments: AssignmentContext[];
    tasks: WorkTaskItem[];
    summary: WorkQueueSummary;
    selectedSessionCode: string | null;
    selectionRequired: boolean;
    sessionMonitor?: LaboratorySessionMonitor | null;
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

const phaseLabels: Record<LaboratorySessionMonitorPhase, string> = {
    SCHEDULED: 'Terjadwal',
    READY_TO_START: 'Siap dimulai',
    IN_PROGRESS: 'Sedang berjalan',
    PAUSED: 'Dijeda',
    FINALIZED: 'Difinalisasi',
    ENDED: 'Berakhir',
};

const phaseStyles: Record<LaboratorySessionMonitorPhase, string> = {
    SCHEDULED: 'border-slate-300 bg-slate-50 text-slate-700',
    READY_TO_START: 'border-emerald-300 bg-emerald-50 text-emerald-800',
    IN_PROGRESS: 'border-orange-300 bg-orange-50 text-[#87401d]',
    PAUSED: 'border-amber-300 bg-amber-50 text-amber-900',
    FINALIZED: 'border-sky-300 bg-sky-50 text-[#00598f]',
    ENDED: 'border-slate-300 bg-slate-100 text-slate-700',
};

const taskTypeLabels: Record<string, string> = {
    REGISTRATION: 'Registrasi',
    NURSING_INTAKE: 'Asesmen keperawatan',
    MEDICAL_ASSESSMENT: 'Asesmen medis',
    SYNTHETIC_RESULT_RELEASE: 'Rilis hasil sintetis',
    RESULT_ACKNOWLEDGEMENT: 'Tinjau hasil',
    PHARMACY_REVIEW: 'Telaah farmasi',
    PRESCRIPTION_INTERVENTION_RESPONSE: 'Tanggapan intervensi resep',
    DISPENSING: 'Penyerahan obat',
    ENCOUNTER_CLOSURE: 'Penutupan encounter',
    ENCOUNTER_CLOSURE_REVIEW: 'Review penutupan',
    RECORD_REVIEW: 'Review rekam medis',
    RECORD_CORRECTION: 'Koreksi rekam medis',
    RECORD_QUALITY_REVIEW: 'Review mutu rekam medis',
    CODING: 'Koding',
    CODING_SOURCE_CORRECTION: 'Koreksi sumber diagnosis',
    PROCEDURE_SOURCE_CORRECTION: 'Koreksi sumber tindakan',
    CODING_REVIEW: 'Review koding',
    SUPERVISOR_REVIEW: 'Review supervisor',
    SAFETY_DISPOSITION: 'Disposisi keselamatan',
    DEBRIEF: 'Debrief',
};

const roleLabels: Record<string, string> = {
    FACILITATOR: 'Fasilitator',
    REGISTRAR: 'Petugas registrasi',
    LEARNER: 'Mahasiswa',
    SUPERVISOR: 'Supervisor',
    CODER: 'Koder',
    PHARMACIST: 'Farmasis',
    SYSTEM_ADMINISTRATOR: 'Administrator sistem',
};

const programLabels: Record<string, string> = {
    MEDICINE: 'Kedokteran',
    NURSING: 'Keperawatan',
    RMIK: 'RMIK',
    PHARMACY: 'Farmasi',
    FACILITATION: 'Fasilitasi',
    SYSTEM: 'Sistem',
};

const attentionLabels: Record<string, string> = {
    SESSION_NOT_ACTIVE: 'Sesi tidak aktif',
    TASKS_BLOCKED: 'Ada tugas yang diblokir',
    CHANGES_REQUESTED: 'Ada perbaikan yang diminta',
    SUPERVISOR_REVIEW_PENDING: 'Ada review supervisor yang menunggu',
};

const blockerLabels: Record<string, string> = {
    'session.disposable_clone':
        'Gunakan sesi sekali pakai yang dibuat dari sumber sintetis.',
    'session.environment_mode': 'Sesi harus tetap berada dalam mode simulasi.',
    'session.one_synthetic_case':
        'Sesi harus memuat tepat satu kasus sintetis yang lengkap.',
    'session.assignment_roster':
        'Daftar sepuluh penugasan aktif belum lengkap.',
    'session.task_graph': 'Jejak tugas referensi belum lengkap.',
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
                        <dt>Sesi</dt>
                        <dd className="font-mono font-medium text-foreground">
                            {task.sessionCode}
                        </dd>
                    </div>
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

function SessionSelectionRequired() {
    return (
        <section className="clinical-shadow rounded-lg border border-sky-200 bg-sky-50 px-6 py-12 text-center">
            <BookOpenCheck
                className="mx-auto size-9 text-primary"
                aria-hidden="true"
            />
            <h2 className="mt-4 text-xl font-semibold">
                Pilih satu sesi simulasi
            </h2>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                Anda memiliki lebih dari satu sesi aktif. Pilih sesi sebelum
                membuka tugas agar konteks latihan dan data sintetis tidak
                tercampur.
            </p>
        </section>
    );
}

export function FacilitatorSessionMonitor({
    monitor,
}: {
    monitor: LaboratorySessionMonitor;
}) {
    if (monitor.status === 'BLOCKED') {
        return (
            <section
                role="alert"
                aria-labelledby="facilitator-monitor-title"
                className="clinical-shadow overflow-hidden rounded-lg border border-l-4 border-red-300 border-l-red-700 bg-white"
            >
                <div className="flex gap-4 px-5 py-5">
                    <AlertTriangle
                        className="mt-0.5 size-6 shrink-0 text-red-700"
                        aria-hidden="true"
                    />
                    <div>
                        <p className="text-[0.68rem] font-bold tracking-[0.14em] text-red-800 uppercase">
                            Kontrol fasilitator · baca saja
                        </p>
                        <h2
                            id="facilitator-monitor-title"
                            className="mt-1 text-xl font-semibold"
                        >
                            Pemantauan sesi dihentikan
                        </h2>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Struktur sesi tidak memenuhi batas latihan yang
                            aman. Jangan lanjutkan peserta atau memperbaiki data
                            langsung di basis data.
                        </p>
                        <ul className="mt-3 space-y-1.5 text-sm text-red-900">
                            {monitor.blockers.map((blocker) => (
                                <li
                                    key={blocker.id}
                                    className="flex items-start gap-2"
                                >
                                    <Ban
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {blockerLabels[blocker.id] ??
                                        'Pemeriksaan kesiapan sesi belum terpenuhi.'}
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </section>
        );
    }

    const counts = [
        {
            label: 'Penugasan aktif',
            value: monitor.summary.activeAssignments,
        },
        { label: 'Seluruh tugas', value: monitor.summary.totalTasks },
        { label: 'Tugas terbuka', value: monitor.summary.openTasks },
        { label: 'Siap diserahkan', value: monitor.readyTasks.length },
    ];

    return (
        <section
            aria-labelledby="facilitator-monitor-title"
            className="clinical-shadow overflow-hidden rounded-lg border border-l-4 border-sky-200 border-l-[#e87326] bg-white"
        >
            <div className="grid lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.72fr)]">
                <div className="px-5 py-5 md:px-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-[0.68rem] font-bold tracking-[0.14em] text-primary uppercase">
                                Kontrol fasilitator · seluruh sesi · baca saja
                            </p>
                            <h2
                                id="facilitator-monitor-title"
                                className="mt-1 text-xl font-semibold"
                            >
                                Jalur serah terima laboratorium
                            </h2>
                            <p className="mt-1 font-mono text-xs text-muted-foreground">
                                {monitor.session.code} ·{' '}
                                {monitor.encounter.number}
                            </p>
                        </div>
                        <Badge
                            variant="outline"
                            className={cn(
                                'border px-3 py-1 text-xs',
                                phaseStyles[monitor.phase],
                            )}
                        >
                            {phaseLabels[monitor.phase]}
                        </Badge>
                    </div>

                    <dl className="mt-5 grid overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2 xl:grid-cols-4">
                        {counts.map((count) => (
                            <div
                                key={count.label}
                                className="border-b border-border bg-[#f8fbfc] px-4 py-3 last:border-b-0 sm:border-r xl:border-b-0 xl:last:border-r-0 sm:[&:nth-child(2)]:border-r-0 xl:[&:nth-child(2)]:border-r sm:[&:nth-child(n+3)]:border-b-0"
                            >
                                <dt className="text-[0.68rem] font-semibold tracking-wide text-muted-foreground uppercase">
                                    {count.label}
                                </dt>
                                <dd className="mt-1 font-mono text-xl font-semibold text-foreground">
                                    {count.value}
                                </dd>
                            </div>
                        ))}
                    </dl>

                    {monitor.attention.length > 0 && (
                        <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3">
                            <p className="flex items-center gap-2 text-sm font-semibold text-amber-950">
                                <AlertTriangle
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Perlu perhatian fasilitator
                            </p>
                            <ul className="mt-1.5 space-y-1 text-xs leading-5 text-amber-950">
                                {monitor.attention.map((attention) => (
                                    <li key={attention}>
                                        {attentionLabels[attention] ??
                                            'Ada kondisi sesi yang perlu ditinjau.'}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <p className="mt-4 text-xs leading-5 text-muted-foreground">
                        Jumlah tugas adalah bukti operasional, bukan persentase,
                        nilai, atau keputusan penerimaan pilot.
                    </p>
                </div>

                <div className="border-t border-sky-200 bg-[#eaf4f8] px-5 py-5 lg:border-t-0 lg:border-l">
                    <p className="text-[0.68rem] font-bold tracking-[0.14em] text-primary uppercase">
                        Serah terima berikutnya
                    </p>
                    {monitor.readyTasks.length > 0 ? (
                        <ol className="mt-3 space-y-2">
                            {monitor.readyTasks.map((task, index) => (
                                <li
                                    key={`${task.type}-${task.program}-${task.role}-${task.priority}-${index}`}
                                    className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-3 rounded-md border border-sky-200 bg-white px-3 py-3"
                                >
                                    <span
                                        className="flex size-7 items-center justify-center rounded-full bg-primary font-mono text-xs font-semibold text-white"
                                        aria-hidden="true"
                                    >
                                        {index + 1}
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold">
                                            {taskTypeLabels[task.type] ??
                                                task.type.replaceAll('_', ' ')}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {programLabels[task.program] ??
                                                task.program}{' '}
                                            ·{' '}
                                            {roleLabels[task.role] ?? task.role}
                                        </p>
                                    </div>
                                    <span className="font-mono text-[0.68rem] font-semibold text-primary">
                                        P{task.priority}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <div className="mt-3 rounded-md border border-sky-200 bg-white px-4 py-4">
                            <p className="text-sm font-semibold">
                                Belum ada tugas siap
                            </p>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Tinjau fase dan tanda perhatian sebelum membuka
                                tahap berikutnya.
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

export default function WorkQueue({
    assignments,
    tasks,
    summary,
    selectedSessionCode,
    selectionRequired,
    sessionMonitor = null,
}: Props) {
    const { auth, requestId } = usePage().props;
    const sessions = Array.from(
        new Map(
            assignments.map((assignment) => [
                assignment.session.publicId,
                assignment.session,
            ]),
        ).values(),
    );
    const selectedAssignments = assignments.filter(
        (assignment) => assignment.session.code === selectedSessionCode,
    );
    const assignment = selectedAssignments[0];
    const selectedCapabilities = Array.from(
        new Map(
            selectedAssignments
                .flatMap((item) => item.capabilities)
                .map((capability) => [capability.code, capability]),
        ).values(),
    );
    const selectedPrograms = Array.from(
        new Set(selectedAssignments.map((item) => item.program.label)),
    ).join(', ');
    const selectedRoles = Array.from(
        new Set(selectedAssignments.map((item) => item.role.label)),
    ).join(', ');
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

                {sessions.length > 1 && (
                    <section
                        aria-labelledby="session-selector-title"
                        className="flex flex-col gap-3 rounded-lg border border-sky-200 bg-[#eaf4f8] px-4 py-4 sm:flex-row sm:items-end sm:justify-between"
                    >
                        <div>
                            <h2
                                id="session-selector-title"
                                className="text-sm font-semibold"
                            >
                                Sesi simulasi aktif
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Antrean hanya memuat tugas dari sesi yang Anda
                                pilih.
                            </p>
                        </div>
                        <div className="w-full sm:max-w-sm">
                            <label
                                htmlFor="work-session"
                                className="mb-1.5 block text-xs font-semibold text-foreground"
                            >
                                Pilih sesi
                            </label>
                            <select
                                id="work-session"
                                value={selectedSessionCode ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        work().url,
                                        { session: event.target.value },
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            replace: true,
                                        },
                                    )
                                }
                                className="h-10 w-full rounded-md border border-input bg-white px-3 text-sm font-medium text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                            >
                                <option value="" disabled>
                                    Pilih sesi aktif
                                </option>
                                {sessions.map((session) => (
                                    <option
                                        key={session.publicId}
                                        value={session.code}
                                    >
                                        {session.code} · {session.courseCode} ·{' '}
                                        {session.cohortCode}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </section>
                )}

                {assignments.length === 0 ? (
                    <EmptyAssignment />
                ) : selectionRequired || !assignment ? (
                    <SessionSelectionRequired />
                ) : (
                    <>
                        <section
                            aria-label="Konteks penugasan aktif"
                            className="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 xl:grid-cols-4"
                        >
                            {[
                                ['Sesi', assignment.session.code],
                                ['Skenario', assignment.session.scenarioTitle],
                                ['Program', selectedPrograms],
                                ['Peran', selectedRoles],
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

                        {sessionMonitor && (
                            <FacilitatorSessionMonitor
                                monitor={sessionMonitor}
                            />
                        )}

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
                                        {selectedCapabilities.map(
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
        { title: 'Meja kerja', href: '/desk' },
        {
            title: 'Kerja saya',
            href: work(),
        },
    ],
};
