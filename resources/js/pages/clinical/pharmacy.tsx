import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardCheck,
    Fingerprint,
    MessageSquareWarning,
    PackageCheck,
    Pill,
    RefreshCw,
    Send,
    ShieldCheck,
} from 'lucide-react';
import type { FormEvent, TextareaHTMLAttributes } from 'react';
import { ClinicalVersionStamp } from '@/components/clinical-version-stamp';
import InputError from '@/components/input-error';
import { PatientContextBanner } from '@/components/patient-context-banner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    MedicationDispenseOutcomeCode,
    PharmacyMedicationRequestRecord,
    PharmacyResponseActionCode,
    PharmacyReviewItemOutcomeCode,
    PharmacyReviewOutcomeCode,
    PharmacyWorkspaceProps,
} from '@/types';

type DomainKey = 'administrative' | 'pharmaceutical' | 'clinical';
type ReviewRowForm = {
    criterion_code: string;
    outcome: PharmacyReviewItemOutcomeCode | '';
    comment: string;
};
type ReviewFormData = {
    request_key: string;
    overall_outcome: PharmacyReviewOutcomeCode;
    domain_results: Record<DomainKey, ReviewRowForm[]>;
    intervention: {
        request_key: string;
        issue_category: string;
        urgency: 'ROUTINE' | 'PRIORITY_SIMULATION';
        question: string;
        recommendation: string;
    } | null;
};
type DispenseFormData = {
    request_key: string;
    action: 'PREPARE';
    outcome: MedicationDispenseOutcomeCode;
    quantity: string;
    medication_stock_id: number | null;
    outcome_reason: string;
    preparation_notes: string;
    change_reason: string;
    handoff_recipient: string;
    counseling_topics_text: string;
    counseling_acknowledged: boolean;
};
type FinalCheckFormData = {
    request_key: string;
    action: 'FINAL_CHECK';
    preparation_public_id: string;
    review_action: 'APPROVE_SIMULATION' | 'REQUEST_CHANGES';
    comment: string;
    final_check_confirmed: boolean;
    final_check_notes: string;
};
type InterventionResponseFormData = {
    request_key: string;
    response_action: PharmacyResponseActionCode;
    message_text: string;
    replacement: {
        authored_medication: string;
        form: string;
        strength: string;
        dose_value: string;
        dose_unit: string;
        route: string;
        frequency: string;
        duration: string;
        quantity_value: string;
        quantity_unit: string;
        directions: string;
        indication_text: string;
    };
};

