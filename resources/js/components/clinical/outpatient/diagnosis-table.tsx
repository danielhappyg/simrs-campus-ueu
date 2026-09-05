import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type DiagnosisCode = { code: string; display: string };
type DiagnosisType = 'PRIMARY' | 'SECONDARY';
type TerminologyOption = DiagnosisCode & { system: 'ICD-10' | 'ICD-9-CM' };

type Row = DiagnosisCode & { type: DiagnosisType };

function rowsFromValues(
    primary: DiagnosisCode | null,
    secondary: DiagnosisCode[],
) {
    return [
        ...(primary ? [{ ...primary, type: 'PRIMARY' as const }] : []),
        ...secondary.map((item) => ({ ...item, type: 'SECONDARY' as const })),
    ];
}

export function DiagnosisTable({
    primary,
    secondary,
    lookupUrl,
    disabled,
    onChange,
    onPendingChange,
}: {
    primary: DiagnosisCode | null;
    secondary: DiagnosisCode[];
    lookupUrl?: string;
    disabled: boolean;
    onChange: (values: {
        primary: DiagnosisCode | null;
        secondary: DiagnosisCode[];
    }) => void;
    onPendingChange?: (pending: boolean) => void;
}) {
    const rows = useMemo(
        () => rowsFromValues(primary, secondary),
        [primary, secondary],
    );
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState<TerminologyOption[]>([]);
    const [message, setMessage] = useState(
        'Type at least 2 characters to search the official code catalogue.',
    );
    const [selected, setSelected] = useState<DiagnosisCode | null>(null);
    const [selectedType, setSelectedType] = useState<DiagnosisType>(() =>
        primary ? 'SECONDARY' : 'PRIMARY',
    );
    const [editingCode, setEditingCode] = useState<string | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);
    const typeRef = useRef<HTMLSelectElement>(null);
    const pending = Boolean(selected || editingCode || query.trim());

    useEffect(() => onPendingChange?.(pending), [onPendingChange, pending]);

    useEffect(() => {
        if (query.trim().length < 2) {
            return;
        }

        if (!lookupUrl) {
            queueMicrotask(() => {
                setOptions([]);
                setMessage(
                    'The code catalogue is unavailable for this encounter.',
                );
            });

            return;
        }

        const controller = new AbortController();
        const timeout = window.setTimeout(() => {
            setMessage('Searching the official catalogue…');
            fetch(
                `${lookupUrl}?system=ICD-10&q=${encodeURIComponent(query.trim())}`,
                { signal: controller.signal },
            )
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error('lookup_failed');
                    }

                    return response.json() as Promise<{
                        options?: TerminologyOption[];
                        source?: { authority?: string; dataset?: string };
                    }>;
                })
                .then((result) => {
                    if (controller.signal.aborted) {
                        return;
                    }

                    setOptions(result.options ?? []);
                    setMessage(
                        result.options?.length
                            ? `Results from ${result.source?.authority ?? 'the official catalogue'}${result.source?.dataset ? ` · ${result.source.dataset}` : ''}.`
                            : 'No codes found. Try a different keyword or code.',
                    );
                })
                .catch((error: unknown) => {
                    if (
                        !controller.signal.aborted &&
                        (error as { name?: string }).name !== 'AbortError'
                    ) {
                        setOptions([]);
                        setMessage(
                            'The code catalogue could not be loaded. Try again.',
                        );
                    }
                });
        }, 300);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [lookupUrl, query]);

    const resetComposer = (hasPrimary = Boolean(primary)) => {
        setQuery('');
        setOptions([]);
        setSelected(null);
        setSelectedType(hasPrimary ? 'SECONDARY' : 'PRIMARY');
        setEditingCode(null);
        setMessage(
            'Type at least 2 characters to search the official code catalogue.',
        );
        requestAnimationFrame(() => searchRef.current?.focus());
    };
    const codeValue = (row: Row): DiagnosisCode => ({
        code: row.code,
        display: row.display,
    });
    const emit = (nextRows: Row[]) =>
        onChange({
            primary: nextRows.find((row) => row.type === 'PRIMARY')
                ? codeValue(nextRows.find((row) => row.type === 'PRIMARY')!)
                : null,
            secondary: nextRows
                .filter((row) => row.type === 'SECONDARY')
                .map(codeValue),
        });
    const addOrUpdate = () => {
        if (!selected) {
            return;
        }

        if (
            rows.some(
                (row) => row.code === selected.code && row.code !== editingCode,
            )
        ) {
            setMessage('This diagnosis is already in the table.');
            setSelected(null);

            return;
        }

        let next = editingCode
            ? rows.filter((row) => row.code !== editingCode)
            : [...rows];

        if (selectedType === 'PRIMARY') {
            next = next.map((row) => ({ ...row, type: 'SECONDARY' as const }));
        }

        next.push({ ...selected, type: selectedType });

        if (next.filter((row) => row.type === 'SECONDARY').length > 20) {
            setMessage('A maximum of 20 secondary diagnoses can be recorded.');

            return;
        }

        emit(next);
        resetComposer(next.some((row) => row.type === 'PRIMARY'));
    };
    const edit = (row: Row) => {
        setEditingCode(row.code);
        setSelected({ code: row.code, display: row.display });
        setSelectedType(row.type);
        setQuery('');
        setOptions([]);
        setMessage(
            `Editing ${row.code}. Search to replace it, or update its type.`,
        );
        requestAnimationFrame(() => searchRef.current?.focus());
    };
    const makePrimary = (code: string) =>
        emit(
            rows.map((row) => ({
                ...row,
                type:
                    row.code === code
                        ? ('PRIMARY' as const)
                        : ('SECONDARY' as const),
            })),
        );

    return (
        <section
            className="grid gap-3 border-t border-[#eef6fc] pt-4"
            aria-labelledby="icd10-diagnoses-heading"
        >
            <div>
                <h3
                    id="icd10-diagnoses-heading"
                    className="text-sm font-semibold text-[#123b63]"
                >
                    Diagnoses (ICD-10)
                </h3>
                <p className="mt-0.5 text-xs text-[#64748b]">
                    Add a primary diagnosis and any secondary diagnoses from the
                    official catalogue.
                </p>
            </div>
            <div className="grid gap-2 rounded-md border border-[#eef6fc] bg-[#fff] p-3 md:grid-cols-[minmax(0,1fr)_10rem_auto] md:items-end">
                <div className="grid gap-1.5">
                    <Label htmlFor="MEDICAL_ASSESSMENT-icd10-search">
                        Search ICD-10 code or diagnosis
                    </Label>
                    <Input
                        id="MEDICAL_ASSESSMENT-icd10-search"
                        ref={searchRef}
                        value={query}
                        disabled={disabled}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setSelected(null);
                            setOptions([]);
                            setMessage('Waiting for a search term…');
                        }}
                        placeholder="Search an ICD-10 code or diagnosis"
                        aria-describedby="MEDICAL_ASSESSMENT-icd10-help"
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="MEDICAL_ASSESSMENT-icd10-type">Type</Label>
                    <select
                        id="MEDICAL_ASSESSMENT-icd10-type"
                        ref={typeRef}
                        value={selectedType}
                        disabled={disabled}
                        onChange={(event) =>
                            setSelectedType(event.target.value as DiagnosisType)
                        }
                        className="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:outline-2 focus-visible:outline-[#1b75bc]"
                    >
                        <option value="PRIMARY">Primary</option>
                        <option value="SECONDARY">Secondary</option>
                    </select>
                </div>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        disabled={disabled || !selected}
                        onClick={addOrUpdate}
                    >
                        {editingCode ? 'Update diagnosis' : 'Add diagnosis'}
                    </Button>
                    {pending ? (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={disabled}
                            onClick={() => resetComposer()}
                        >
                            Cancel
                        </Button>
                    ) : null}
                </div>
                <p
                    id="MEDICAL_ASSESSMENT-icd10-help"
                    role="status"
                    aria-live="polite"
                    className="text-xs text-[#64748b] md:col-span-3"
                >
                    {selected
                        ? `Selected: ${selected.code} — ${selected.display}. Choose a type, then ${editingCode ? 'update' : 'add'} it.`
                        : message}
                </p>
                {options.length ? (
                    <ul
                        className="max-h-44 overflow-y-auto rounded-md border border-[#eef6fc] bg-[#fff] md:col-span-3"
                        aria-label="ICD-10 search results"
                    >
                        {options.map((option) => (
                            <li key={option.code}>
                                <button
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => {
                                        setSelected({
                                            code: option.code,
                                            display: option.display,
                                        });
                                        setQuery('');
                                        setOptions([]);
                                        requestAnimationFrame(() =>
                                            typeRef.current?.focus(),
                                        );
                                    }}
                                    className="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-[#eef6fc] focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-[#1b75bc] disabled:opacity-50"
                                >
                                    <span className="shrink-0 font-mono text-xs font-semibold text-[#123b63]">
                                        {option.code}
                                    </span>
                                    <span>{option.display}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : null}
            </div>
            <div className="overflow-x-auto rounded-md border border-[#eef6fc]">
                <table className="w-full min-w-[42rem] text-left text-sm">
                    <thead className="bg-[#eef6fc] text-[#123b63]">
                        <tr>
                            <th className="px-3 py-2 font-semibold">Code</th>
                            <th className="px-3 py-2 font-semibold">
                                Diagnosis description
                            </th>
                            <th className="px-3 py-2 font-semibold">Type</th>
                            <th className="px-3 py-2 font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length ? (
                            rows.map((row) => (
                                <tr
                                    key={row.code}
                                    className="border-t border-[#eef6fc]"
                                >
                                    <td className="px-3 py-2 font-mono text-xs font-semibold text-[#123b63]">
                                        {row.code}
                                    </td>
                                    <td className="px-3 py-2">{row.display}</td>
                                    <td className="px-3 py-2">
                                        {row.type === 'PRIMARY' ? (
                                            <span className="rounded bg-[#eef6fc] px-2 py-1 text-xs font-medium text-[#123b63]">
                                                Primary
                                            </span>
                                        ) : (
                                            <span className="text-xs text-[#64748b]">
                                                Secondary
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                disabled={disabled}
                                                onClick={() => edit(row)}
                                                aria-label={`Edit ${row.code}`}
                                                className="text-[#1b75bc] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-[#1b75bc]"
                                            >
                                                Edit
                                            </button>
                                            {row.type === 'SECONDARY' ? (
                                                <button
                                                    type="button"
                                                    disabled={disabled}
                                                    onClick={() =>
                                                        makePrimary(row.code)
                                                    }
                                                    className="text-[#1b75bc] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-[#1b75bc]"
                                                >
                                                    Make primary
                                                </button>
                                            ) : null}
                                            <button
                                                type="button"
                                                disabled={disabled}
                                                onClick={() =>
                                                    emit(
                                                        rows.filter(
                                                            (item) =>
                                                                item.code !==
                                                                row.code,
                                                        ),
                                                    )
                                                }
                                                className="text-[#64748b] underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-[#1b75bc]"
                                                aria-label={`Remove ${row.code}`}
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))
                        ) : (
                            <tr>
                                <td
                                    colSpan={4}
                                    className="px-3 py-4 text-center text-sm text-[#64748b]"
                                >
                                    No diagnoses added.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            {!primary && secondary.length ? (
                <p className="text-xs text-[#64748b]" role="status">
                    Choose a primary diagnosis before finalizing this document.
                </p>
            ) : null}
        </section>
    );
}
