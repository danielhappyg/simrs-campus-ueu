import { router } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { RegistrationAppointment } from '@/types';

type Outcome = 'CANCELLED' | 'NO_SHOW';

export default function VisitTerminationDialog({
    appointment,
}: {
    appointment: RegistrationAppointment;
}) {
    const defaultOutcome: Outcome = appointment.termination.canCancel
        ? 'CANCELLED'
        : 'NO_SHOW';
    const [open, setOpen] = useState(false);
    const [outcome, setOutcome] = useState<Outcome>(defaultOutcome);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<{
        reason?: string;
        workflow?: string;
    }>({});
    const allowed =
        appointment.termination.canCancel ||
        appointment.termination.canMarkNoShow;
    const reasonErrorId = `termination-reason-error-${appointment.publicId}`;
    const reasonHintId = `termination-reason-hint-${appointment.publicId}`;

    if (!allowed) {
        return null;
    }

    function submit() {
        setProcessing(true);
        setErrors({});
        router.post(
            appointment.termination.url,
            { outcome, reason },
            {
                preserveScroll: true,
                onError: (next) =>
                    setErrors({
                        reason:
                            typeof next.reason === 'string'
                                ? next.reason
                                : undefined,
                        workflow:
                            typeof next.workflow === 'string'
                                ? next.workflow
                                : undefined,
                    }),
                onSuccess: () => {
                    setOpen(false);
                    setOutcome(defaultOutcome);
                    setReason('');
                },
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline">
                    <Ban className="size-4" aria-hidden="true" />
                    Akhiri kunjungan
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Akhiri kunjungan sintetis</DialogTitle>
                    <DialogDescription>
                        Outcome ini terminal dan tidak menghapus check-in, tugas
                        selesai, atau riwayat sebelumnya.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="rounded-md border border-orange-200 bg-orange-50 px-3 py-2 text-sm text-[#743719]">
                        <span className="font-semibold">
                            {appointment.patient.fullName}
                        </span>{' '}
                        · {appointment.appointmentCode}
                    </div>
                    <div className="space-y-2">
                        <Label
                            htmlFor={`termination-outcome-${appointment.publicId}`}
                        >
                            Outcome kunjungan
                        </Label>
                        <select
                            id={`termination-outcome-${appointment.publicId}`}
                            value={outcome}
                            onChange={(event) =>
                                setOutcome(event.target.value as Outcome)
                            }
                            className="min-h-11 w-full rounded-md border border-input bg-background px-3 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {appointment.termination.canCancel && (
                                <option value="CANCELLED">
                                    Batalkan kunjungan
                                </option>
                            )}
                            {appointment.termination.canMarkNoShow && (
                                <option value="NO_SHOW">
                                    Tandai tidak hadir
                                </option>
                            )}
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label
                            htmlFor={`termination-reason-${appointment.publicId}`}
                        >
                            Alasan terminasi
                        </Label>
                        <textarea
                            id={`termination-reason-${appointment.publicId}`}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            minLength={10}
                            maxLength={500}
                            required
                            aria-invalid={Boolean(errors.reason)}
                            aria-describedby={`${reasonHintId}${errors.reason ? ` ${reasonErrorId}` : ''}`}
                            className="min-h-24 w-full rounded-md border border-input bg-background px-3 py-2 focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none aria-invalid:border-destructive"
                        />
                        <p
                            id={reasonHintId}
                            className="text-xs text-muted-foreground"
                        >
                            Wajib 10–500 karakter. Alasan disimpan pada
                            provenance terlindungi, bukan layar antrean publik.
                        </p>
                        <InputError
                            id={reasonErrorId}
                            message={errors.reason}
                            role="alert"
                        />
                    </div>
                    <InputError message={errors.workflow} role="alert" />
                </div>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing}
                        >
                            Kembali
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing || reason.trim().length < 10}
                        onClick={submit}
                    >
                        {processing ? 'Mencatat…' : 'Konfirmasi terminasi'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
