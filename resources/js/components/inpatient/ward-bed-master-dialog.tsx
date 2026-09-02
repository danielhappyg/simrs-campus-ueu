import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, RefObject } from 'react';
import type {
    WardBedMasterAction,
    WardBedOption,
} from '@/components/inpatient/ward-bed-types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type FormData = {
    code: string;
    display_name: string;
    room_label: string;
    service_class: string;
    expected_version: number | '';
    reason_code: string;
    idempotency_key: string;
};

const fieldLabels: Record<keyof FormData | 'master', string> = {
    code: 'Kode',
    display_name: 'Nama tampilan',
    room_label: 'Ruang',
    service_class: 'Kelas layanan',
    expected_version: 'Versi data',
    reason_code: 'Alasan perubahan',
    idempotency_key: 'Kunci penyimpanan',
    master: 'Perubahan data',
};

function newIdempotencyKey(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `ward-bed-${Date.now()}-${Math.random().toString(16).slice(2)}`
    );
}

function initialData(action: WardBedMasterAction | null): FormData {
    if (!action) {
        return {
            code: '',
            display_name: '',
            room_label: '',
            service_class: '',
            expected_version: '',
            reason_code: '',
            idempotency_key: newIdempotencyKey(),
        };
    }

    if (action.kind === 'CREATE_WARD') {
        return {
            code: '',
            display_name: '',
            room_label: '',
            service_class: '',
            expected_version: '',
            reason_code: 'INITIAL_SETUP',
            idempotency_key: newIdempotencyKey(),
        };
    }

    if (action.kind === 'CREATE_BED') {
        return {
            code: '',
            display_name: '',
            room_label: '',
            service_class: '',
            expected_version: '',
            reason_code: 'INITIAL_SETUP',
            idempotency_key: newIdempotencyKey(),
        };
    }

    if (action.kind === 'UPDATE_WARD') {
        return {
            code: action.ward.code,
            display_name: action.ward.display_name,
            room_label: '',
            service_class: '',
            expected_version: action.ward.version,
            reason_code: '',
            idempotency_key: newIdempotencyKey(),
        };
    }

    if (action.kind === 'RETIRE_WARD') {
        return {
            code: action.ward.code,
            display_name: action.ward.display_name,
            room_label: '',
            service_class: '',
            expected_version: action.ward.version,
            reason_code: 'RETIREMENT',
            idempotency_key: newIdempotencyKey(),
        };
    }

    if (action.kind === 'UPDATE_BED') {
        return {
            code: action.bed.code,
            display_name: action.bed.display_name,
            room_label: action.bed.room_label,
            service_class: action.bed.service_class,
            expected_version: action.bed.version,
            reason_code: '',
            idempotency_key: newIdempotencyKey(),
        };
    }

    return {
        code: action.bed.code,
        display_name: action.bed.display_name,
        room_label: action.bed.room_label,
        service_class: action.bed.service_class,
        expected_version: action.bed.version,
        reason_code: 'RETIREMENT',
        idempotency_key: newIdempotencyKey(),
    };
}

function actionCopy(action: WardBedMasterAction): {
    title: string;
    description: string;
    submit: string;
    success: string;
    destructive: boolean;
} {
    switch (action.kind) {
        case 'CREATE_WARD':
            return {
                title: 'Tambah bangsal',
                description:
                    'Kode disimpan permanen dan tidak dapat digunakan kembali.',
                submit: 'Simpan bangsal',
                success: 'Bangsal berhasil ditambahkan.',
                destructive: false,
            };
        case 'UPDATE_WARD':
            return {
                title: 'Ubah nama bangsal',
                description:
                    'Perubahan menambah versi baru tanpa mengubah kode bangsal.',
                submit: 'Simpan perubahan',
                success: 'Perubahan bangsal berhasil disimpan.',
                destructive: false,
            };
        case 'RETIRE_WARD':
            return {
                title: 'Nonaktifkan bangsal',
                description:
                    'Bangsal yang dinonaktifkan tidak dapat diaktifkan kembali. Riwayat tetap tersimpan.',
                submit: 'Nonaktifkan bangsal',
                success: 'Bangsal berhasil dinonaktifkan.',
                destructive: true,
            };
        case 'CREATE_BED':
            return {
                title: `Tambah tempat tidur · ${action.ward.display_name}`,
                description:
                    'Tempat tidur akan tetap berada pada bangsal ini. Kode tidak dapat diubah.',
                submit: 'Simpan tempat tidur',
                success: 'Tempat tidur berhasil ditambahkan.',
                destructive: false,
            };
        case 'UPDATE_BED':
            return {
                title: `Ubah tempat tidur · ${action.bed.code}`,
                description:
                    'Perubahan menambah versi baru. Kode dan bangsal tidak berubah.',
                submit: 'Simpan perubahan',
                success: 'Perubahan tempat tidur berhasil disimpan.',
                destructive: false,
            };
        case 'RETIRE_BED':
            return {
                title: `Nonaktifkan tempat tidur · ${action.bed.code}`,
                description:
                    'Tempat tidur yang dinonaktifkan tidak dapat dipilih atau diaktifkan kembali. Riwayat tetap tersimpan.',
                submit: 'Nonaktifkan tempat tidur',
                success: 'Tempat tidur berhasil dinonaktifkan.',
                destructive: true,
            };
    }
}

