import type {
    CodedStatus,
    EncounterContext,
    PatientContext,
} from './outpatient';

export type ClinicalEntryStatusCode =
    | 'DRAFT'
    | 'SUBMITTED'
    | 'CHANGES_REQUESTED'
    | 'APPROVED'
    | 'AMENDED'
    | 'ENTERED_IN_ERROR';

export type ClinicalReviewActionCode =
    'SUBMIT' | 'REQUEST_CHANGES' | 'APPROVE_SIMULATION';

export type NursingVitalKey =
    | 'temperature'
    | 'heart_rate'
    | 'respiratory_rate'
    | 'systolic_blood_pressure'
    | 'diastolic_blood_pressure'
    | 'oxygen_saturation';

export type NursingVitalDefinition = {
    label: string;
    code: string;
    display: string;
    unitCode: string;
    unitDisplay: string;
};

export type NursingVitalContent = {
    codeSystem: string;
    code: string;
    display: string;
    codeVersion: string | null;
    mappingVersion: string;
    value: string | null;
    unitSystem: string;
    unitCode: string;
    unitDisplay: string;
};

export type ClinicalReviewRecord = {
    publicId: string;
    action: {
        code: ClinicalReviewActionCode;
        label: string;
    };
    actor: string;
    comment: string | null;
    findings: ClinicalFinding[] | null;
    occurredAt: string;
};

export type ClinicalFinding = {
    code: string;
    severity: 'BLOCKING' | 'NON_BLOCKING' | 'INFORMATIONAL';
    message: string;
};

export type NursingIntakeContent = {
    historySource: string | null;
    chiefComplaint: string | null;
    onsetDuration: string | null;
    consciousness: string | null;
    allergyAssessment: {
        state: string | null;
        details: string | null;
    };
    currentMedication: {
        state: string | null;
        details: string | null;
    };
    vitalObservations: Record<NursingVitalKey, NursingVitalContent>;
    safetyScreenResponses: Array<{
        questionCode: string;
        response: 'YES' | 'NO' | 'UNKNOWN';
        note: string | null;
    }>;
    safetyDecision: string | null;
    note: string | null;
    handoffSummary: string | null;
};

export type MedicalAssessmentContent = {
    history: {
        source: string | null;
        presentIllness: string | null;
        pastMedical: string | null;
        family: string | null;
        social: string | null;
    };
    examination: {
        general: string | null;
        focused: string | null;
    };
    assessmentSummary: string | null;
    diagnoses: Array<{
        authoredText: string;
        certainty: string | null;
        role: string | null;
        onsetAt: string | null;
    }>;
    serviceRequests: Array<{
        requestType: string | null;
        authoredService: string;
        clinicalQuestion: string | null;
        priority: string | null;
        sourceDiagnosisIndex: number | null;
    }>;
    medicationRequests: Array<{
        authoredMedication: string;
        form: string | null;
        strength: string | null;
        doseValue: string | null;
        doseUnit: string | null;
        route: string | null;
        frequency: string | null;
        duration: string | null;
        quantityValue: string | null;
        quantityUnit: string | null;
        directions: string | null;
        indicationText: string | null;
        sourceDiagnosisIndex: number | null;
    }>;
    plan: {
        carePlan: string | null;
        education: string | null;
        followUp: string | null;
        intendedDisposition: string | null;
    };
};

export type ClinicalVersionSummary = {
    publicId: string;
    versionNumber: number;
    schemaVersion: string;
    content: NursingIntakeContent;
    contentHash: string;
    status: CodedStatus & { code: ClinicalEntryStatusCode };
    author: string;
    authorRole: string;
    clinicalOccurrenceAt: string;
    recordedAt: string;
    changeReason: string | null;
    reviews: ClinicalReviewRecord[];
};

export type MedicalClinicalVersionSummary = Omit<
    ClinicalVersionSummary,
    'content'
> & {
    content: MedicalAssessmentContent;
};

export type ClinicalWorkspaceBase = {
    encounter: EncounterContext;
    patient: PatientContext;
    session: {
        publicId?: string;
        code: string;
        scenarioTitle: string;
    };
    urls: {
        encounter: string;
        workQueue: string;
    };
};

