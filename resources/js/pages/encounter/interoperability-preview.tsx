import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    Ban,
    Braces,
    CheckCircle2,
    ChevronDown,
    ChevronUp,
    CircleDotDashed,
    FileJson2,
    Fingerprint,
    GitCompareArrows,
    LockKeyhole,
    Network,
    Route,
    ShieldAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type FhirResource = {
    resourceType: string;
    id: string;
    [key: string]: unknown;
};

type BundleEntry = {
    fullUrl: string;
    resource: FhirResource;
};

type SourceIndexItem = {
    fullUrl: string;
    resourceType: string;
    resourceId: string;
    sourceType: string;
    sourcePublicId: string;
    sourcePath: string;
};

type ValidationItem = {
    code: string;
    severity?: string;
    path?: string;
    message: string;
};

type Props = {
    boundary: {
        classification: string;
        environment: string;
        mode: string;
        transportState: string;
        fhirVersion: string;
        satusehatProfileStatus: string;
        readyForTransmission: boolean;
        externalEndpoint: string | null;
    };
    summary: {
        resourceCount: number;
        resourceTypeCounts: Record<string, number>;
    };
    bundle: {
        resourceType: string;
        id: string;
        type: string;
        timestamp: string;
        entry: BundleEntry[];
        [key: string]: unknown;
    };
    sourceIndex: SourceIndexItem[];
    validation: {
        status: string;
        readyForTransmission: boolean;
        structuralErrors: ValidationItem[];
        issues: ValidationItem[];
    };
    urls: {
        self: string;
        back: string;
        encounter: string;
        timeline: string;
    };
};

const resourceStyles: Record<string, string> = {
    Composition: 'border-l-[#00639f]',
    Patient: 'border-l-[#6b4ea0]',
    Organization: 'border-l-[#516674]',
    Encounter: 'border-l-[#174c68]',
    Observation: 'border-l-[#2f7659]',
    Condition: 'border-l-[#b54708]',
    ServiceRequest: 'border-l-[#007c91]',
    DiagnosticReport: 'border-l-[#176b87]',
    MedicationRequest: 'border-l-[#915000]',
    QuestionnaireResponse: 'border-l-[#7a5b00]',
    MedicationDispense: 'border-l-[#286a4f]',
    Procedure: 'border-l-[#8c3d77]',
};

function nestedText(resource: FhirResource, path: string[]): string | null {
    let value: unknown = resource;

    for (const segment of path) {
        if (typeof value !== 'object' || value === null) {
            return null;
        }

        value = (value as Record<string, unknown>)[segment];
    }

    return typeof value === 'string' ? value : null;
}

function resourceDescription(resource: FhirResource): string {
    const coding = Array.isArray(
        (resource.code as Record<string, unknown> | undefined)?.coding,
    )
        ? (
              (resource.code as Record<string, unknown>).coding as Array<
                  Record<string, unknown>
              >
          )[0]
        : null;
    const codedLabel = coding
        ? [coding.code, coding.display]
              .filter((value): value is string => typeof value === 'string')
              .join(' · ')
        : '';

    if (codedLabel) {
        return codedLabel;
    }

    const candidates = [
        nestedText(resource, ['title']),
        nestedText(resource, ['code', 'text']),
        nestedText(resource, ['medicationCodeableConcept', 'text']),
        nestedText(resource, ['name']),
        nestedText(resource, ['status']),
    ];
    const names = resource.name;

    if (Array.isArray(names) && typeof names[0] === 'object' && names[0]) {
        const nameText = (names[0] as Record<string, unknown>).text;

        if (typeof nameText === 'string') {
            candidates.unshift(nameText);
        }
    }

    return (
        candidates.find((candidate): candidate is string =>
            Boolean(candidate),
        ) ?? 'Sumber terpetakan'
    );
}

function formatTimestamp(value: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'long',
        timeStyle: 'short',
        timeZone: 'Asia/Jakarta',
    }).format(new Date(value));
}

function shortId(value: string): string {
    return value.length > 18
        ? `${value.slice(0, 8)}…${value.slice(-6)}`
        : value;
}

