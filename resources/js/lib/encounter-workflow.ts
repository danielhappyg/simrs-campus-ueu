export type WorkflowState = {
    code: string;
    label: string;
    current: boolean;
};

export type WorkflowTransitionCode = {
    toStatusCode: string;
};

export type PresentedWorkflowState = WorkflowState & {
    completed: boolean;
};

export function presentRecordedWorkflow(
    states: WorkflowState[],
    transitions: WorkflowTransitionCode[],
): PresentedWorkflowState[] {
    const visitedStates = new Set(
        transitions.map((transition) => transition.toStatusCode),
    );

    return states.map((state) => ({
        ...state,
        completed: visitedStates.has(state.code) && !state.current,
    }));
}