export type NursingIntakeWorkspaceProps = ClinicalWorkspaceBase & {
    assignment: {
        publicId: string;
        program: string;
        role: string;
    };
    task: {
        publicId: string;
        status: CodedStatus;
    } | null;
    document: {
        publicId: string | null;
        type: 'NURSING_INTAKE';
        label: string;
        schemaVersion: string;
        lifecycleStatus:
            (CodedStatus & { code: ClinicalEntryStatusCode }) | null;
        latestVersion: ClinicalVersionSummary | null;
        history: ClinicalVersionSummary[];
        canEdit: boolean;
        requiresChangeReason: boolean;
    };
    formOptions: {
        requestKey: string;
        defaultOccurrenceAt: string;
        intents: Array<'SAVE_DRAFT' | 'SUBMIT'>;
        allergyStates: CodedStatus[];
        medicationStates: CodedStatus[];
        safetyDecisions: CodedStatus[];
        vitals: Record<NursingVitalKey, NursingVitalDefinition>;
        safetyQuestions: Array<{ code: string; label: string }>;
    };
    urls: ClinicalWorkspaceBase['urls'] & {
        store: string;
    };
};

export type MedicalAssessmentWorkspaceProps = ClinicalWorkspaceBase & {
    assignment: {
        publicId: string;
        program: string;
        role: string;
    };
    task: {
        publicId: string;
        type: 'MEDICAL_ASSESSMENT' | 'CODING_SOURCE_CORRECTION';
        status: CodedStatus;
    } | null;
    codingCorrection: {
        publicId: string;
        status: CodedStatus;
        reason: string;
        requestedBy: string;
        requestedAt: string;
        sourceConditionPublicId: string;
        sourceStatement: string;
        sourceMedicalVersionPublicId: string;
        sourceMedicalVersionNumber: number;
        requestedSourceHash: string;
    } | null;
    nursingSource: {
        versionPublicId: string;
        versionNumber: number;
        contentHash: string;
        status: CodedStatus & { code: ClinicalEntryStatusCode };
        author: string;
        content: NursingIntakeContent;
        observations: Array<{
            code: string;
            display: string;
            value: string;
            unitDisplay: string;
            mappingVersion: string;
        }>;
    } | null;
    document: {
        publicId: string | null;
        type: 'MEDICAL_ASSESSMENT';
        label: string;
        schemaVersion: string;
        lifecycleStatus:
            (CodedStatus & { code: ClinicalEntryStatusCode }) | null;
        latestVersion: MedicalClinicalVersionSummary | null;
        history: MedicalClinicalVersionSummary[];
        canEdit: boolean;
        requiresChangeReason: boolean;
        amendmentMode: boolean;
        ordersLocked: boolean;
        workflowEnabled: boolean;
    };
    formOptions: {
        requestKey: string;
        defaultOccurrenceAt: string;
        intents: Array<'SAVE_DRAFT' | 'SUBMIT'>;
        diagnosisCertainties: CodedStatus[];
        diagnosisRoles: CodedStatus[];
    };
    urls: ClinicalWorkspaceBase['urls'] & {
        store: string;
    };
};

export type ClinicalReviewWorkspaceProps = ClinicalWorkspaceBase & {
    document: {
        entryPublicId: string;
        versionPublicId: string;
        type: string;
        label: string;
        versionNumber: number;
        schemaVersion: string;
        content: NursingIntakeContent | MedicalAssessmentContent;
        contentHash: string;
        status: CodedStatus & { code: ClinicalEntryStatusCode };
        author: string;
        authorRole: string;
        clinicalOccurrenceAt: string;
        recordedAt: string;
        observations: Array<{
            codeSystem: string;
            code: string;
            display: string;
            value: string;
            unitCode: string;
            unitDisplay: string;
            mappingVersion: string;
        }>;
        conditions: Array<{
            publicId: string;
            authoredText: string;
            certainty: CodedStatus;
            role: CodedStatus;
            clinicalStatus: string;
            codeSystem: string | null;
            code: string | null;
            display: string | null;
            codeVersion: string | null;
            onsetAt: string | null;
            recordedAt: string;
        }>;
        serviceRequests: Array<{
            publicId: string;
            requestType: string;
            authoredService: string;
            clinicalQuestion: string;
            priority: string;
            status: string;
            sourceDiagnosis: string | null;
        }>;
        medicationRequests: Array<{
            publicId: string;
            authoredMedication: string;
            form: string | null;
            strength: string | null;
            doseValue: string;
            doseUnit: string;
            route: string;
            frequency: string;
            duration: string;
            quantityValue: string;
            quantityUnit: string;
            directions: string;
            indicationText: string | null;
            status: string;
            sourceDiagnosis: string | null;
        }>;
        reviews: ClinicalReviewRecord[];
        canReview: boolean;
    };
    formOptions: {
        requestKey: string;
        actions: Array<'REQUEST_CHANGES' | 'APPROVE_SIMULATION'>;
    };
    urls: ClinicalWorkspaceBase['urls'] & {
        storeDecision: string;
    };
};

