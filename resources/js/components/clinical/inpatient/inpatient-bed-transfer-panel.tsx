import { useForm } from '@inertiajs/react';
import { ArrowRightLeft, ChevronDown, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    InpatientBedTransferAction,
    InpatientPlacementSnapshot,
} from './types';

let transferKeyFallback = 0;

export function newBedTransferIdempotencyKey() {
    const randomPart =
        globalThis.crypto?.randomUUID?.() ??
        `${Date.now().toString(36)}-${(++transferKeyFallback).toString(36)}`;

    return `inpatient-transfer-${randomPart}`.toLowerCase();
}

type Props = {
    action: InpatientBedTransferAction;
    disabledByUnsavedDocument: boolean;
};

export function InpatientBedTransferPanel({
    action,
    disabledByUnsavedDocument,
}: Props) {
    const [open, setOpen] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const form = useForm({
        expected_location_sequence: action.expected_location_sequence,
        expected_source_bed_public_id:
            action.expected_source_bed_public_id ?? '',
        target_bed_public_id: '',
        reason: '',
        idempotency_key: newBedTransferIdempotencyKey(),
    });
    const groups = useMemo(() => {
        const grouped = new Map<string, InpatientPlacementSnapshot[]>();

        for (const bed of action.target_beds) {
            const current = grouped.get(bed.ward_public_id) ?? [];
            current.push(bed);
            grouped.set(bed.ward_public_id, current);
        }

        return [...grouped.values()];
    }, [action.target_beds]);
    const canOpen =
        action.allowed &&
        action.url !== null &&
        action.expected_source_bed_public_id !== null &&
        action.target_beds.length > 0 &&
        !disabledByUnsavedDocument;
    const errors = Object.entries(form.errors).filter(
        (entry): entry is [string, string] => Boolean(entry[1]),
    );
    const selectedTargetStillAvailable = action.target_beds.some(
        (bed) => bed.bed_public_id === form.data.target_bed_public_id,
    );
    const selectedTargetBedPublicId = selectedTargetStillAvailable
        ? form.data.target_bed_public_id
        : '';
    const targetWasRemoved =
        form.data.target_bed_public_id !== '' && !selectedTargetStillAvailable;
    const actionVersion = `${action.expected_location_sequence}:${action.expected_source_bed_public_id ?? 'none'}:${action.target_beds.map((bed) => bed.bed_public_id).join(':')}`;
    const previousActionVersion = useRef(actionVersion);

    useEffect(() => {
        if (previousActionVersion.current === actionVersion) {
            return;
        }

        previousActionVersion.current = actionVersion;
        form.setData({
            expected_location_sequence: action.expected_location_sequence,
            expected_source_bed_public_id:
                action.expected_source_bed_public_id ?? '',
            target_bed_public_id: '',
            reason: '',
            idempotency_key: newBedTransferIdempotencyKey(),
        });
    }, [
        action.expected_location_sequence,
        action.expected_source_bed_public_id,
        actionVersion,
        form,
    ]);

    useEffect(() => {
        if (errors.length > 0) {
            errorSummaryRef.current?.focus();
        }
    }, [errors.length]);

    const close = () => {
        setOpen(false);
        form.clearErrors();
        requestAnimationFrame(() => triggerRef.current?.focus());
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.clearErrors();
        const reasonLength = [...form.data.reason.trim()].length;

        if (!selectedTargetBedPublicId) {
            form.setError('target_bed_public_id', 'Pilih tempat tidur tujuan.');
        }

        if (reasonLength < 5 || reasonLength > 500) {
            form.setError('reason', 'Alasan harus berisi 5–500 karakter.');
        }

        if (
            !action.url ||
            !action.expected_source_bed_public_id ||
            !selectedTargetBedPublicId ||
            reasonLength < 5 ||
            reasonLength > 500
        ) {
            return;
        }

        form.post(action.url, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset('target_bed_public_id', 'reason');
                form.setData('idempotency_key', newBedTransferIdempotencyKey());
                requestAnimationFrame(() => triggerRef.current?.focus());
            },
            onError: () => errorSummaryRef.current?.focus(),
        });
    };

    return (
        <div className="mt-4 border-t border-sidebar-border pt-4">
            <p className="sr-only" aria-live="polite">
                {targetWasRemoved
                    ? 'Pilihan tempat tidur dibersihkan karena tidak lagi tersedia.'
                    : ''}
            </p>
            <Button
                ref={triggerRef}
                type="button"
                variant="secondary"
                className="min-h-11 w-full justify-between"
                disabled={!canOpen}
                aria-disabled={!canOpen}
                aria-expanded={open}
                onClick={() => setOpen((current) => !current)}
            >
                <span className="inline-flex items-center gap-2">
                    <ArrowRightLeft aria-hidden="true" className="size-4" />
                    {open
                        ? 'Sembunyikan formulir transfer'
                        : 'Pindahkan tempat tidur'}
                </span>
                <ChevronDown
                    aria-hidden="true"
                    className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`}
                />
            </Button>

            {disabledByUnsavedDocument ? (
                <p className="mt-2 text-xs leading-5 opacity-80">
                    Simpan atau batalkan perubahan dokumen harian sebelum
                    memindahkan tempat tidur.
                </p>
            ) : !action.allowed ? (
                <p className="mt-2 text-xs leading-5 opacity-80">
                    Transfer tidak tersedia untuk status atau penempatan episode
                    ini.
                </p>
            ) : action.target_beds.length === 0 ? (
                <p className="mt-2 text-xs leading-5 opacity-80">
                    Belum ada tempat tidur aktif yang tersedia pada kelas yang
                    sama.
                </p>
            ) : null}

            {errors.length > 0 ? (
                <div
                    ref={errorSummaryRef}
                    tabIndex={-1}
                    role="alert"
                    className="mt-3 rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive outline-none focus-visible:ring-2 focus-visible:ring-destructive"
                >
                    <p className="font-semibold">
                        Transfer belum dapat disimpan
                    </p>
                    <ul className="mt-1 list-disc space-y-1 pl-5 text-xs">
                        {errors.map(([key, message]) => (
                            <li key={key}>{message}</li>
                        ))}
                    </ul>
                </div>
            ) : null}

            {open && canOpen ? (
                <form
                    onSubmit={submit}
                    className="mt-3 space-y-4 rounded-lg bg-background p-3 text-foreground shadow-sm"
                    noValidate
                >
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Transfer tempat tidur
                            </h2>
                            <p className="mt-1 text-xs leading-5 text-muted-foreground">
                                Hanya tempat tidur aktif, tersedia, dan sekelas
                                yang ditampilkan.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={close}
                            className="inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-label="Tutup formulir transfer"
                        >
                            <X aria-hidden="true" className="size-4" />
                        </button>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="target-bed">Tempat tidur tujuan</Label>
                        <select
                            id="target-bed"
                            value={selectedTargetBedPublicId}
                            onChange={(event) => {
                                form.setData(
                                    'target_bed_public_id',
                                    event.target.value,
                                );
                                form.clearErrors('target_bed_public_id');
                            }}
                            className="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-invalid={Boolean(
                                form.errors.target_bed_public_id,
                            )}
                        >
                            <option value="">Pilih tempat tidur</option>
                            {groups.map((beds) => (
                                <optgroup
                                    key={beds[0].ward_public_id}
                                    label={`${beds[0].ward_display_name} (${beds[0].ward_code})`}
                                >
                                    {beds.map((bed) => (
                                        <option
                                            key={bed.bed_public_id}
                                            value={bed.bed_public_id}
                                        >
                                            {bed.bed_display_name} ·{' '}
                                            {bed.room_label} · {bed.bed_code}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1.5">
                        <div className="flex items-end justify-between gap-3">
                            <Label htmlFor="transfer-reason">
                                Alasan transfer
                            </Label>
                            <span className="text-[0.7rem] text-muted-foreground">
                                {[...form.data.reason].length}/500
                            </span>
                        </div>
                        <textarea
                            id="transfer-reason"
                            value={form.data.reason}
                            onChange={(event) => {
                                form.setData('reason', event.target.value);
                                form.clearErrors('reason');
                            }}
                            rows={3}
                            maxLength={500}
                            className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-describedby="transfer-reason-help"
                            aria-invalid={Boolean(form.errors.reason)}
                        />
                        <p
                            id="transfer-reason-help"
                            className="text-xs leading-5 text-muted-foreground"
                        >
                            Tulis alasan operasional yang jelas, 5–500 karakter.
                        </p>
                    </div>

                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            onClick={close}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Memindahkan…'
                                : 'Pindahkan tempat tidur'}
                        </Button>
                    </div>
                </form>
            ) : null}
        </div>
    );
}
