import type {
    ClinicalFinding,
    EncounterClosureReadinessCheck,
    EncounterClosureSourceSnapshot,
} from './clinical';
import type {
    CodedStatus,
    EncounterContext,
    PatientContext,
} from './outpatient';

export type RecordQualityFinding = {
    publicId: string;
    code: string;
    severity: CodedStatus & {
        code: 'BLOCKING' | 'NON_BLOCKING' | 'INFORMATIONAL';
    };
    message: string;
    affectedResourceType: 'ENCOUNTER_CLOSURE';
    affectedResourcePublicId: string;
    affectedVersionNumber: number;
    affectedContentHash: string;
    responsibleAuthor: string;
    requestedAction: string;
    correctionRequest: {
        publicId: string;
        status: CodedStatus;
    } | null;
    canRequestCorrection: boolean;
    correctionRequestKey: string;
    correctionUrl: string;
};

export type RecordQualityReviewVersion = {
    publicId: string;
    versionNumber: number;
    checklistVersion: string;
    status: CodedStatus & {
        code: 'DRAFT' | 'SUBMITTED' | 'CHANGES_REQUESTED' | 'APPROVED';
    };
    content: {
        checklistVersion: string;
        completeness: {
            checklistVersion: string;
            ready: boolean;
            checks: EncounterClosureReadinessCheck[];
        };
        assemblySnapshot: Record<string, unknown>;
        manualFindings: Array<Record<string, unknown>>;
        resolvedCorrections: Array<{
            correctionRequestPublicId: string;
            priorSourceHash: string;
            responseClosurePublicId: string;
            responseClosureContentHash: string;
            status: string;
        }>;
        codingDocumentationCorrection: {
            publicId: string;
            priorRecordQualityReviewPublicId: string;
            requestedSourceHash: string;
            responseClosurePublicId: string;
            responseClosureContentHash: string;
        } | null;
        procedureDocumentationCorrection: {
            publicId: string;
            priorRecordQualityReviewPublicId: string;
            requestedSourceHash: string;
            requestedClosureHash: string;
            responseClosurePublicId: string;
            responseClosureContentHash: string;
        } | null;
    };
    contentHash: string;
    reviewer: string;
    reviewerRole: string;
    recordedAt: string;
    submittedAt: string | null;
    reviewedAt: string | null;
    changeReason: string | null;
    findings: RecordQualityFinding[];
    reviews: Array<{
        publicId: string;
        action: CodedStatus & {
            code: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES';
        };
        reviewer: string;
        comment: string | null;
        findings: ClinicalFinding[] | null;
        reviewedContentHash: string;
        reviewedAt: string;
    }>;
};

export type RecordQualityWorkspaceProps = {
    encounter: EncounterContext;
    patient: PatientContext;
    session: {
        code: string;
        scenarioTitle: string;
    };
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canAuthor: boolean;
        canReview: boolean;
        canCode: boolean;
    };
    task: {
        publicId: string;
        type: 'RECORD_REVIEW' | 'RECORD_QUALITY_REVIEW';
        status: CodedStatus;
    } | null;
    completeness: {
        checklistVersion: string;
        ready: boolean;
        checks: EncounterClosureReadinessCheck[];
    };
    assembly: {
        closure: {
            publicId: string;
            versionNumber: number;
            schemaVersion: string;
            status: string;
            contentHash: string;
            content: Record<string, unknown>;
            author: string;
            authorAssignmentPublicId: string;
            reviewActions: Array<Record<string, unknown>>;
        } | null;
        clinicalSources: EncounterClosureSourceSnapshot;
    };
    currentClosure: {
        publicId: string;
        versionNumber: number;
        status: CodedStatus;
        contentHash: string;
        authorAssignmentPublicId: string;
    } | null;
    document: {
        latestVersion: RecordQualityReviewVersion | null;
        history: RecordQualityReviewVersion[];
        canAuthor: boolean;
        canReview: boolean;
        requiresChangeReason: boolean;
    };
    codingCorrection: {
        publicId: string;
        status: CodedStatus;
        sourceType: 'DIAGNOSIS' | 'PROCEDURE';
        reason: string;
        requestedBy: string;
        responsibleAuthor: string;
        requestedSourceHash: string;
        requestedClosureHash: string | null;
        responseMedicalVersionPublicId: string | null;
        responseMedicalContentHash: string | null;
        responseClosurePublicId: string | null;
        responseClosureContentHash: string | null;
    } | null;
    corrections: Array<{
        publicId: string;
        status: CodedStatus;
        reason: string;
        requestedSourceHash: string;
        requestedAt: string;
        findingCode: string;
        findingMessage: string;
        responsibleAuthor: string;
        responseClosurePublicId: string | null;
        responseClosureVersion: number | null;
        responseContentHash: string | null;
        resolvable: boolean;
    }>;
    formOptions: {
        requestKey: string;
        reviewRequestKey: string;
        correctionRequestKey: string;
        intents: Array<'SAVE_DRAFT' | 'SUBMIT'>;
        findingSeverities: Array<
            CodedStatus & {
                code: 'BLOCKING' | 'NON_BLOCKING' | 'INFORMATIONAL';
            }
        >;
        reviewActions: Array<
            CodedStatus & {
                code: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES';
            }
        >;
    };
    urls: {
        store: string;
        review: string | null;
        encounter: string;
        closure: string;
        coding: string;
        workQueue: string;
    };
};