export type DiagnosticResultRecord = {
    publicId: string;
    versionNumber: number;
    status: CodedStatus;
    reportCode: string;
    reportDisplay: string;
    content: {
        synthetic: true;
        narrativeConclusion: string;
        components: Array<{
            code: string;
            display: string;
            value: string;
            unit: string | null;
        }>;
    };
    contentHash: string;
    effectiveAt: string;
    issuedAt: string;
    performer: string;
    current: boolean;
    acknowledgements: Array<{
        publicId: string;
        actor: string;
        outcome: string;
        comment: string | null;
        acknowledgedAt: string;
    }>;
    acknowledgement: {
        allowed: boolean;
        requestKey: string;
        url: string;
    };
};

export type OrderResultWorkspaceProps = ClinicalWorkspaceBase & {
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canRelease: boolean;
        canAcknowledge: boolean;
    };
    serviceRequests: Array<{
        publicId: string;
        sequenceNumber: number;
        requestType: string;
        authoredService: string;
        clinicalQuestion: string;
        priority: string;
        status: string;
        authoredAt: string;
        requester: string;
        source: {
            medicalVersionPublicId: string;
            medicalVersionNumber: number;
            medicalContentHash: string;
            diagnosis: string | null;
        };
        release: {
            allowed: boolean;
            requestKey: string;
            url: string;
        };
        currentResultPublicId: string | null;
        results: DiagnosticResultRecord[];
    }>;
};

export type PharmacyReviewItemOutcomeCode =
    'CLEAR' | 'FINDING' | 'NOT_APPLICABLE';
export type PharmacyReviewOutcomeCode =
    'ACCEPT' | 'CLARIFICATION_REQUIRED' | 'RECOMMEND_CANCEL';
export type PharmacyResponseActionCode = 'EXPLANATION' | 'REPLACE' | 'CANCEL';
export type MedicationDispenseOutcomeCode =
    'COMPLETE' | 'PARTIAL' | 'NOT_DISPENSED';
export type DispensePreparationReviewActionCode =
    'APPROVE_SIMULATION' | 'REQUEST_CHANGES';

export type PharmacyReviewRecord = {
    publicId: string;
    versionNumber: number;
    overallOutcome: CodedStatus & { code: PharmacyReviewOutcomeCode };
    domainResults: Record<
        'administrative' | 'pharmaceutical' | 'clinical',
        Array<{
            criterionCode: string;
            outcome: PharmacyReviewItemOutcomeCode;
            comment: string | null;
        }>
    >;
    contentHash: string;
    reviewer: string;
    reviewedAt: string;
};

export type PharmacyInterventionRecord = {
    publicId: string;
    status: 'OPEN' | 'RESPONDED' | 'RESOLVED';
    issueCategory: string;
    urgency: string;
    question: string;
    recommendation: string | null;
    openedBy: string;
    openedAt: string;
    messages: Array<{
        publicId: string;
        messageType:
            'PHARMACY_QUERY' | 'PRESCRIBER_RESPONSE' | 'PHARMACY_RESOLUTION';
        responseAction: PharmacyResponseActionCode | null;
        messageText: string;
        author: string;
        replacementPublicId: string | null;
        authoredAt: string;
    }>;
    response: {
        allowed: boolean;
        requestKey: string;
        url: string;
    };
};

