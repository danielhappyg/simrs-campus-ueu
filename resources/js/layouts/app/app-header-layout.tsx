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
            <AppHeader />
            {breadcrumbs.length > 0 && (
                <div className="flex h-11 shrink-0 items-center border-b border-border bg-white px-4 md:px-6">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            )}
            <AppContent variant="header" className="overflow-x-hidden">
                {children}
            </AppContent>
        </AppShell>
    );
}