export default function OutpatientInteroperabilityPreview({
    boundary,
    summary,
    bundle,
    sourceIndex,
    validation,
    urls,
}: Props) {
    const [showJson, setShowJson] = useState(false);
    const json = useMemo(() => JSON.stringify(bundle, null, 2), [bundle]);
    const findings = [...validation.structuralErrors, ...validation.issues];
    const structurallyClear = validation.structuralErrors.length === 0;

    return (
        <>
            <Head title="Pratinjau Interoperabilitas Lokal" />

            <div className="mx-auto flex w-full max-w-[1480px] flex-1 flex-col gap-5 px-4 py-6 md:px-6 md:py-8">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-xs font-bold tracking-[0.14em] text-primary uppercase">
                            Laboratorium interoperabilitas · FHIR R4
                        </p>
                        <h1 className="mt-1 text-3xl font-semibold md:text-4xl">
                            Pratinjau Interoperabilitas Lokal
                        </h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-muted-foreground">
                            Inspeksi bagaimana sumber encounter final dipetakan
                            ke resource FHIR tanpa membuat klaim kesesuaian
                            profil dan tanpa koneksi ke sistem eksternal.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={urls.timeline}>
                                <Route className="size-4" aria-hidden="true" />
                                Linimasa sumber
                            </Link>
                        </Button>
                        <Button asChild variant="outline">
                            <Link href={urls.back}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Kembali ke debrief
                            </Link>
                        </Button>
                    </div>
                </header>

                <section
                    aria-labelledby="transport-boundary-title"
                    className="clinical-shadow overflow-hidden rounded-lg border border-[#efb08d] bg-white"
                >
                    <div className="grid lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <div className="p-5 md:p-6">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge className="border-[#b53b13] bg-[#f05828] text-white">
                                    {boundary.classification}
                                </Badge>
                                <Badge variant="outline">
                                    Bundle · {bundle.type}
                                </Badge>
                                <Badge variant="outline">
                                    FHIR {boundary.fhirVersion}
                                </Badge>
                            </div>

                            <h2
                                id="transport-boundary-title"
                                className="mt-5 text-2xl font-semibold text-[#063650]"
                            >
                                Pemetaan berhenti di perangkat ini
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-[#365867]">
                                Bundle disusun ulang saat halaman dibuka dari
                                sumber final yang telah disetujui. Tidak ada
                                endpoint, kredensial, antrean kirim, atau aksi
                                transmisi pada modul ini.
                            </p>

                            <ol
                                aria-label="Batas aliran interoperabilitas"
                                className="mt-6 grid items-stretch gap-3 sm:grid-cols-[1fr_auto_1fr_auto_1fr]"
                            >
                                <li className="rounded-md border border-[#b9d5e3] bg-[#eaf4f8] p-4">
                                    <Fingerprint
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <p className="mt-3 text-xs font-bold tracking-wider text-primary uppercase">
                                        Sumber final
                                    </p>
                                    <p className="mt-1 font-semibold">
                                        {sourceIndex.length} jejak provenance
                                    </p>
                                </li>
                                <GitCompareArrows
                                    className="m-auto hidden size-5 text-[#6c8794] sm:block"
                                    aria-hidden="true"
                                />
                                <li className="rounded-md border border-primary bg-white p-4 ring-2 ring-[#b8ddef] ring-offset-2">
                                    <FileJson2
                                        className="size-5 text-primary"
                                        aria-hidden="true"
                                    />
                                    <p className="mt-3 text-xs font-bold tracking-wider text-primary uppercase">
                                        Koleksi lokal
                                    </p>
                                    <p className="mt-1 font-semibold">
                                        {summary.resourceCount} resource
                                    </p>
                                </li>
                                <CircleDotDashed
                                    className="m-auto hidden size-5 text-[#f05828] sm:block"
                                    aria-hidden="true"
                                />
                                <li className="rounded-md border border-dashed border-[#da754e] bg-[#fff4ed] p-4">
                                    <Ban
                                        className="size-5 text-[#b53b13]"
                                        aria-hidden="true"
                                    />
                                    <p className="mt-3 text-xs font-bold tracking-wider text-[#b53b13] uppercase">
                                        Endpoint eksternal
                                    </p>
                                    <p className="mt-1 font-semibold text-[#7c2d12]">
                                        Tidak dikonfigurasi
                                    </p>
                                </li>
                            </ol>
                        </div>

                        <aside className="relative flex min-h-56 items-center justify-center overflow-hidden border-t border-[#efb08d] bg-[#fff4ed] p-6 lg:border-t-0 lg:border-l">
                            <div
                                aria-hidden="true"
                                className="absolute inset-x-[-3rem] h-12 -rotate-12 border-y-2 border-[#d94716] bg-[repeating-linear-gradient(135deg,#f05828_0,#f05828_18px,#fff4ed_18px,#fff4ed_36px)] opacity-20"
                            />
                            <div className="relative rounded-md border-2 border-[#b53b13] bg-white px-6 py-5 text-center shadow-[5px_5px_0_#f3c1a5]">
                                <LockKeyhole
                                    className="mx-auto size-6 text-[#b53b13]"
                                    aria-hidden="true"
                                />
                                <p className="mt-3 font-mono text-lg font-medium tracking-[0.12em] text-[#9a3412]">
                                    BELUM DIKIRIM
                                </p>
                                <p className="mt-2 text-xs font-bold text-[#7c2d12]">
                                    {boundary.satusehatProfileStatus}
                                </p>
                            </div>
                        </aside>
                    </div>
                </section>

                <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <section
                        aria-labelledby="resource-inventory-title"
                        className="clinical-shadow rounded-lg border bg-white"
                    >
                        <div className="flex flex-wrap items-end justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <p className="text-xs font-bold tracking-wider text-primary uppercase">
                                    Inventaris bundle
                                </p>
                                <h2
                                    id="resource-inventory-title"
                                    className="mt-1 text-xl font-semibold"
                                >
                                    Resource yang terbentuk
                                </h2>
                            </div>
                            <span className="font-mono text-sm text-muted-foreground">
                                {formatTimestamp(bundle.timestamp)}
                            </span>
                        </div>

                        <div className="grid gap-3 p-4 md:grid-cols-2 md:p-5">
                            {bundle.entry.map((entry, index) => (
                                <article
                                    key={entry.fullUrl}
                                    className={cn(
                                        'rounded-md border border-l-4 bg-white p-4',
                                        resourceStyles[
                                            entry.resource.resourceType
                                        ] ?? 'border-l-[#6c8794]',
                                    )}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-display text-lg font-semibold">
                                                {entry.resource.resourceType}
                                            </p>
                                            <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                                {resourceDescription(
                                                    entry.resource,
                                                )}
                                            </p>
                                        </div>
                                        <span className="font-mono text-xs text-[#6c8794]">
                                            {String(index + 1).padStart(2, '0')}
                                        </span>
                                    </div>
                                    <p
                                        className="mt-4 truncate border-t pt-3 font-mono text-xs text-[#516674]"
                                        title={entry.resource.id}
                                    >
                                        id {shortId(entry.resource.id)}
                                    </p>
                                </article>
                            ))}
                        </div>
                    </section>

                    <aside className="flex flex-col gap-5">
                        <section
                            aria-labelledby="validation-title"
                            className="clinical-shadow rounded-lg border bg-white"
                        >
                            <div className="border-b px-5 py-4">
                                <div className="flex items-center gap-2">
                                    {structurallyClear ? (
                                        <CheckCircle2
                                            className="size-5 text-success"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <ShieldAlert
                                            className="size-5 text-destructive"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <h2
                                        id="validation-title"
                                        className="text-xl font-semibold"
                                    >
                                        Batas validasi
                                    </h2>
                                </div>
                                <p className="mt-2 text-sm leading-5 text-muted-foreground">
                                    Struktur lokal diperiksa; validasi profil
                                    nasional belum dijalankan.
                                </p>
                            </div>
                            <ul className="divide-y">
                                {findings.map((finding) => (
                                    <li
                                        key={`${finding.code}-${finding.path ?? 'general'}`}
                                        className="p-4"
                                    >
                                        <div className="flex gap-3">
                                            <AlertTriangle
                                                className="mt-0.5 size-4 shrink-0 text-warning"
                                                aria-hidden="true"
                                            />
                                            <div>
                                                <p className="font-mono text-xs font-medium break-all text-[#7c4b00]">
                                                    {finding.code}
                                                </p>
                                                <p className="mt-1 text-sm leading-5 text-muted-foreground">
                                                    {finding.message}
                                                </p>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section
                            aria-labelledby="type-count-title"
                            className="rounded-lg border bg-[#063650] p-5 text-[#edf7fb]"
                        >
                            <div className="flex items-center gap-2">
                                <Network
                                    className="size-5 text-[#76c7ed]"
                                    aria-hidden="true"
                                />
                                <h2
                                    id="type-count-title"
                                    className="text-xl font-semibold"
                                >
                                    Komposisi resource
                                </h2>
                            </div>
                            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3">
                                {Object.entries(summary.resourceTypeCounts).map(
                                    ([type, count]) => (
                                        <div
                                            key={type}
                                            className="border-t border-[#2c607a] pt-2"
                                        >
                                            <dt className="text-xs text-[#b7d7e6]">
                                                {type}
                                            </dt>
                                            <dd className="mt-1 font-mono text-lg">
                                                {count}
                                            </dd>
                                        </div>
                                    ),
                                )}
                            </dl>
                        </section>
                    </aside>
                </div>

                <section
                    aria-labelledby="source-index-title"
                    className="clinical-shadow overflow-hidden rounded-lg border bg-white"
                >
                    <div className="border-b px-5 py-4">
                        <p className="text-xs font-bold tracking-wider text-primary uppercase">
                            Provenance ledger
                        </p>
                        <h2
                            id="source-index-title"
                            className="mt-1 text-xl font-semibold"
                        >
                            Indeks resource ke sumber lokal
                        </h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Setiap baris menjelaskan resource mana yang berasal
                            dari sumber final mana; ID basis data internal tidak
                            ditampilkan.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-[#eaf4f8] text-xs tracking-wider text-[#174c68] uppercase">
                                <tr>
                                    <th className="px-5 py-3 font-bold">
                                        Resource
                                    </th>
                                    <th className="px-5 py-3 font-bold">
                                        Tipe sumber
                                    </th>
                                    <th className="px-5 py-3 font-bold">
                                        Public ID sumber
                                    </th>
                                    <th className="px-5 py-3 font-bold">
                                        Jalur
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {sourceIndex.map((item) => (
                                    <tr key={item.fullUrl}>
                                        <td className="px-5 py-3">
                                            <span className="font-semibold">
                                                {item.resourceType}
                                            </span>
                                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                                {shortId(item.resourceId)}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3 font-mono text-xs">
                                            {item.sourceType}
                                        </td>
                                        <td className="px-5 py-3 font-mono text-xs">
                                            {shortId(item.sourcePublicId)}
                                        </td>
                                        <td className="px-5 py-3 font-mono text-xs text-muted-foreground">
                                            {item.sourcePath}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section
                    aria-labelledby="json-title"
                    className="overflow-hidden rounded-lg border border-[#2c607a] bg-[#063650] text-[#edf7fb]"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div>
                            <p className="text-xs font-bold tracking-wider text-[#76c7ed] uppercase">
                                Inspeksi teknis
                            </p>
                            <h2
                                id="json-title"
                                className="mt-1 text-xl font-semibold"
                            >
                                JSON bundle lokal
                            </h2>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            className="border-[#76c7ed] bg-transparent text-white hover:bg-[#0d4a6a] hover:text-white"
                            onClick={() => setShowJson((visible) => !visible)}
                            aria-expanded={showJson}
                            aria-controls="bundle-json"
                        >
                            <Braces className="size-4" aria-hidden="true" />
                            {showJson
                                ? 'Sembunyikan JSON bundle'
                                : 'Lihat JSON bundle'}
                            {showJson ? (
                                <ChevronUp
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            ) : (
                                <ChevronDown
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            )}
                        </Button>
                    </div>
                    {showJson && (
                        <div
                            id="bundle-json"
                            className="border-t border-[#2c607a]"
                        >
                            <pre className="max-h-[42rem] overflow-auto p-5 font-mono text-xs leading-6 text-[#d9edf6]">
                                {json}
                            </pre>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