export type PharmacyMedicationRequestRecord = {
    publicId: string;
    sequenceNumber: number;
    revisionNumber: number;
    replacesPublicId: string | null;
    replacementPublicId: string | null;
    replacementReason: string | null;
    cancellationReason: string | null;
    authoredMedication: string;
    form: string | null;
    strength: string | null;
    doseValue: string;
    doseUnit: string;
    route: string;
    frequency: string;
    duration: string;
    quantityValue: string;
    quantityUnit: string;
    directions: string;
    indicationText: string | null;
    status: string;
    authoredAt: string;
    requester: string;
    source: {
        medicalVersionPublicId: string;
        medicalVersionNumber: number;
        medicalContentHash: string;
        diagnosis: string | null;
    };
    reviews: PharmacyReviewRecord[];
    interventions: PharmacyInterventionRecord[];
    reviewAction: {
        allowed: boolean;
        requestKey: string;
        interventionRequestKey: string;
        url: string;
    };
    dispenseAction: {
        allowed: boolean;
        requestKey: string;
        url: string;
    };
    dispensePreparations: Array<{
        publicId: string;
        versionNumber: number;
        outcome: CodedStatus & { code: MedicationDispenseOutcomeCode };
        quantity: string;
        unit: string;
        outcomeReason: string | null;
        content: {
            synthetic: boolean;
            preparationNotes: string | null;
            handoffRecipient: string | null;
            counselingTopics: string[];
            counselingAcknowledged: boolean;
            lotNumber: string | null;
            expiresOn: string | null;
        };
        contentHash: string;
        changeReason: string | null;
        preparer: string;
        preparedAt: string;
        review: {
            action: CodedStatus & {
                code: DispensePreparationReviewActionCode;
            };
            comment: string | null;
            checker: string;
            sourceContentHash: string;
            reviewedAt: string;
        } | null;
    }>;
    finalCheckAction: {
        allowed: boolean;
        requestKey: string;
        preparationPublicId: string | null;
        url: string;
    };
    dispense: {
        publicId: string;
        outcome: CodedStatus & { code: MedicationDispenseOutcomeCode };
        quantity: string;
        unit: string;
        outcomeReason: string | null;
        content: {
            synthetic: boolean;
            preparationNotes: string | null;
            finalCheckConfirmed: boolean;
            finalCheckNotes: string | null;
            sameActorCheckPermittedByScenario: boolean;
            handoffRecipient: string | null;
            counselingTopics: string[];
            counselingAcknowledged: boolean;
            lotNumber: string | null;
            expiresOn: string | null;
            preparationPublicId: string;
            preparationVersion: number;
            preparationContentHash: string;
            reviewActionPublicId: string;
        };
        contentHash: string;
        preparer: string;
        checker: string;
        preparedAt: string;
        checkedAt: string;
        preparationPublicId: string | null;
        stockMovement: {
            publicId: string;
            quantity: string;
            balanceBefore: string;
            balanceAfter: string;
        } | null;
    } | null;
};

export type PharmacyWorkspaceProps = ClinicalWorkspaceBase & {
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canReview: boolean;
        canRespond: boolean;
        canDispense: boolean;
        canFinalCheck: boolean;
    };
    allergySource: {
        state: string;
        label: string;
        details: string | null;
        sourceVersionPublicId: string;
        sourceContentHash: string;
        assessedAt: string;
    } | null;
    reviewDefinition: {
        criteriaVersion: string;
        humanAuthored: true;
        criteria: Record<
            'administrative' | 'pharmaceutical' | 'clinical',
            Array<{ code: string; label: string }>
        >;
        itemOutcomes: Array<
            CodedStatus & { code: PharmacyReviewItemOutcomeCode }
        >;
        overallOutcomes: Array<
            CodedStatus & { code: PharmacyReviewOutcomeCode }
        >;
    };
    medicationRequests: PharmacyMedicationRequestRecord[];
    stocks: Array<{
        id: number;
        publicId: string;
        authoredMedication: string;
        form: string | null;
        strength: string | null;
        lotNumber: string;
        expiresOn: string;
        quantityOnHand: string;
        unit: string;
        synthetic: true;
    }>;
    formOptions: {
        responseActions: Array<
            CodedStatus & { code: PharmacyResponseActionCode }
        >;
        dispenseOutcomes: Array<
            CodedStatus & { code: MedicationDispenseOutcomeCode }
        >;
    };
};

export type EncounterClosureReadinessEvidence = Record<string, unknown> & {
    publicId?: string;
    label?: string;
    status?: string;
};

