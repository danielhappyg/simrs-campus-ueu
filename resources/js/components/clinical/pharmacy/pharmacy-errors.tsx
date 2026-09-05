import { useEffect, useRef } from 'react';

export function PharmacyErrorSummary({
    errors,
    title = 'Data could not be saved.',
}: {
    errors: Record<string, string>;
    title?: string;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors).filter(Boolean);
    const fingerprint = messages.join('|');

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [fingerprint, messages.length]);

    return messages.length ? (
        <div
            ref={ref}
            tabIndex={-1}
            role="alert"
            className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            <p className="font-semibold">{title}</p>
            <ul className="mt-1 list-disc pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    ) : null;
}
