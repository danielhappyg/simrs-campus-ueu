export type TaskStatusCode =
    | 'READY'
    | 'WAITING'
    | 'BLOCKED'
    | 'IN_PROGRESS'
    | 'SUBMITTED'
    | 'CHANGES_REQUESTED'
    | 'COMPLETE'
    | 'CANCELLED';

export type WorkTaskType =
    | 'SESSION_ORIENTATION'
    | 'REGISTRATION'
    | 'NURSING_INTAKE'
    | 'MEDICAL_ASSESSMENT'
    | 'RESULT_ACKNOWLEDGEMENT'
    | 'PHARMACY_REVIEW'
    | 'DISPENSING'
    | 'ENCOUNTER_CLOSURE'
    | 'RECORD_REVIEW'
    | 'SUPERVISOR_REVIEW';

export type AssignmentContext = {
    publicId: string;
    program: { code: string; label: string };
    role: { code: string; label: string };
    capabilities: Array<{ code: string; label: string }>;
    session: {
        publicId: string;
        code: string;
        status: { code: string; label: string };
        courseCode: string;
        cohortCode: string;
        scenarioTitle: string;
    };
};

export type WorkTaskItem = {
    publicId: string;
    type: WorkTaskType;
    title: string;
    description: string | null;
    status: { code: TaskStatusCode; label: string };
    priority: number;
    sourceProgram: string | null;
    availableAt: string | null;
    context: Record<string, unknown> | null;
    assignmentPublicId: string;
    sessionCode: string;
};

export type WorkQueueSummary = {
    ready: number;
    inProgress: number;
    waiting: number;
    blocked: number;
    changesRequested: number;
};