export type EncounterClosureReadinessCheck = {
    code: string;
    label: string;
    passed: boolean;
    blocking: boolean;
    detail: string;
    evidence: EncounterClosureReadinessEvidence[];
};

export type EncounterClosureSourceSnapshot = {
    nursing: {
        publicId: string;
        versionNumber: number;
        schemaVersion: string;
        status: string;
        contentHash: string;
    } | null;
    medical: {
        publicId: string;
        versionNumber: number;
        schemaVersion: string;
        status: string;
        contentHash: string;
    } | null;
    diagnoses: Array<{
        publicId: string;
        authoredText: string;
        certainty: string;
        role: string;
        codeSystem: string | null;
        code: string | null;
        display: string | null;
        codeVersion: string | null;
    }>;
    results: Array<{
        serviceRequestPublicId: string;
        authoredService: string;
        requestStatus: string;
        resultPublicId: string | null;
        resultVersion: number | null;
        resultContentHash: string | null;
        resultConclusion: string | null;
        acknowledgementPublicId: string | null;
        acknowledgementOutcome: string | null;
        acknowledgedAt: string | null;
    }>;
    medications: Array<{
        medicationRequestPublicId: string;
        sequenceNumber: number;
        revisionNumber: number;
        authoredMedication: string;
        status: string;
        dispensePublicId: string | null;
        dispenseOutcome: string | null;
        dispenseContentHash: string | null;
    }>;
};

export type EncounterClosureContent = {
    authored: {
        leavingCondition: string | null;
        disposition: string | null;
        followUpPlan: string | null;
        referralPlan: string | null;
        educationInstructions: string | null;
        outpatientSummary: string | null;
    };
    procedureDocumentation: {
        state: 'NONE_PERFORMED' | 'PROCEDURES_RECORDED' | null;
        procedures: Array<{
            publicId: string;
            sequenceNumber: number;
            status: 'COMPLETED';
            authoredText: string;
            performedStartAt: string;
            performedEndAt: string | null;
            performerText: string;
            bodySiteText: string | null;
            outcomeText: string | null;
            note: string | null;
            reasonConditionPublicId: string | null;
            basedOnServiceRequestPublicId: string | null;
            contentHash: string;
        }>;
    };
    sourceSnapshot: EncounterClosureSourceSnapshot;
    readinessAtAuthoring: {
        ready: boolean;
        checks: EncounterClosureReadinessCheck[];
    };
};

