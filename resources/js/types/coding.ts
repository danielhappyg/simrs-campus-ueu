import type {
    CodedStatus,
    EncounterContext,
    PatientContext,
} from './outpatient';

export type CodingConcept = {
    publicId: string;
    code: string;
    display: string;
};

export type CodingCandidate = {
    publicId: string;
    rank: number;
    concept: CodingConcept;
    confidence: CodedStatus & {
        code: 'EXACT' | 'STRONG_MATCH' | 'REVIEW_REQUIRED';
    };
    score: number;
    evidence: Record<string, unknown>;
    specificityWarning: string | null;
};

export type CodingSuggestionRun = {
    publicId: string;
    sourceType: CodedStatus & { code: 'DIAGNOSIS' | 'PROCEDURE' };
    outcome: CodedStatus & {
        code: 'CANDIDATES' | 'NO_RELIABLE_CANDIDATE';
    };
    engineType: string;
    engineVersion: string;
    configurationHash: string;
    normalizedInputHash: string;
    generatedAt: string;
    release: {
        publicId: string;
        system: string;
        logicalVersion: string;
        sourceSha256: string;
    };
    canDecide: boolean;
    decisionUrl: string;
    candidates: CodingCandidate[];
    decisions: Array<{
        publicId: string;
        decision: CodedStatus;
        candidatePublicId: string | null;
        reason: string | null;
        resultingAssignmentPublicId: string | null;
        decidedAt: string;
    }>;
};

export type CodeAssignment = {
    publicId: string;
    sourceType: CodedStatus & { code: 'DIAGNOSIS' | 'PROCEDURE' };
    status: CodedStatus & {
        code:
            | 'DRAFT'
            | 'SUBMITTED'
            | 'CHANGES_REQUESTED'
            | 'APPROVED'
            | 'REVIEW_REQUIRED';
    };
    selectionMethod: CodedStatus & {
        code: 'SUGGESTED' | 'MANUAL';
    };
    concept: CodingConcept;
    sourceClinicalContentHash: string;
    sourceStatementHash: string;
    terminologySourceHash: string;
    contentHash: string;
    rationale: string | null;
    changeReason: string | null;
    coder: string;
    recordedAt: string;
    submittedAt: string | null;
    reviewedAt: string | null;
    canSubmit: boolean;
    canReview: boolean;
    submitUrl: string;
    reviewUrl: string;
    reviews: Array<{
        publicId: string;
        action: CodedStatus;
        reviewer: string;
        comment: string | null;
        findings: Array<Record<string, unknown>> | null;
        reviewedContentHash: string;
        reviewedAt: string;
    }>;
};

export type CodingSource = {
    publicId: string;
    sourceType: CodedStatus & { code: 'DIAGNOSIS' | 'PROCEDURE' };
    terminologySystem: 'ICD_10' | 'ICD_9_CM';
    correctionSupported: boolean;
    authoredText: string;
    certainty: CodedStatus | null;
    role: CodedStatus | null;
    clinicalStatus: string;
    clinicianCode: {
        system: string;
        code: string;
        display: string;
        version: string | null;
    } | null;
    sourceVersion: {
        publicId: string | null;
        versionNumber: number | null;
        schemaVersion: string | null;
        status: CodedStatus | null;
        contentHash: string | null;
        documentContentHash: string | null;
        author: string;
    };
    procedureDetails: {
        performedStartAt: string;
        performedEndAt: string | null;
        performerText: string;
        bodySiteText: string | null;
        outcomeText: string | null;
        basedOnServiceRequestPublicId: string | null;
    } | null;
    canGenerate: boolean;
    suggestionUrl: string;
    latestRun: CodingSuggestionRun | null;
    assignmentHistory: CodeAssignment[];
};

export type CodingWorkspaceProps = {
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
        canCode: boolean;
        canReview: boolean;
    };
    task: {
        publicId: string;
        type: 'CODING' | 'CODING_REVIEW';
        status: CodedStatus;
    } | null;
    documentationCorrection: {
        publicId: string;
        sourceType: 'DIAGNOSIS' | 'PROCEDURE';
        status: CodedStatus;
        reason: string;
        responsibleAuthor: string;
        requestedAt: string;
        requestedSourceHash: string;
        requestedClosureHash: string | null;
        responseMedicalVersionPublicId: string | null;
        responseClosurePublicId: string | null;
    } | null;
    prerequisites: {
        qualityApproved: boolean;
        qualityReviewPublicId: string | null;
        qualityReviewVersion: number | null;
        medicalSourceApproved: boolean;
        medicalSourcePublicId: string | null;
        medicalSourceVersion: number | null;
        medicalSourceHash: string | null;
        closureSourceApproved: boolean;
        closureSourcePublicId: string | null;
        closureSourceVersion: number | null;
        closureSourceHash: string | null;
    };
    releases: Array<{
        system: CodedStatus & { code: 'ICD_10' | 'ICD_9_CM' };
        sourceType: 'DIAGNOSIS' | 'PROCEDURE';
        active: boolean;
        release: {
            publicId: string;
            logicalVersion: string;
            status: CodedStatus;
            sourceFilename: string;
            sourceSha256: string;
            rowCount: number;
            ignoredBlankRows: number;
            provenance: string;
            activatedAt: string | null;
        } | null;
    }>;
    sources: CodingSource[];
    selectedSourcePublicId: string;
    manualSearch: {
        query: string;
        system: 'ICD_10' | 'ICD_9_CM';
        error: string | null;
        results: Array<{
            concept: CodingConcept;
            confidence: CodedStatus;
            score: number;
            evidence: Record<string, unknown>;
            specificityWarning: string | null;
        }>;
    };
    formOptions: {
        suggestionRequestKey: string;
        decisionRequestKey: string;
        reviewRequestKey: string;
        decisionTypes: CodedStatus[];
        reviewActions: CodedStatus[];
    };
    urls: {
        self: string;
        recordQuality: string;
        workQueue: string;
    };
};
