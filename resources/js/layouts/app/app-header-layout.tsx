import { AppContent } from '@/components/app-content';
import { AppHeader } from '@/components/app-header';
import { AppShell } from '@/components/app-shell';
import { Breadcrumbs } from '@/components/breadcrumbs';
import type { AppLayoutProps } from '@/types';

export default function AppHeaderLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="header">
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-[#0d2b4a] focus:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-[#1b75bc]"
            >
                Loncat ke konten utama
            </a>
            <AppHeader />
            {breadcrumbs.length > 0 && (
                <div className="flex min-h-10 shrink-0 items-center border-b border-[#e2e8f0] bg-[#f8fafc] px-3 py-2 md:px-4 lg:px-5">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            )}
            <AppContent
                id="main-content"
                variant="header"
                className="overflow-x-hidden"
                tabIndex={-1}
            >
                {children}
            </AppContent>
        </AppShell>
    );
}