export type EncounterClosureVersion = {
    publicId: string;
    versionNumber: number;
    schemaVersion: string;
    content: EncounterClosureContent;
    contentHash: string;
    status: CodedStatus & {
        code: 'DRAFT' | 'SUBMITTED' | 'CHANGES_REQUESTED' | 'APPROVED';
    };
    author: string;
    authorRole: string;
    clinicalOccurrenceAt: string;
    recordedAt: string;
    submittedAt: string | null;
    reviewedAt: string | null;
    changeReason: string | null;
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

export type EncounterClosureWorkspaceProps = ClinicalWorkspaceBase & {
    assignment: {
        publicId: string;
        program: string;
        role: string;
        canAuthor: boolean;
        canReview: boolean;
    };
    task: {
        publicId: string;
        type:
            | 'ENCOUNTER_CLOSURE'
            | 'ENCOUNTER_CLOSURE_REVIEW'
            | 'RECORD_CORRECTION'
            | 'PROCEDURE_SOURCE_CORRECTION';
        status: CodedStatus;
    } | null;
    correctionRequest: {
        publicId: string;
        status: CodedStatus;
        reason: string;
        requestedSourceHash: string;
        finding: {
            code: string;
            severity: string;
            message: string;
            requestedAction: string;
        };
    } | null;
    codingCorrection: {
        publicId: string;
        status: CodedStatus;
        reason: string;
        requestedBy: string;
        requestedSourceHash: string;
        responseMedicalVersionPublicId: string | null;
        responseMedicalVersionNumber: number | null;
        responseMedicalContentHash: string | null;
    } | null;
    procedureCodingCorrection: {
        publicId: string;
        status: CodedStatus;
        reason: string;
        requestedBy: string;
        sourceProcedurePublicId: string;
        sourceStatement: string;
        sourceClosurePublicId: string;
        requestedSourceHash: string;
        requestedClosureHash: string;
    } | null;
    readiness: {
        ready: boolean;
        checks: EncounterClosureReadinessCheck[];
    };
    sourceSnapshot: EncounterClosureSourceSnapshot;
    document: {
        schemaVersion: 'encounter-closure.v2';
        latestVersion: EncounterClosureVersion | null;
        history: EncounterClosureVersion[];
        canAuthor: boolean;
        canReview: boolean;
        requiresChangeReason: boolean;
        authoredFieldsLocked: boolean;
    };
    formOptions: {
        requestKey: string;
        reviewRequestKey: string;
        defaultOccurrenceAt: string;
        intents: Array<'SAVE_DRAFT' | 'SUBMIT'>;
        procedureDocumentationStates: Array<
            CodedStatus & {
                code: 'NONE_PERFORMED' | 'PROCEDURES_RECORDED';
            }
        >;
        diagnosisOptions: Array<{ publicId: string; label: string }>;
        serviceRequestOptions: Array<{ publicId: string; label: string }>;
        reviewActions: Array<
            CodedStatus & {
                code: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES';
            }
        >;
    };
    urls: ClinicalWorkspaceBase['urls'] & {
        store: string;
        review: string | null;
    };
};

export type OutpatientSafetyDispositionOutcomeCode =
    'RESUME_ROUTINE_FLOW' | 'SIMULATED_TRANSFER';

export type OutpatientSafetyDispositionWorkspaceProps = {
    boundary: {
        classification: string;
        emergencyTriageClaim: false;
        clinicalRecommendation: false;
    };
    encounter: EncounterContext;
    patient: PatientContext;
    session: {
        code: string;
        scenarioTitle: string;
    };
    source: {
        versionPublicId: string;
        versionNumber: number;
        contentHash: string;
        author: string;
        authorRole: string;
        approvedAt: string | null;
        safetyDecision: string;
        safetyResponses: Array<{
            questionCode: string;
            response: string;
            note: string | null;
        }>;
        handoffSummary: string | null;
    };
    authorization: {
        assignmentPublicId: string;
        role: string;
        canRecord: boolean;
    };
    disposition: {
        publicId: string;
        outcome: CodedStatus & {
            code: OutpatientSafetyDispositionOutcomeCode;
        };
        rationale: string;
        actor: string;
        role: string;
        occurredAt: string;
    } | null;
    formOptions: {
        requestKey: string | null;
        selectedOutcome: null;
        outcomes: Array<{
            code: OutpatientSafetyDispositionOutcomeCode;
            label: string;
            consequence: string;
        }>;
    };
    urls: {
        store: string;
        encounter: string;
        workQueue: string;
    };
};

export type OutpatientEarlyDepartureSource = {
    documentType: 'NURSING_INTAKE' | 'MEDICAL_ASSESSMENT';
    entryPublicId: string;
    versionPublicId: string;
    versionNumber: number;
    status: string;
    contentHash: string;
};

export type OutpatientEarlyDepartureWorkspaceProps = {
    boundary: {
        classification: string;
        clinicalRecommendation: false;
        automaticFinalization: false;
    };
    encounter: EncounterContext & {
        periodEnd: string | null;
    };
    patient: PatientContext;
    session: {
        code: string;
        scenarioTitle: string;
    };
    source: {
        encounterStatus: CodedStatus;
        clinicalSources: OutpatientEarlyDepartureSource[];
        snapshotHash: string | null;
    };
    authorization: {
        assignmentPublicId: string;
        role: string;
        canRecord: boolean;
    };
    departure: {
        publicId: string;
        outcome: CodedStatus & {
            code: 'PATIENT_REQUESTED_DEPARTURE';
            interoperabilityCode: 'aadvice';
        };
        statedReason: string;
        communicationSummary: string;
        actor: string;
        role: string;
        occurredAt: string;
        sourceSnapshotHash: string;
    } | null;
    form: {
        requestKey: string | null;
        outcome: CodedStatus & {
            code: 'PATIENT_REQUESTED_DEPARTURE';
            interoperabilityCode: 'aadvice';
        };
    };
    urls: {
        store: string;
        encounter: string;
        timeline: string;
        workQueue: string;
    };
};
