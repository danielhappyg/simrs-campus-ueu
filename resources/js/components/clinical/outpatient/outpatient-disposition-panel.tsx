import { useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { OutpatientDisposition, OutpatientDispositionType } from './types';

const options: Array<{
    code: OutpatientDispositionType;
    label: string;
    description: string;
}> = [
    {
        code: 'KONTROL_ULANG',
        label: 'Kontrol ulang',
        description: 'Catat rencana kontrol atau tindak lanjut pasien.',
    },
    {
        code: 'SEMBUH',
        label: 'Sembuh',
        description: 'Catat ringkasan klinis akhir kunjungan.',
    },
    {
        code: 'RAWAT_INAP',
        label: 'Rawat inap',
        description: 'Keputusan klinis dokter; tempat tidur dipilih registrar.',
    },
];

const labels: Record<OutpatientDispositionType, string> = {
    KONTROL_ULANG: 'Kontrol ulang',
    SEMBUH: 'Sembuh',
    RAWAT_INAP: 'Rawat inap',
};

function operationKey(): string {
    return globalThis.crypto?.randomUUID?.() ?? `rj-disposition-${Date.now()}`;
}

export function OutpatientDispositionPanel({
    disposition,
    canSign,
    signUrl,
    canCorrect,
    correctUrl,
    medicalDocumentVersion,
    medicalDocumentFinal,
}: {
    disposition: OutpatientDisposition;
    canSign: boolean;
    signUrl: string | null;
    canCorrect: boolean;
    correctUrl: string | null;
    medicalDocumentVersion: number;
    medicalDocumentFinal: boolean;
}) {
    const [type, setType] =
        useState<OutpatientDispositionType>('KONTROL_ULANG');
    const [correcting, setCorrecting] = useState(false);
    const form = useForm({
        disposition_type: type,
        expected_document_version: medicalDocumentVersion,
        idempotency_key: operationKey(),
        correction_reason: '',
        payload: {
            follow_up_plan: '',
            clinical_note: '',
            admission_reason: '',
            receiving_unit_handoff_note: '',
        },
    });
    const current = disposition.current;

    const changeType = (next: OutpatientDispositionType) => {
        setType(next);
        form.setData({
            ...form.data,
            disposition_type: next,
            expected_document_version: medicalDocumentVersion,
        });
    };

    const updatePayload = (
        key: keyof typeof form.data.payload,
        value: string,
    ) => {
        form.setData({
            ...form.data,
            payload: { ...form.data.payload, [key]: value },
            idempotency_key: operationKey(),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const targetUrl = correcting ? correctUrl : signUrl;

        if (!targetUrl || !medicalDocumentFinal) {
            return;
        }

        form.transform((data) => ({
            disposition_type: type,
            expected_document_version: medicalDocumentVersion,
            idempotency_key: data.idempotency_key,
            ...(correcting
                ? {
                      expected_disposition_version: current?.version ?? 0,
                      correction_reason: data.correction_reason,
                  }
                : {}),
            payload:
                type === 'KONTROL_ULANG'
                    ? { follow_up_plan: data.payload.follow_up_plan }
                    : type === 'SEMBUH'
                      ? { clinical_note: data.payload.clinical_note }
                      : {
                            admission_reason: data.payload.admission_reason,
                            receiving_unit_handoff_note:
                                data.payload.receiving_unit_handoff_note,
                        },
        }));
        form.post(targetUrl, { preserveScroll: true });
    };

    const beginCorrection = () => {
        if (!current) {
            return;
        }

        setType(current.code);
        form.setData({
            ...form.data,
            disposition_type: current.code,
            expected_document_version: medicalDocumentVersion,
            payload: {
                follow_up_plan: current.payload.follow_up_plan ?? '',
                clinical_note: current.payload.clinical_note ?? '',
                admission_reason: current.payload.admission_reason ?? '',
                receiving_unit_handoff_note:
                    current.payload.receiving_unit_handoff_note ?? '',
            },
            correction_reason: '',
            idempotency_key: operationKey(),
        });
        setCorrecting(true);
    };

    return (
        <section
            aria-labelledby="outpatient-disposition-title"
            className="rounded-lg border border-border bg-card p-3 md:p-4"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-[0.68rem] font-semibold tracking-[0.13em] text-muted-foreground uppercase">
                        Keputusan dokter
                    </p>
                    <h2
                        id="outpatient-disposition-title"
                        className="mt-0.5 flex items-center gap-2 text-base font-semibold"
                    >
                        <ClipboardCheck
                            aria-hidden="true"
                            className="size-5 text-primary"
                        />{' '}
                        Disposisi rawat jalan
                    </h2>
                </div>
                {current ? (
                    <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
                        <CheckCircle2 aria-hidden="true" className="size-3.5" />{' '}
                        Ditandatangani · v{current.version}
                    </span>
                ) : null}
            </div>

            {current && !correcting ? (
                <div className="mt-3 rounded-md border border-success/20 bg-success/5 p-3 text-sm">
                    <p className="font-semibold">
                        {labels[current.code]} · Dokumen medis v
                        {current.bound_medical_document_version}
                    </p>
                    <p className="mt-1 text-muted-foreground">
                        {current.physician_name ?? 'Dokter'}
                        {current.signed_at
                            ? ` · ${new Date(current.signed_at).toLocaleString('id-ID')}`
                            : ''}
                    </p>
                    {current.code === 'RAWAT_INAP' ? (
                        <>
                            <p className="mt-2">
                                <span className="font-medium">
                                    Alasan rawat inap:
                                </span>{' '}
                                {current.payload.admission_reason || '—'}
                            </p>
                            <p className="mt-1">
                                <span className="font-medium">
                                    Catatan unit penerima:
                                </span>{' '}
                                {current.payload.receiving_unit_handoff_note ||
                                    '—'}
                            </p>
                            <p className="mt-2 text-xs text-muted-foreground">
                                {disposition.pending_handoff
                                    ? 'Menunggu registrar memilih tempat tidur dan menyelesaikan serah terima.'
                                    : 'Serah terima rawat inap telah selesai.'}
                            </p>
                        </>
                    ) : (
                        <p className="mt-2">
                            {current.code === 'KONTROL_ULANG'
                                ? current.payload.follow_up_plan
                                : current.payload.clinical_note}
                        </p>
                    )}
                    {canCorrect && correctUrl && disposition.pending_handoff ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3"
                            onClick={beginCorrection}
                        >
                            Koreksi disposisi
                        </Button>
                    ) : (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Disposisi yang sudah ditandatangani bersifat
                            hanya-baca.
                            {!disposition.pending_handoff
                                ? ' Serah terima sudah selesai sehingga koreksi tidak lagi tersedia.'
                                : ''}
                        </p>
                    )}
                </div>
            ) : (
                <form onSubmit={submit} className="mt-3 space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Disposisi hanya dapat ditandatangani dokter setelah
                        dokumen medis versi saat ini berstatus Final.
                    </p>
                    {correcting ? (
                        <div className="grid gap-1.5 rounded-md border border-warning/30 bg-warning/5 p-3">
                            <Label htmlFor="disposition-correction-reason">
                                Alasan koreksi
                            </Label>
                            <textarea
                                id="disposition-correction-reason"
                                required
                                minLength={3}
                                className="min-h-20 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={form.data.correction_reason}
                                onChange={(event) =>
                                    form.setData({
                                        ...form.data,
                                        correction_reason: event.target.value,
                                        idempotency_key: operationKey(),
                                    })
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Koreksi membuat versi disposisi baru dan hanya
                                tersedia sebelum serah terima rawat inap.
                            </p>
                        </div>
                    ) : null}
                    <fieldset
                        disabled={
                            (correcting
                                ? !canCorrect || !correctUrl
                                : !canSign || !signUrl) ||
                            !medicalDocumentFinal ||
                            form.processing
                        }
                    >
                        <legend className="sr-only">Jenis disposisi</legend>
                        <div className="grid gap-2 md:grid-cols-3">
                            {options.map((option) => (
                                <label
                                    key={option.code}
                                    className="cursor-pointer rounded-md border border-border p-3 has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                                >
                                    <input
                                        className="sr-only"
                                        type="radio"
                                        name="outpatient-disposition"
                                        checked={type === option.code}
                                        onChange={() => changeType(option.code)}
                                    />
                                    <span className="block text-sm font-semibold">
                                        {option.label}
                                    </span>
                                    <span className="mt-1 block text-xs text-muted-foreground">
                                        {option.description}
                                    </span>
                                </label>
                            ))}
                        </div>
                        {type === 'KONTROL_ULANG' ? (
                            <div className="mt-3 grid gap-1.5">
                                <Label htmlFor="follow-up-plan">
                                    Rencana kontrol / tindak lanjut
                                </Label>
                                <textarea
                                    id="follow-up-plan"
                                    required
                                    className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    value={form.data.payload.follow_up_plan}
                                    onChange={(e) =>
                                        updatePayload(
                                            'follow_up_plan',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        ) : null}
                        {type === 'SEMBUH' ? (
                            <div className="mt-3 grid gap-1.5">
                                <Label htmlFor="disposition-clinical-note">
                                    Catatan klinis
                                </Label>
                                <textarea
                                    id="disposition-clinical-note"
                                    required
                                    className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    value={form.data.payload.clinical_note}
                                    onChange={(e) =>
                                        updatePayload(
                                            'clinical_note',
                                            e.target.value,
                                        )
                                    }
                                />
                            </div>
                        ) : null}
                        {type === 'RAWAT_INAP' ? (
                            <div className="mt-3 grid gap-3 md:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="admission-reason">
                                        Alasan rawat inap
                                    </Label>
                                    <textarea
                                        id="admission-reason"
                                        required
                                        className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        value={
                                            form.data.payload.admission_reason
                                        }
                                        onChange={(e) =>
                                            updatePayload(
                                                'admission_reason',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="receiving-unit-handoff-note">
                                        Catatan serah terima unit penerima
                                    </Label>
                                    <textarea
                                        id="receiving-unit-handoff-note"
                                        required
                                        className="min-h-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                        value={
                                            form.data.payload
                                                .receiving_unit_handoff_note
                                        }
                                        onChange={(e) =>
                                            updatePayload(
                                                'receiving_unit_handoff_note',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                        ) : null}
                        <Button className="mt-3" type="submit">
                            {form.processing
                                ? 'Menandatangani…'
                                : correcting
                                  ? 'Simpan koreksi disposisi'
                                  : 'Tandatangani disposisi'}
                        </Button>
                    </fieldset>
                    {!medicalDocumentFinal ? (
                        <p role="status" className="text-sm text-warning">
                            Finalisasi dokumen medis versi saat ini sebelum
                            menandatangani disposisi.
                        </p>
                    ) : null}
                    {!canSign ? (
                        <p className="text-sm text-muted-foreground">
                            Akun ini tidak memiliki kewenangan menandatangani
                            disposisi.
                        </p>
                    ) : null}
                </form>
            )}
        </section>
    );
}
