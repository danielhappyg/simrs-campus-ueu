export function dateTimeLocalDisplay(value: string): string {
    return value.slice(0, 16);
}

export function dateTimeFormValue(
    value: string,
    preserveExactInstant: boolean,
): string {
    return preserveExactInstant ? value : dateTimeLocalDisplay(value);
}