function Textarea({
    className,
    ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
    return (
        <textarea
            className={cn(
                'min-h-24 w-full rounded-md border border-input bg-white px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

function formatDateTime(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

const domainLabels: Record<DomainKey, string> = {
    administrative: 'Administratif',
    pharmaceutical: 'Farmasetik',
    clinical: 'Klinis',
};

function PharmacyReviewForm({
    medicationRequest,
    definition,
}: {
    medicationRequest: PharmacyMedicationRequestRecord;
    definition: PharmacyWorkspaceProps['reviewDefinition'];
}) {
    const initialRows = (domain: DomainKey): ReviewRowForm[] =>
        definition.criteria[domain].map((criterion) => ({
            criterion_code: criterion.code,
            outcome: '',
            comment: '',
        }));
    const form = useForm<ReviewFormData>({
        request_key: medicationRequest.reviewAction.requestKey,
        overall_outcome: 'ACCEPT',
        domain_results: {
            administrative: initialRows('administrative'),
            pharmaceutical: initialRows('pharmaceutical'),
            clinical: initialRows('clinical'),
        },
        intervention: null,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function updateRow(
        domain: DomainKey,
        index: number,
        field: 'outcome' | 'comment',
        value: string,
    ) {
        const rows = form.data.domain_results[domain].map((row, rowIndex) =>
            rowIndex === index ? { ...row, [field]: value } : row,
        );
        form.setData('domain_results', {
            ...form.data.domain_results,
            [domain]: rows,
        });
    }

    function updateOverall(value: PharmacyReviewOutcomeCode) {
        form.setData('overall_outcome', value);

        if (value === 'ACCEPT') {
            form.setData('intervention', null);

            return;
        }

        form.setData('intervention', {
            request_key: medicationRequest.reviewAction.interventionRequestKey,
            issue_category: '',
            urgency: 'ROUTINE',
            question: '',
            recommendation: '',
        });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(medicationRequest.reviewAction.url, {
            preserveScroll: true,
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-5 rounded-md border border-sky-200 bg-sky-50/70 p-4"
        >
            <div className="flex items-start gap-3">
                <ClipboardCheck
                    className="mt-0.5 size-5 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <div>
                    <h3 className="font-semibold">Telaah tiga domain</h3>
                    <p className="mt-1 text-xs leading-5 text-[#174c68]">
                        Setiap keputusan di bawah ditulis oleh penelaah. Sistem
                        hanya memeriksa kelengkapan dan tidak menyatakan resep
                        aman secara otomatis.
                    </p>
                </div>
            </div>
            <InputError message={errors.workflow} className="mt-3" />

            <fieldset
                disabled={form.processing}
                className="mt-4 space-y-5 border-0 p-0"
            >
                {(Object.keys(domainLabels) as DomainKey[]).map((domain) => (
                    <section
                        key={domain}
                        className="overflow-hidden rounded-md border border-border bg-white"
                    >
                        <h4 className="border-b border-border bg-muted/40 px-4 py-3 font-semibold">
                            {domainLabels[domain]}
                        </h4>
                        <div className="divide-y divide-border">
                            {definition.criteria[domain].map(
                                (criterion, index) => {
                                    const row =
                                        form.data.domain_results[domain][index];

                                    return (
                                        <div
                                            key={criterion.code}
                                            className="grid gap-3 p-4 lg:grid-cols-[minmax(0,1fr)_15rem_minmax(12rem,1fr)] lg:items-start"
                                        >
                                            <div>
                                                <p className="text-sm font-medium">
                                                    {criterion.label}
                                                </p>
                                                <code className="mt-1 block font-mono text-[0.65rem] text-muted-foreground">
                                                    {criterion.code}
                                                </code>
                                            </div>
                                            <div>
                                                <Label
                                                    htmlFor={`${medicationRequest.publicId}-${domain}-${criterion.code}-outcome`}
                                                    className="sr-only"
                                                >
                                                    Hasil {criterion.label}
                                                </Label>
                                                <select
                                                    id={`${medicationRequest.publicId}-${domain}-${criterion.code}-outcome`}
                                                    value={row?.outcome ?? ''}
                                                    onChange={(event) =>
                                                        updateRow(
                                                            domain,
                                                            index,
                                                            'outcome',
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                                >
                                                    <option value="">
                                                        Pilih penilaian…
                                                    </option>
                                                    {definition.itemOutcomes.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.code
                                                                }
                                                                value={
                                                                    option.code
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <div>
                                                <Label
                                                    htmlFor={`${medicationRequest.publicId}-${domain}-${criterion.code}-comment`}
                                                    className="sr-only"
                                                >
                                                    Catatan {criterion.label}
                                                </Label>
                                                <Input
                                                    id={`${medicationRequest.publicId}-${domain}-${criterion.code}-comment`}
                                                    value={row?.comment ?? ''}
                                                    onChange={(event) =>
                                                        updateRow(
                                                            domain,
                                                            index,
                                                            'comment',
                                                            event.target.value,
                                                        )
                                                    }
                                                    placeholder="Komentar wajib untuk temuan / tidak berlaku"
                                                />
                                            </div>
                                        </div>
                                    );
                                },
                            )}
                        </div>
                    </section>
                ))}

                <div className="grid gap-3 md:grid-cols-[18rem_minmax(0,1fr)]">
                    <div>
                        <Label
                            htmlFor={`overall-review-${medicationRequest.publicId}`}
                        >
                            Kesimpulan penelaah
                        </Label>
                        <select
                            id={`overall-review-${medicationRequest.publicId}`}
                            value={form.data.overall_outcome}
                            onChange={(event) =>
                                updateOverall(
                                    event.target
                                        .value as PharmacyReviewOutcomeCode,
                                )
                            }
                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {definition.overallOutcomes.map((option) => (
                                <option key={option.code} value={option.code}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {form.data.intervention && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                            <h4 className="flex items-center gap-2 font-semibold text-amber-950">
                                <MessageSquareWarning
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Intervensi wajib
                            </h4>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <Label
                                        htmlFor={`intervention-category-${medicationRequest.publicId}`}
                                    >
                                        Kategori isu
                                    </Label>
                                    <Input
                                        id={`intervention-category-${medicationRequest.publicId}`}
                                        value={
                                            form.data.intervention
                                                .issue_category
                                        }
                                        onChange={(event) =>
                                            form.setData('intervention', {
                                                ...form.data.intervention!,
                                                issue_category:
                                                    event.target.value,
                                            })
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div>
                                    <Label
                                        htmlFor={`intervention-urgency-${medicationRequest.publicId}`}
                                    >
                                        Prioritas skenario
                                    </Label>
                                    <select
                                        id={`intervention-urgency-${medicationRequest.publicId}`}
                                        value={form.data.intervention.urgency}
                                        onChange={(event) =>
                                            form.setData('intervention', {
                                                ...form.data.intervention!,
                                                urgency: event.target.value as
                                                    | 'ROUTINE'
                                                    | 'PRIORITY_SIMULATION',
                                            })
                                        }
                                        className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                                    >
                                        <option value="ROUTINE">Rutin</option>
                                        <option value="PRIORITY_SIMULATION">
                                            Prioritas simulasi
                                        </option>
                                    </select>
                                </div>
                                <div className="sm:col-span-2">
                                    <Label
                                        htmlFor={`intervention-question-${medicationRequest.publicId}`}
                                    >
                                        Pertanyaan kepada prescriber
                                    </Label>
                                    <Textarea
                                        id={`intervention-question-${medicationRequest.publicId}`}
                                        value={form.data.intervention.question}
                                        onChange={(event) =>
                                            form.setData('intervention', {
                                                ...form.data.intervention!,
                                                question: event.target.value,
                                            })
                                        }
                                        className="mt-1"
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Label
                                        htmlFor={`intervention-recommendation-${medicationRequest.publicId}`}
                                    >
                                        Rekomendasi penelaah
                                    </Label>
                                    <Textarea
                                        id={`intervention-recommendation-${medicationRequest.publicId}`}
                                        value={
                                            form.data.intervention
                                                .recommendation
                                        }
                                        onChange={(event) =>
                                            form.setData('intervention', {
                                                ...form.data.intervention!,
                                                recommendation:
                                                    event.target.value,
                                            })
                                        }
                                        className="mt-1 min-h-20"
                                    />
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                <div className="flex justify-end">
                    <Button type="submit">
                        <Send className="size-4" aria-hidden="true" />
                        Simpan telaah manusia
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

function InterventionResponseForm({
    intervention,
    medicationRequest,
    options,
}: {
    intervention: PharmacyMedicationRequestRecord['interventions'][number];
    medicationRequest: PharmacyMedicationRequestRecord;
    options: PharmacyWorkspaceProps['formOptions']['responseActions'];
}) {
    const form = useForm<InterventionResponseFormData>({
        request_key: intervention.response.requestKey,
        response_action: 'EXPLANATION' as PharmacyResponseActionCode,
        message_text: '',
        replacement: {
            authored_medication: medicationRequest.authoredMedication,
            form: medicationRequest.form ?? '',
            strength: medicationRequest.strength ?? '',
            dose_value: medicationRequest.doseValue,
            dose_unit: medicationRequest.doseUnit,
            route: medicationRequest.route,
            frequency: medicationRequest.frequency,
            duration: medicationRequest.duration,
            quantity_value: medicationRequest.quantityValue,
            quantity_unit: medicationRequest.quantityUnit,
            directions: medicationRequest.directions,
            indication_text: medicationRequest.indicationText ?? '',
        },
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            replacement:
                data.response_action === 'REPLACE' ? data.replacement : null,
        }));
        form.post(intervention.response.url, { preserveScroll: true });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-4 rounded-md border border-violet-200 bg-violet-50 p-4"
        >
            <h4 className="font-semibold text-violet-950">
                Tanggapan prescriber
            </h4>
            <InputError message={errors.workflow} className="mt-2" />
            <fieldset
                disabled={form.processing}
                className="mt-3 space-y-3 border-0 p-0"
            >
                <div>
                    <Label htmlFor={`response-action-${intervention.publicId}`}>
                        Tindakan
                    </Label>
                    <select
                        id={`response-action-${intervention.publicId}`}
                        value={form.data.response_action}
                        onChange={(event) =>
                            form.setData(
                                'response_action',
                                event.target
                                    .value as PharmacyResponseActionCode,
                            )
                        }
                        className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                    >
                        {options.map((option) => (
                            <option key={option.code} value={option.code}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <Label htmlFor={`response-text-${intervention.publicId}`}>
                        Penjelasan dan alasan
                    </Label>
                    <Textarea
                        id={`response-text-${intervention.publicId}`}
                        value={form.data.message_text}
                        onChange={(event) =>
                            form.setData('message_text', event.target.value)
                        }
                        className="mt-1"
                    />
                </div>
                {form.data.response_action === 'REPLACE' && (
                    <div className="grid gap-3 rounded border border-violet-200 bg-white p-3 sm:grid-cols-2">
                        {(
                            [
                                ['authored_medication', 'Nama obat'],
                                ['form', 'Bentuk'],
                                ['strength', 'Kekuatan'],
                                ['dose_value', 'Dosis'],
                                ['dose_unit', 'Satuan dosis'],
                                ['route', 'Rute'],
                                ['frequency', 'Frekuensi'],
                                ['duration', 'Durasi'],
                                ['quantity_value', 'Jumlah'],
                                ['quantity_unit', 'Satuan jumlah'],
                                ['directions', 'Petunjuk'],
                                ['indication_text', 'Indikasi'],
                            ] as const
                        ).map(([field, label]) => (
                            <div
                                key={field}
                                className={
                                    field === 'directions' ||
                                    field === 'indication_text'
                                        ? 'sm:col-span-2'
                                        : undefined
                                }
                            >
                                <Label
                                    htmlFor={`replacement-${field}-${intervention.publicId}`}
                                >
                                    {label}
                                </Label>
                                <Input
                                    id={`replacement-${field}-${intervention.publicId}`}
                                    value={form.data.replacement[field]}
                                    onChange={(event) =>
                                        form.setData('replacement', {
                                            ...form.data.replacement,
                                            [field]: event.target.value,
                                        })
                                    }
                                    className="mt-1"
                                />
                            </div>
                        ))}
                    </div>
                )}
                <div className="flex justify-end">
                    <Button type="submit" size="sm">
                        <Send className="size-4" aria-hidden="true" />
                        Kirim tanggapan
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

export function DispenseForm({
    medicationRequest,
    stocks,
    outcomes,
}: {
    medicationRequest: PharmacyMedicationRequestRecord;
    stocks: PharmacyWorkspaceProps['stocks'];
    outcomes: PharmacyWorkspaceProps['formOptions']['dispenseOutcomes'];
}) {
    const matchingStocks = stocks.filter(
        (stock) =>
            stock.authoredMedication === medicationRequest.authoredMedication &&
            stock.unit === medicationRequest.quantityUnit,
    );
    const hasMatchingStock = matchingStocks.length > 0;
    const form = useForm<DispenseFormData>({
        request_key: medicationRequest.dispenseAction.requestKey,
        action: 'PREPARE',
        outcome: (hasMatchingStock
            ? 'COMPLETE'
            : 'NOT_DISPENSED') as MedicationDispenseOutcomeCode,
        quantity: hasMatchingStock ? medicationRequest.quantityValue : '0',
        medication_stock_id: matchingStocks[0]?.id ?? null,
        outcome_reason: '',
        preparation_notes: '',
        change_reason: '',
        handoff_recipient: 'Pasien sintetis',
        counseling_topics_text: '',
        counseling_acknowledged: false,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            medication_stock_id:
                data.outcome === 'NOT_DISPENSED'
                    ? null
                    : data.medication_stock_id,
            counseling_topics: data.counseling_topics_text
                .split(',')
                .map((topic) => topic.trim())
                .filter(Boolean),
        }));
        form.post(medicationRequest.dispenseAction.url, {
            preserveScroll: true,
        });
    }

    return (
        <form
            onSubmit={submit}
            className="mt-5 rounded-md border border-emerald-200 bg-emerald-50 p-4"
        >
            <h3 className="flex items-center gap-2 font-semibold text-emerald-950">
                <PackageCheck className="size-5" aria-hidden="true" />
                Penyiapan obat
            </h3>
            <p className="mt-1 text-xs leading-5 text-emerald-900">
                Simpan versi penyiapan tanpa mengubah stok. Supervisor farmasi
                yang terhubung harus memeriksa versi dan hash ini sebelum stok,
                penyerahan, serta closure dapat dilanjutkan.
            </p>
            {!hasMatchingStock && (
                <div
                    role="alert"
                    className="mt-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950"
                >
                    Tidak ada lot stok sintetis dengan nama obat dan unit yang
                    sama untuk “{medicationRequest.authoredMedication}” /{' '}
                    {medicationRequest.quantityUnit}.
                    {stocks.length > 0 ? (
                        <>
                            {' '}
                            Lot sesi saat ini:{' '}
                            {stocks
                                .map(
                                    (stock) =>
                                        `${stock.authoredMedication} (${stock.unit})`,
                                )
                                .join('; ')}
                            .
                        </>
                    ) : (
                        <> Tidak ada lot stok sintetis aktif pada sesi ini.</>
                    )}{' '}
                    Catat outcome “Tidak diserahkan” beserta alasannya; sistem
                    tidak melakukan substitusi otomatis.
                </div>
            )}
            <InputError message={errors.workflow} className="mt-2" />
            <fieldset
                disabled={form.processing}
                className="mt-4 grid gap-3 border-0 p-0 md:grid-cols-2"
            >
                <div>
                    <Label
                        htmlFor={`dispense-outcome-${medicationRequest.publicId}`}
                    >
                        Outcome
                    </Label>
                    <select
                        id={`dispense-outcome-${medicationRequest.publicId}`}
                        value={form.data.outcome}
                        onChange={(event) => {
                            const outcome = event.target
                                .value as MedicationDispenseOutcomeCode;

                            form.setData('outcome', outcome);

                            if (outcome === 'NOT_DISPENSED') {
                                form.setData('quantity', '0');
                            } else if (form.data.quantity === '0') {
                                form.setData(
                                    'quantity',
                                    medicationRequest.quantityValue,
                                );
                            }
                        }}
                        className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                    >
                        {outcomes.map((outcome) => (
                            <option key={outcome.code} value={outcome.code}>
                                {outcome.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div>
                    <Label
                        htmlFor={`dispense-quantity-${medicationRequest.publicId}`}
                    >
                        Jumlah ({medicationRequest.quantityUnit})
                    </Label>
                    <Input
                        id={`dispense-quantity-${medicationRequest.publicId}`}
                        type="number"
                        min="0"
                        step="0.001"
                        value={form.data.quantity}
                        onChange={(event) =>
                            form.setData('quantity', event.target.value)
                        }
                        className="mt-1 bg-white"
                    />
                </div>
                {form.data.outcome !== 'NOT_DISPENSED' && (
                    <div className="md:col-span-2">
                        <Label
                            htmlFor={`dispense-stock-${medicationRequest.publicId}`}
                        >
                            Lot stok sintetis · FEFO
                        </Label>
                        <select
                            id={`dispense-stock-${medicationRequest.publicId}`}
                            value={form.data.medication_stock_id ?? ''}
                            onChange={(event) =>
                                form.setData(
                                    'medication_stock_id',
                                    event.target.value
                                        ? Number(event.target.value)
                                        : null,
                                )
                            }
                            className="mt-1 h-9 w-full rounded-md border border-input bg-white px-3 text-sm"
                        >
                            <option value="">Pilih lot…</option>
                            {matchingStocks.map((stock) => (
                                <option key={stock.id} value={stock.id}>
                                    {stock.lotNumber} · kedaluwarsa{' '}
                                    {stock.expiresOn} · tersedia{' '}
                                    {stock.quantityOnHand} {stock.unit}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                <div className="md:col-span-2">
                    <Label
                        htmlFor={`dispense-reason-${medicationRequest.publicId}`}
                    >
                        Alasan partial / tidak diserahkan
                    </Label>
                    <Textarea
                        id={`dispense-reason-${medicationRequest.publicId}`}
                        value={form.data.outcome_reason}
                        onChange={(event) =>
                            form.setData('outcome_reason', event.target.value)
                        }
                        className="mt-1 min-h-20 bg-white"
                    />
                </div>
                <div className="md:col-span-2">
                    <Label
                        htmlFor={`preparation-notes-${medicationRequest.publicId}`}
                    >
                        Catatan penyiapan
                    </Label>
                    <Textarea
                        id={`preparation-notes-${medicationRequest.publicId}`}
                        value={form.data.preparation_notes}
                        onChange={(event) =>
                            form.setData(
                                'preparation_notes',
                                event.target.value,
                            )
                        }
                        className="mt-1 bg-white"
                    />
                </div>
                {medicationRequest.dispensePreparations.length > 0 && (
                    <div className="md:col-span-2">
                        <Label
                            htmlFor={`preparation-change-reason-${medicationRequest.publicId}`}
                        >
                            Ringkasan perubahan dari versi sebelumnya
                        </Label>
                        <Textarea
                            id={`preparation-change-reason-${medicationRequest.publicId}`}
                            value={form.data.change_reason}
                            onChange={(event) =>
                                form.setData(
                                    'change_reason',
                                    event.target.value,
                                )
                            }
                            className="mt-1 min-h-20 bg-white"
                        />
                    </div>
                )}
                <div>
                    <Label
                        htmlFor={`handoff-recipient-${medicationRequest.publicId}`}
                    >
                        Penerima penyerahan
                    </Label>
                    <Input
                        id={`handoff-recipient-${medicationRequest.publicId}`}
                        value={form.data.handoff_recipient}
                        onChange={(event) =>
                            form.setData(
                                'handoff_recipient',
                                event.target.value,
                            )
                        }
                        className="mt-1 bg-white"
                    />
                </div>
                <div>
                    <Label
                        htmlFor={`counseling-topics-${medicationRequest.publicId}`}
                    >
                        Topik konseling (pisahkan koma)
                    </Label>
                    <Input
                        id={`counseling-topics-${medicationRequest.publicId}`}
                        value={form.data.counseling_topics_text}
                        onChange={(event) =>
                            form.setData(
                                'counseling_topics_text',
                                event.target.value,
                            )
                        }
                        className="mt-1 bg-white"
                    />
                </div>
                <label className="flex items-start gap-2 rounded border border-emerald-200 bg-white p-3 text-sm md:col-span-2">
                    <input
                        type="checkbox"
                        checked={form.data.counseling_acknowledged}
                        onChange={(event) =>
                            form.setData(
                                'counseling_acknowledged',
                                event.target.checked,
                            )
                        }
                        className="mt-0.5"
                    />
                    <span>Konseling skenario diterima oleh penerima.</span>
                </label>
                <div className="flex justify-end md:col-span-2">
                    <Button type="submit">
                        <PackageCheck className="size-4" aria-hidden="true" />
                        Ajukan penyiapan ke supervisor
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

export function FinalCheckForm({
    medicationRequest,
}: {
    medicationRequest: PharmacyMedicationRequestRecord;
}) {
    const preparationPublicId =
        medicationRequest.finalCheckAction.preparationPublicId ?? '';
    const form = useForm<FinalCheckFormData>({
        request_key: medicationRequest.finalCheckAction.requestKey,
        action: 'FINAL_CHECK',
        preparation_public_id: preparationPublicId,
        review_action: 'APPROVE_SIMULATION',
        comment: '',
        final_check_confirmed: false,
        final_check_notes: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(action: FinalCheckFormData['review_action']) {
        form.transform((data) => ({ ...data, review_action: action }));
        form.post(medicationRequest.finalCheckAction.url, {
            preserveScroll: true,
        });
    }

    return (
        <form
            onSubmit={(event) => event.preventDefault()}
            className="mt-5 rounded-md border border-sky-200 bg-sky-50 p-4"
        >
            <h3 className="flex items-center gap-2 font-semibold text-sky-950">
                <ShieldCheck className="size-5" aria-hidden="true" />
                Pemeriksaan akhir supervisor
            </h3>
            <p className="mt-1 text-xs leading-5 text-sky-900">
                Keputusan ini ditautkan ke versi dan hash penyiapan terakhir.
                Persetujuan akan menyelesaikan outcome dan mutasi stok sintetis
                secara atomik.
            </p>
            <InputError message={errors.workflow} className="mt-2" />
            <fieldset
                disabled={form.processing}
                className="mt-4 grid gap-3 border-0 p-0"
            >
                <div>
                    <Label
                        htmlFor={`final-check-comment-${medicationRequest.publicId}`}
                    >
                        Komentar keputusan
                    </Label>
                    <Textarea
                        id={`final-check-comment-${medicationRequest.publicId}`}
                        value={form.data.comment}
                        onChange={(event) =>
                            form.setData('comment', event.target.value)
                        }
                        className="mt-1 min-h-20 bg-white"
                    />
                    <InputError message={errors.comment} className="mt-1" />
                </div>
                <div>
                    <Label
                        htmlFor={`final-check-notes-${medicationRequest.publicId}`}
                    >
                        Catatan pemeriksaan akhir
                    </Label>
                    <Textarea
                        id={`final-check-notes-${medicationRequest.publicId}`}
                        value={form.data.final_check_notes}
                        onChange={(event) =>
                            form.setData(
                                'final_check_notes',
                                event.target.value,
                            )
                        }
                        className="mt-1 min-h-20 bg-white"
                    />
                </div>
                <label className="flex items-start gap-2 rounded border border-sky-200 bg-white p-3 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.final_check_confirmed}
                        onChange={(event) =>
                            form.setData(
                                'final_check_confirmed',
                                event.target.checked,
                            )
                        }
                        className="mt-0.5"
                    />
                    <span>
                        Saya telah memeriksa identitas, obat, jumlah, etiket,
                        lot sintetis, dan versi penyiapan.
                    </span>
                </label>
                <InputError
                    message={errors.final_check_confirmed}
                    className="mt-1"
                />
                <div className="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => submit('REQUEST_CHANGES')}
                    >
                        <RefreshCw className="size-4" aria-hidden="true" />
                        Minta perbaikan
                    </Button>
                    <Button
                        type="button"
                        onClick={() => submit('APPROVE_SIMULATION')}
                    >
                        <ShieldCheck className="size-4" aria-hidden="true" />
                        Setujui pemeriksaan akhir
                    </Button>
                </div>
            </fieldset>
        </form>
    );
}

function PreparationHistory({
    medicationRequest,
}: {
    medicationRequest: PharmacyMedicationRequestRecord;
}) {
    if (medicationRequest.dispensePreparations.length === 0) {
        return null;
    }

    return (
        <section className="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
            <h3 className="font-semibold text-slate-950">
                Riwayat versi penyiapan
            </h3>
            <ol className="mt-3 space-y-3">
                {medicationRequest.dispensePreparations.map((preparation) => (
                    <li
                        key={preparation.publicId}
                        className="rounded border border-slate-200 bg-white p-3 text-xs leading-5"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="font-semibold">
                                Versi {preparation.versionNumber} ·{' '}
                                {preparation.outcome.label}
                            </p>
                            <Badge variant="outline">
                                {preparation.review
                                    ? preparation.review.action.label
                                    : 'Menunggu supervisor'}
                            </Badge>
                        </div>
                        <p className="mt-1">
                            Penyiap: {preparation.preparer} ·{' '}
                            {preparation.quantity} {preparation.unit} ·{' '}
                            {formatDateTime(preparation.preparedAt)}
                        </p>
                        {preparation.changeReason && (
                            <p className="mt-1">
                                Perubahan: {preparation.changeReason}
                            </p>
                        )}
                        {preparation.review && (
                            <p className="mt-1">
                                Pemeriksa: {preparation.review.checker} ·{' '}
                                {preparation.review.comment ?? 'Tanpa komentar'}
                            </p>
                        )}
                        <code className="mt-2 block font-mono text-[0.65rem] break-all">
                            SHA-256 {preparation.contentHash}
                        </code>
                    </li>
                ))}
            </ol>
        </section>
    );
}

function ReviewHistory({
    medicationRequest,
}: {
    medicationRequest: PharmacyMedicationRequestRecord;
}) {
    if (medicationRequest.reviews.length === 0) {
        return (
            <div className="rounded-md border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground">
                Belum ada telaah farmasi manusia.
            </div>
        );
    }

    return (
        <ol className="space-y-3">
            {medicationRequest.reviews.map((review, index) => (
                <li
                    key={review.publicId}
                    className={cn(
                        'rounded-md border p-4',
                        index === 0
                            ? 'border-primary bg-sky-50/50'
                            : 'border-border bg-muted/30',
                    )}
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <Badge variant="outline">
                                v{review.versionNumber}
                            </Badge>
                            <Badge variant="outline">
                                {review.overallOutcome.label}
                            </Badge>
                            {index === 0 && (
                                <Badge className="bg-primary text-white">
                                    Telaah saat ini
                                </Badge>
                            )}
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {review.reviewer} ·{' '}
                            {formatDateTime(review.reviewedAt)} WIB
                        </span>
                    </div>
                    <div className="mt-3 grid gap-2 text-xs md:grid-cols-3">
                        {(Object.keys(domainLabels) as DomainKey[]).map(
                            (domain) => {
                                const findings = review.domainResults[
                                    domain
                                ].filter(
                                    (row) => row.outcome === 'FINDING',
                                ).length;

                                return (
                                    <div
                                        key={domain}
                                        className="rounded border border-border bg-white p-2"
                                    >
                                        <span className="font-semibold">
                                            {domainLabels[domain]}
                                        </span>{' '}
                                        · {findings} temuan
                                    </div>
                                );
                            },
                        )}
                    </div>
                    <code className="mt-3 block font-mono text-[0.65rem] break-all text-muted-foreground">
                        SHA-256 {review.contentHash}
                    </code>
                </li>
            ))}
        </ol>
    );
}

export default function PharmacyWorkspace({
    encounter,
    patient,
    session,
    assignment,
    allergySource,
    reviewDefinition,
    medicationRequests,
    stocks,
    formOptions,
    urls,
}: PharmacyWorkspaceProps) {
    return (
        <>
            <Head title="Telaah resep dan dispensing" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Pharmacy loop · {session.code}
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Telaah Resep &amp; Dispensing
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Telaah administratif, farmasetik, dan klinis dicatat
                            sebagai penilaian manusia. Intervensi menahan item
                            tanpa mengubah resep; dispensing dan stok sintetis
                            disimpan dalam satu transaksi.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.encounter}>
                                <ClipboardCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Ringkasan encounter
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.workQueue}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Antrean kerja
                            </Link>
                        </Button>
                    </div>
                </header>

                <PatientContextBanner
                    patient={patient}
                    encounter={encounter}
                    actingAs={`${assignment.program} · ${assignment.role}`}
                />

                <section className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-lg border border-orange-200 bg-orange-50 p-5">
                        <div className="flex gap-3 text-[#743719]">
                            <AlertTriangle
                                className="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <h2 className="font-semibold">
                                    Tidak ada auto-clear klinis
                                </h2>
                                <p className="mt-1 text-sm leading-6">
                                    Sistem menampilkan fakta sumber dan menolak
                                    formulir tidak lengkap. Keputusan menerima,
                                    meminta klarifikasi, atau menyarankan batal
                                    sepenuhnya milik penelaah.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="rounded-lg border border-sky-200 bg-sky-50 p-5">
                        <div className="flex gap-3 text-[#174c68]">
                            <ShieldCheck
                                className="mt-0.5 size-5 shrink-0"
                                aria-hidden="true"
                            />
                            <div>
                                <h2 className="font-semibold">Sumber alergi</h2>
                                <p className="mt-1 text-sm leading-6">
                                    {allergySource
                                        ? `${allergySource.label}${allergySource.details ? `: ${allergySource.details}` : ''}`
                                        : 'Belum tersedia.'}
                                </p>
                                {allergySource && (
                                    <code className="mt-2 block font-mono text-[0.65rem] break-all">
                                        SHA-256{' '}
                                        {allergySource.sourceContentHash}
                                    </code>
                                )}
                            </div>
                        </div>
                    </div>
                </section>

                {medicationRequests.length === 0 ? (
                    <section className="clinical-shadow rounded-lg border border-border bg-white px-6 py-14 text-center">
                        <Pill
                            className="mx-auto size-9 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <h2 className="mt-3 text-xl font-semibold">
                            Belum ada permintaan obat
                        </h2>
                    </section>
                ) : (
                    <div className="space-y-5">
                        {medicationRequests.map((medicationRequest) => (
                            <article
                                key={medicationRequest.publicId}
                                className={cn(
                                    'clinical-shadow overflow-hidden rounded-lg border bg-white',
                                    medicationRequest.status === 'CANCELLED'
                                        ? 'border-slate-300 opacity-80'
                                        : 'border-border',
                                )}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                                    <div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="outline">
                                                Revisi{' '}
                                                {
                                                    medicationRequest.revisionNumber
                                                }
                                            </Badge>
                                            <Badge variant="outline">
                                                {medicationRequest.status}
                                            </Badge>
                                            {medicationRequest.replacesPublicId && (
                                                <Badge variant="outline">
                                                    <RefreshCw
                                                        className="size-3"
                                                        aria-hidden="true"
                                                    />
                                                    Pengganti
                                                </Badge>
                                            )}
                                        </div>
                                        <h2 className="mt-2 text-xl font-semibold">
                                            {
                                                medicationRequest.authoredMedication
                                            }
                                        </h2>
                                        <p className="mt-1 text-sm text-muted-foreground">
                                            {medicationRequest.form ??
                                                'Bentuk —'}{' '}
                                            ·{' '}
                                            {medicationRequest.strength ??
                                                'Kekuatan —'}
                                        </p>
                                    </div>
                                    <span className="font-mono text-xs text-muted-foreground">
                                        Item #{medicationRequest.sequenceNumber}
                                    </span>
                                </div>

                                <div className="grid items-start gap-5 p-5 xl:grid-cols-[22rem_minmax(0,1fr)]">
                                    <div className="space-y-4">
                                        <ClinicalVersionStamp
                                            compact
                                            versionNumber={
                                                medicationRequest.source
                                                    .medicalVersionNumber
                                            }
                                            schemaVersion="medical-assessment.v1"
                                            contentHash={
                                                medicationRequest.source
                                                    .medicalContentHash
                                            }
                                            status={{
                                                code: 'APPROVED',
                                                label: 'Sumber disetujui',
                                            }}
                                        />
                                        <dl className="grid gap-3 rounded-md border border-border p-4 text-sm sm:grid-cols-2 xl:grid-cols-1">
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Dosis &amp; rute
                                                </dt>
                                                <dd className="font-medium">
                                                    {
                                                        medicationRequest.doseValue
                                                    }{' '}
                                                    {medicationRequest.doseUnit}{' '}
                                                    · {medicationRequest.route}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Frekuensi &amp; durasi
                                                </dt>
                                                <dd className="font-medium">
                                                    {
                                                        medicationRequest.frequency
                                                    }{' '}
                                                    ·{' '}
                                                    {medicationRequest.duration}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Jumlah
                                                </dt>
                                                <dd className="font-medium">
                                                    {
                                                        medicationRequest.quantityValue
                                                    }{' '}
                                                    {
                                                        medicationRequest.quantityUnit
                                                    }
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Petunjuk
                                                </dt>
                                                <dd className="font-medium">
                                                    {
                                                        medicationRequest.directions
                                                    }
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Diagnosis / indikasi sumber
                                                </dt>
                                                <dd className="font-medium">
                                                    {medicationRequest.source
                                                        .diagnosis ??
                                                        medicationRequest.indicationText ??
                                                        'Tidak ditautkan'}
                                                </dd>
                                            </div>
                                        </dl>
                                        <p className="text-xs text-muted-foreground">
                                            Ditulis{' '}
                                            {medicationRequest.requester} ·{' '}
                                            {formatDateTime(
                                                medicationRequest.authoredAt,
                                            )}{' '}
                                            WIB
                                        </p>
                                        {medicationRequest.replacementReason && (
                                            <div className="rounded border border-violet-200 bg-violet-50 p-3 text-xs leading-5 text-violet-950">
                                                <strong>
                                                    Alasan penggantian:
                                                </strong>{' '}
                                                {
                                                    medicationRequest.replacementReason
                                                }
                                            </div>
                                        )}
                                        {medicationRequest.cancellationReason && (
                                            <div className="rounded border border-slate-200 bg-slate-50 p-3 text-xs leading-5">
                                                <strong>
                                                    Alasan pembatalan:
                                                </strong>{' '}
                                                {
                                                    medicationRequest.cancellationReason
                                                }
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-5">
                                        <section>
                                            <h3 className="mb-3 font-semibold">
                                                Riwayat telaah
                                            </h3>
                                            <ReviewHistory
                                                medicationRequest={
                                                    medicationRequest
                                                }
                                            />
                                        </section>

                                        {medicationRequest.interventions
                                            .length > 0 && (
                                            <section>
                                                <h3 className="mb-3 font-semibold">
                                                    Intervensi farmasi
                                                </h3>
                                                <div className="space-y-3">
                                                    {medicationRequest.interventions.map(
                                                        (intervention) => (
                                                            <div
                                                                key={
                                                                    intervention.publicId
                                                                }
                                                                className="rounded-md border border-amber-200 bg-amber-50 p-4"
                                                            >
                                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                                    <Badge variant="outline">
                                                                        {
                                                                            intervention.status
                                                                        }
                                                                    </Badge>
                                                                    <span className="text-xs text-amber-900">
                                                                        {
                                                                            intervention.openedBy
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {formatDateTime(
                                                                            intervention.openedAt,
                                                                        )}{' '}
                                                                        WIB
                                                                    </span>
                                                                </div>
                                                                <p className="mt-3 text-sm leading-6 font-semibold text-amber-950">
                                                                    {
                                                                        intervention.question
                                                                    }
                                                                </p>
                                                                {intervention.recommendation && (
                                                                    <p className="mt-1 text-sm leading-6 text-amber-950">
                                                                        Rekomendasi:{' '}
                                                                        {
                                                                            intervention.recommendation
                                                                        }
                                                                    </p>
                                                                )}
                                                                <ol className="mt-3 space-y-2">
                                                                    {intervention.messages.map(
                                                                        (
                                                                            message,
                                                                        ) => (
                                                                            <li
                                                                                key={
                                                                                    message.publicId
                                                                                }
                                                                                className="rounded border border-amber-200 bg-white p-3 text-xs leading-5"
                                                                            >
                                                                                <p className="font-semibold">
                                                                                    {
                                                                                        message.author
                                                                                    }{' '}
                                                                                    ·{' '}
                                                                                    {
                                                                                        message.messageType
                                                                                    }
                                                                                </p>
                                                                                <p className="mt-1 whitespace-pre-wrap">
                                                                                    {
                                                                                        message.messageText
                                                                                    }
                                                                                </p>
                                                                            </li>
                                                                        ),
                                                                    )}
                                                                </ol>
                                                                {intervention
                                                                    .response
                                                                    .allowed && (
                                                                    <InterventionResponseForm
                                                                        intervention={
                                                                            intervention
                                                                        }
                                                                        medicationRequest={
                                                                            medicationRequest
                                                                        }
                                                                        options={
                                                                            formOptions.responseActions
                                                                        }
                                                                    />
                                                                )}
                                                            </div>
                                                        ),
                                                    )}
                                                </div>
                                            </section>
                                        )}

                                        {medicationRequest.reviewAction
                                            .allowed && (
                                            <PharmacyReviewForm
                                                medicationRequest={
                                                    medicationRequest
                                                }
                                                definition={reviewDefinition}
                                            />
                                        )}

                                        {medicationRequest.dispenseAction
                                            .allowed && (
                                            <DispenseForm
                                                medicationRequest={
                                                    medicationRequest
                                                }
                                                stocks={stocks}
                                                outcomes={
                                                    formOptions.dispenseOutcomes
                                                }
                                            />
                                        )}

                                        <PreparationHistory
                                            medicationRequest={
                                                medicationRequest
                                            }
                                        />

                                        {medicationRequest.finalCheckAction
                                            .allowed && (
                                            <FinalCheckForm
                                                medicationRequest={
                                                    medicationRequest
                                                }
                                            />
                                        )}

                                        {medicationRequest.dispense && (
                                            <section className="rounded-md border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <h3 className="flex items-center gap-2 font-semibold">
                                                        <CheckCircle2
                                                            className="size-5"
                                                            aria-hidden="true"
                                                        />
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .outcome.label
                                                        }
                                                    </h3>
                                                    <span className="font-mono text-xs">
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .quantity
                                                        }{' '}
                                                        {
                                                            medicationRequest
                                                                .dispense.unit
                                                        }
                                                    </span>
                                                </div>
                                                <p className="mt-2 text-xs leading-5">
                                                    Penyiap:{' '}
                                                    {
                                                        medicationRequest
                                                            .dispense.preparer
                                                    }{' '}
                                                    · Pemeriksa:{' '}
                                                    {
                                                        medicationRequest
                                                            .dispense.checker
                                                    }
                                                </p>
                                                {medicationRequest.dispense
                                                    .outcomeReason && (
                                                    <p className="mt-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-950">
                                                        <span className="font-semibold">
                                                            Alasan outcome:
                                                        </span>{' '}
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .outcomeReason
                                                        }
                                                    </p>
                                                )}
                                                {medicationRequest.dispense
                                                    .stockMovement && (
                                                    <p className="mt-2 text-xs">
                                                        Stok sintetis:{' '}
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .stockMovement
                                                                .balanceBefore
                                                        }{' '}
                                                        →{' '}
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .stockMovement
                                                                .balanceAfter
                                                        }
                                                    </p>
                                                )}
                                                <div className="mt-3 flex items-start gap-2">
                                                    <Fingerprint
                                                        className="mt-0.5 size-4 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <code className="font-mono text-[0.65rem] break-all">
                                                        SHA-256{' '}
                                                        {
                                                            medicationRequest
                                                                .dispense
                                                                .contentHash
                                                        }
                                                    </code>
                                                </div>
                                            </section>
                                        )}
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}
