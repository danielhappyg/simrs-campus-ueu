import type { PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { AlertTriangle, FileCheck2, LogOut, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type UnsavedChangesGuardProps = {
    formLabel: string;
    processing: boolean;
    onSaveDraft: (
        continueNavigation: () => void,
        reportFailure: () => void,
    ) => void;
};

export function UnsavedChangesGuard({
    formLabel,
    processing,
    onSaveDraft,
}: UnsavedChangesGuardProps) {
    const [pendingVisit, setPendingVisit] = useState<PendingVisit | null>(null);
    const [open, setOpen] = useState(false);
    const [savingDraft, setSavingDraft] = useState(false);
    const [saveError, setSaveError] = useState<string | null>(null);
    const bypassNextVisit = useRef(false);
    const busy = processing || savingDraft;

    const continueNavigation = useCallback(() => {
        if (!pendingVisit) {
            return;
        }

        const visit = pendingVisit;
        bypassNextVisit.current = true;
        setSavingDraft(false);
        setSaveError(null);
        setPendingVisit(null);
        setOpen(false);
        router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
            only: visit.only,
            except: visit.except,
            headers: visit.headers,
            errorBag: visit.errorBag,
            forceFormData: visit.forceFormData,
            queryStringArrayFormat: visit.queryStringArrayFormat,
            async: visit.async,
            showProgress: visit.showProgress,
            fresh: visit.fresh,
            reset: visit.reset,
            preserveUrl: visit.preserveUrl,
            preserveErrors: visit.preserveErrors,
            invalidateCacheTags: visit.invalidateCacheTags,
            viewTransition: visit.viewTransition,
        });
    }, [pendingVisit]);

    const stayOnPage = useCallback(() => {
        setSavingDraft(false);
        setSaveError(null);
        setPendingVisit(null);
        setOpen(false);
    }, []);

    const reportSaveFailure = useCallback(() => {
        setSavingDraft(false);
        setSaveError(
            'Periksa isian yang ditandai atau koneksi, lalu pilih Simpan draf lalu keluar lagi. Anda tetap berada di encounter ini.',
        );
    }, []);

    useEffect(() => {
        const removeBeforeListener = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (bypassNextVisit.current) {
                bypassNextVisit.current = false;

                return;
            }

            if (visit.method !== 'get' || visit.prefetch) {
                return;
            }

            setSavingDraft(false);
            setSaveError(null);
            setPendingVisit(visit);
            setOpen(true);

            return false;
        });
        const handleBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', handleBeforeUnload);

        return () => {
            removeBeforeListener();
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, []);

    return (
        <>
            <div
                role="status"
                aria-live="polite"
                className="clinical-shadow flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-amber-950"
            >
                <AlertTriangle
                    className="mt-0.5 size-5 shrink-0 text-amber-700"
                    aria-hidden="true"
                />
                <div className="min-w-0">
                    <p className="text-xs font-bold tracking-[0.12em] text-amber-800 uppercase">
                        Perubahan lokal
                    </p>
                    <p className="mt-0.5 text-sm font-semibold">
                        Perubahan belum disimpan
                    </p>
                    <p className="mt-1 text-xs leading-5 text-amber-900">
                        {formLabel} masih berubah hanya pada halaman ini. Simpan
                        sebagai versi draf sebelum berpindah jika perubahan
                        harus dipertahankan.
                    </p>
                </div>
            </div>

            <Dialog
                open={open}
                onOpenChange={(nextOpen) => {
                    if (!nextOpen && !busy) {
                        stayOnPage();
                    }
                }}
            >
                <DialogContent
                    className="overflow-hidden p-0 sm:max-w-xl"
                    onInteractOutside={(event) => event.preventDefault()}
                    showCloseButton={false}
                >
                    <div className="border-b border-amber-200 bg-amber-50 px-6 py-5">
                        <div className="flex items-center gap-2 text-xs font-bold tracking-[0.12em] text-amber-800 uppercase">
                            <ShieldCheck
                                className="size-4"
                                aria-hidden="true"
                            />
                            Lindungi pekerjaan pada encounter ini
                        </div>
                        <DialogHeader className="mt-3 text-left">
                            <DialogTitle className="text-2xl text-[#063650]">
                                Perubahan belum disimpan
                            </DialogTitle>
                            <DialogDescription className="leading-6 text-slate-700">
                                Anda akan meninggalkan {formLabel}. Pilih apa
                                yang harus dilakukan terhadap perubahan lokal
                                sebelum membuka halaman berikutnya.
                            </DialogDescription>
                        </DialogHeader>
                    </div>

                    <div className="grid gap-3 px-6 py-5 text-sm text-muted-foreground sm:grid-cols-3">
                        <div className="rounded-md border border-border p-3">
                            <p className="font-semibold text-foreground">
                                Tetap
                            </p>
                            <p className="mt-1 text-xs leading-5">
                                Lanjutkan mengisi tanpa kehilangan posisi.
                            </p>
                        </div>
                        <div className="rounded-md border border-sky-200 bg-sky-50 p-3">
                            <p className="font-semibold text-[#064f78]">
                                Simpan draf
                            </p>
                            <p className="mt-1 text-xs leading-5 text-[#245b75]">
                                Buat versi draf, lalu buka tujuan.
                            </p>
                        </div>
                        <div className="rounded-md border border-red-200 bg-red-50 p-3">
                            <p className="font-semibold text-red-800">
                                Buang lokal
                            </p>
                            <p className="mt-1 text-xs leading-5 text-red-700">
                                Versi tersimpan tetap utuh; perubahan lokal
                                hilang.
                            </p>
                        </div>
                    </div>

                    {saveError && (
                        <div
                            role="alert"
                            className="mx-6 border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950"
                        >
                            <p className="font-bold">Draf belum tersimpan</p>
                            <p className="mt-1 text-xs leading-5">
                                {saveError}
                            </p>
                        </div>
                    )}

                    <DialogFooter className="border-t border-border bg-slate-50 px-6 py-4 sm:justify-between">
                        <Button
                            type="button"
                            onClick={stayOnPage}
                            disabled={busy}
                        >
                            Tetap di halaman
                        </Button>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row">
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={continueNavigation}
                                disabled={busy}
                            >
                                <LogOut className="size-4" aria-hidden="true" />
                                Keluar tanpa perubahan lokal
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setSaveError(null);
                                    setSavingDraft(true);

                                    try {
                                        onSaveDraft(
                                            continueNavigation,
                                            reportSaveFailure,
                                        );
                                    } catch {
                                        reportSaveFailure();
                                    }
                                }}
                                disabled={busy}
                            >
                                <FileCheck2
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                {busy
                                    ? 'Menyimpan draf…'
                                    : 'Simpan draf lalu keluar'}
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
