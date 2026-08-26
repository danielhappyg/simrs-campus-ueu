import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export type OperationalPaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    pagination: OperationalPaginationMeta | null | undefined;
    itemLabel: string;
    className?: string;
};

const linkClass =
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-[#c5d9eb] bg-white px-3 text-xs font-medium text-[#123b63] hover:bg-[#f5f9fc] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:ring-offset-2';

const disabledClass =
    'inline-flex min-h-11 min-w-11 cursor-not-allowed items-center justify-center rounded-md border border-[#e2e8f0] bg-[#f8fafc] px-3 text-xs font-medium text-[#94a3b8]';

export function OperationalPagination({
    pagination,
    itemLabel,
    className,
}: Props) {
    if (!pagination || pagination.total === 0) {
        return null;
    }

    return (
        <nav
            aria-label={`Navigasi halaman ${itemLabel}`}
            className={cn(
                'flex flex-wrap items-center justify-between gap-2 border-t border-[#e2e8f0] pt-3',
                className,
            )}
        >
            <p className="text-xs text-[#64748b]" aria-live="polite">
                Menampilkan {pagination.from ?? 0}–{pagination.to ?? 0} dari{' '}
                {pagination.total} {itemLabel}. Halaman{' '}
                {pagination.current_page} dari {pagination.last_page}.
            </p>

            <div className="flex items-center gap-2">
                {pagination.prev_page_url ? (
                    <Link
                        href={pagination.prev_page_url}
                        preserveScroll
                        preserveState
                        className={linkClass}
                    >
                        Sebelumnya
                    </Link>
                ) : (
                    <span aria-disabled="true" className={disabledClass}>
                        Sebelumnya
                    </span>
                )}

                {pagination.next_page_url ? (
                    <Link
                        href={pagination.next_page_url}
                        preserveScroll
                        preserveState
                        className={linkClass}
                    >
                        Berikutnya
                    </Link>
                ) : (
                    <span aria-disabled="true" className={disabledClass}>
                        Berikutnya
                    </span>
                )}
            </div>
        </nav>
    );
}