export function WardBedMasterDialog({
    action,
    reasonOptions,
    returnFocusRef,
    onDismiss,
    onSuccess,
}: {
    action: WardBedMasterAction | null;
    reasonOptions: WardBedOption[];
    returnFocusRef: RefObject<HTMLButtonElement | null>;
    onDismiss: () => void;
    onSuccess: (message: string) => void;
}) {
    const form = useForm<FormData>(initialData(action));
    const [validationAttempt, setValidationAttempt] = useState(0);
    const errorSummaryRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        form.setData(initialData(action));
        form.clearErrors();
        // The form instance is stable; action is the reset boundary.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [action]);

    const errors = Object.entries(form.errors);
    useEffect(() => {
        if (validationAttempt > 0 && errors.length > 0) {
            errorSummaryRef.current?.focus();
        }
    }, [errors.length, validationAttempt]);

    if (!action) {
        return null;
    }

    const copy = actionCopy(action);
    const creates =
        action.kind === 'CREATE_WARD' || action.kind === 'CREATE_BED';
    const isWard =
        action.kind === 'CREATE_WARD' ||
        action.kind === 'UPDATE_WARD' ||
        action.kind === 'RETIRE_WARD';
    const retires =
        action.kind === 'RETIRE_WARD' || action.kind === 'RETIRE_BED';

    const errorProps = (field: keyof FormData) => {
        const message = form.errors[field];

        return {
            'aria-invalid': message ? true : undefined,
            'aria-describedby': message ? `ward-bed-${field}-error` : undefined,
        };
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                onSuccess(copy.success);
                onDismiss();
            },
            onError: () => setValidationAttempt((attempt) => attempt + 1),
        };

        if (action.kind === 'UPDATE_WARD' || action.kind === 'UPDATE_BED') {
            form.patch(action.url, options);
        } else {
            form.post(action.url, options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onDismiss();
                }
            }}
        >
            <DialogContent
                className="max-h-[calc(100vh-2rem)] overflow-y-auto p-0 sm:max-w-xl"
                showCloseButton={false}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    returnFocusRef.current?.focus();
                }}
            >
                <DialogHeader className="border-b border-[#e2e8f0] bg-[#f8fafc] px-5 py-4 text-left">
                    <DialogTitle>{copy.title}</DialogTitle>
                    <DialogDescription>{copy.description}</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate>
                    <div className="grid gap-4 px-5 py-4">
                        {errors.length > 0 ? (
                            <div
                                ref={errorSummaryRef}
                                role="alert"
                                tabIndex={-1}
                                aria-labelledby="ward-bed-error-title"
                                className="rounded-md border border-[#fecaca] bg-[#fef2f2] p-3 text-sm text-[#991b1b] focus-visible:ring-2 focus-visible:ring-[#b91c1c] focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <p
                                    id="ward-bed-error-title"
                                    className="font-semibold"
                                >
                                    Perubahan belum dapat disimpan.
                                </p>
                                <ul className="mt-1 list-disc space-y-1 pl-5">
                                    {errors.map(([field, message]) => {
                                        const target =
                                            field === 'master' ||
                                            field === 'idempotency_key'
                                                ? null
                                                : `ward-bed-${field}`;

                                        return (
                                            <li key={field}>
                                                {target ? (
                                                    <a
                                                        href={`#${target}`}
                                                        className="underline"
                                                    >
                                                        {fieldLabels[
                                                            field as keyof typeof fieldLabels
                                                        ] ?? field}
                                                        : {message}
                                                    </a>
                                                ) : (
                                                    <span>{message}</span>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        ) : null}

                        {retires ? (
                            <div className="rounded-md border border-[#fed7aa] bg-[#fff7ed] p-3 text-sm text-[#9a3412]">
                                <p className="font-semibold">
                                    Tindakan ini bersifat permanen.
                                </p>
                                <p className="mt-1">
                                    Tidak ada tombol hapus atau aktifkan
                                    kembali.
                                </p>
                            </div>
                        ) : (
                            <>
                                {creates ? (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="ward-bed-code">
                                            Kode
                                        </Label>
                                        <Input
                                            id="ward-bed-code"
                                            {...errorProps('code')}
                                            value={form.data.code}
                                            onChange={(event) =>
                                                form.setData(
                                                    'code',
                                                    event.target.value.toUpperCase(),
                                                )
                                            }
                                            autoComplete="off"
                                        />
                                        <p className="text-xs text-[#64748b]">
                                            Kode disimpan dengan huruf kapital
                                            dan tidak dapat diubah.
                                        </p>
                                        <InputError
                                            id="ward-bed-code-error"
                                            message={form.errors.code}
                                        />
                                    </div>
                                ) : (
                                    <div>
                                        <p className="text-xs font-medium tracking-wide text-[#64748b] uppercase">
                                            Kode tetap
                                        </p>
                                        <p className="mt-1 font-mono text-sm text-[#0f172a]">
                                            {form.data.code}
                                        </p>
                                    </div>
                                )}

                                <div className="grid gap-1.5">
                                    <Label htmlFor="ward-bed-display_name">
                                        Nama tampilan
                                    </Label>
                                    <Input
                                        id="ward-bed-display_name"
                                        {...errorProps('display_name')}
                                        value={form.data.display_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'display_name',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        id="ward-bed-display_name-error"
                                        message={form.errors.display_name}
                                    />
                                </div>

                                {!isWard ? (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="ward-bed-room_label">
                                                Ruang
                                            </Label>
                                            <Input
                                                id="ward-bed-room_label"
                                                {...errorProps('room_label')}
                                                value={form.data.room_label}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'room_label',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                id="ward-bed-room_label-error"
                                                message={form.errors.room_label}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="ward-bed-service_class">
                                                Kelas layanan
                                            </Label>
                                            <Input
                                                id="ward-bed-service_class"
                                                {...errorProps('service_class')}
                                                value={form.data.service_class}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'service_class',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                id="ward-bed-service_class-error"
                                                message={
                                                    form.errors.service_class
                                                }
                                            />
                                        </div>
                                    </div>
                                ) : null}
                            </>
                        )}

                        {!creates && !retires ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="ward-bed-reason_code">
                                    Alasan perubahan
                                </Label>
                                <select
                                    id="ward-bed-reason_code"
                                    {...errorProps('reason_code')}
                                    className="min-h-11 w-full rounded-md border border-input bg-white px-3 text-sm shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30"
                                    value={form.data.reason_code}
                                    onChange={(event) =>
                                        form.setData(
                                            'reason_code',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Pilih alasan</option>
                                    {reasonOptions
                                        .filter(
                                            (option) =>
                                                option.value !==
                                                    'INITIAL_SETUP' &&
                                                option.value !== 'RETIREMENT',
                                        )
                                        .map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                </select>
                                <InputError
                                    id="ward-bed-reason_code-error"
                                    message={form.errors.reason_code}
                                />
                            </div>
                        ) : null}
                    </div>

                    <DialogFooter className="border-t border-[#e2e8f0] bg-[#f8fafc] px-5 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={form.processing}
                            onClick={onDismiss}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                copy.destructive ? 'destructive' : 'default'
                            }
                            className="min-h-11"
                            disabled={form.processing}
                        >
                            {form.processing ? 'Menyimpan…' : copy.submit}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
