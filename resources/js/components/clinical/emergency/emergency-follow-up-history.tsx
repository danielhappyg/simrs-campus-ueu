import { CheckCircle2, History, TestTube2 } from 'lucide-react';
import { EvidenceTime } from './emergency-shared';
import type {
    EmergencyDiagnosticAssignmentHistoryItem,
    EmergencyFollowUpAssignment,
} from './types';

const assignmentStateLabel: Record<
    EmergencyFollowUpAssignment['state'],
    string
> = {
    PROPOSED: 'Awaiting acceptance',
    ACCEPTED: 'Accepted',
    REVOKED: 'Revoked',
    SUPERSEDED: 'Superseded',
};

function AssignmentVersion({
    assignment,
}: {
    assignment: EmergencyFollowUpAssignment;
}) {
    return (
        <li className="rounded-md border border-border bg-background p-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        Version {assignment.version} ·{' '}
                        {assignment.assignee.name ?? 'Physician unavailable'}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {assignmentStateLabel[assignment.state]}
                    </p>
                </div>
                <EvidenceTime value={assignment.proposed_at} />
            </div>
            <dl className="mt-3 grid gap-2 md:grid-cols-2">
                <div>
                    <dt className="text-xs font-semibold text-muted-foreground">
                        Proposed by
                    </dt>
                    <dd>{assignment.proposed_by.name ?? '—'}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold text-muted-foreground">
                        Effective from
                    </dt>
                    <dd>
                        <EvidenceTime value={assignment.effective_at} />
                    </dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold text-muted-foreground">
                        Assignment reason
                    </dt>
                    <dd className="whitespace-pre-wrap">{assignment.reason}</dd>
                </div>
                <div>
                    <dt className="text-xs font-semibold text-muted-foreground">
                        Handoff note
                    </dt>
                    <dd className="whitespace-pre-wrap">
                        {assignment.handoff_note}
                    </dd>
                </div>
            </dl>
            {assignment.accepted_at ? (
                <p className="mt-3 flex flex-wrap items-center gap-1.5 rounded-md bg-success/5 px-2.5 py-2 text-xs text-success">
                    <CheckCircle2 aria-hidden="true" className="size-3.5" />
                    Accepted by {assignment.accepted_by_name ??
                        'physician'} ·{' '}
                    <EvidenceTime value={assignment.accepted_at} />
                </p>
            ) : null}
        </li>
    );
}

export function EmergencyDiagnosticAssignmentHistory({
    items,
    emptyMessage = 'No diagnostic-result responsibility transfers have been recorded.',
}: {
    items: EmergencyDiagnosticAssignmentHistoryItem[];
    emptyMessage?: string;
}) {
    return (
        <section
            aria-labelledby="diagnostic-assignment-history-title"
            className="rounded-lg border border-border bg-card p-4"
        >
            <h3
                id="diagnostic-assignment-history-title"
                className="flex items-center gap-2 font-semibold"
            >
                <History aria-hidden="true" className="size-4 text-primary" />
                Diagnostic-result responsibility history
            </h3>
            <p className="mt-1 text-xs text-muted-foreground">
                The full assignment chain remains visible, including completed
                orders.
            </p>
            {items.length ? (
                <div className="mt-3 space-y-3">
                    {items.map((item) => (
                        <details
                            key={`${item.order_type}:${item.order_public_id}`}
                            className="rounded-lg border border-border bg-muted/20 p-3"
                        >
                            <summary className="min-h-11 cursor-pointer py-2">
                                <span className="inline-flex items-center gap-2 font-semibold">
                                    <TestTube2
                                        aria-hidden="true"
                                        className="size-4 text-primary"
                                    />
                                    {item.label}
                                </span>
                                <span className="ml-2 text-xs text-muted-foreground">
                                    {item.order_type === 'LABORATORY'
                                        ? 'Laboratory'
                                        : 'Radiology'}{' '}
                                    · {item.history.length} versions ·{' '}
                                    {assignmentStateLabel[item.current.state]}
                                </span>
                            </summary>
                            <ol className="mt-2 space-y-2">
                                {item.history.map((assignment) => (
                                    <AssignmentVersion
                                        key={assignment.public_id}
                                        assignment={assignment}
                                    />
                                ))}
                            </ol>
                        </details>
                    ))}
                </div>
            ) : (
                <p className="mt-3 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground">
                    {emptyMessage}
                </p>
            )}
        </section>
    );
}
