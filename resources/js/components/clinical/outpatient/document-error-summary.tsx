import { useEffect, useRef } from 'react';

export function DocumentErrorSummary({
    errors,
}: {
    errors: Record<string, string | undefined>;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const messages = Object.values(errors).filter(
        (message): message is string => Boolean(message),
    );

    useEffect(() => {
        if (messages.length > 0) {
            ref.current?.focus();
        }
    }, [messages.length]);

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            role="alert"
            tabIndex={-1}
            className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
        >
            <p className="font-semibold">The document could not be saved.</p>
            <ul className="mt-1 list-disc space-y-1 pl-5">
                {messages.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
        </div>
    );
}
