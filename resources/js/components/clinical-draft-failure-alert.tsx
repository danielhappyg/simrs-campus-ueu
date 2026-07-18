import { ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { DraftSaveFailure } from '@/lib/clinical-draft-recovery';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

type ClinicalDraftFailureAlertProps = {
    failure: DraftSaveFailure;
    className?: string;
};

export function ClinicalDraftFailureAlert({
    failure,
    className,
}: ClinicalDraftFailureAlertProps) {
    return (
        <div
            role="alert"
            className={cn(
                'border-l-4 border-red-600 bg-red-50 px-4 py-3 text-sm text-red-950',
                className,
            )}
        >
            <p className="font-bold">
                {failure === 'REAUTHENTICATION_REQUIRED'
                    ? 'Sesi masuk perlu dipulihkan'
                    : 'Draf belum tersimpan'}
            </p>
            {failure === 'REAUTHENTICATION_REQUIRED' ? (
                <>
                    <p className="mt-1 text-xs leading-5">
                        Perubahan tetap berada di memori tab ini. Buka halaman
                        masuk pada tab baru, masuk dengan akun yang sama, lalu
                        kembali dan coba simpan lagi. Server akan memeriksa
                        kembali peran, sesi, encounter, dan versi saat ini.
                    </p>
                    <Button
                        asChild
                        type="button"
                        variant="outline"
                        className="mt-3 border-red-300 bg-white text-red-900 hover:bg-red-100"
                    >
                        <a
                            href={login().url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <ExternalLink
                                className="size-4"
                                aria-hidden="true"
                            />
                            Buka halaman masuk di tab baru
                        </a>
                    </Button>
                </>
            ) : (
                <p className="mt-1 text-xs leading-5">
                    Periksa isian yang ditandai atau koneksi, lalu coba simpan
                    lagi. Anda tetap berada di encounter ini.
                </p>
            )}
        </div>
    );
}
