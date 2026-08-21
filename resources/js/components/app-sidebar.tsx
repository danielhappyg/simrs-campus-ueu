import { Link } from '@inertiajs/react';
import { FlaskConical, Home, BookOpen } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

const foundationNavItems: NavItem[] = [
    {
        title: 'Beranda rebuild',
        href: '/',
        icon: Home,
    },
    {
        title: 'Profil',
        href: '/settings/profile',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={foundationNavItems} label="Fondasi rebuild" />
                <div className="mx-3 mt-auto mb-3 hidden rounded-md border border-white/15 bg-white/5 p-3 text-xs leading-relaxed text-sky-100 group-data-[collapsible=icon]:hidden md:block">
                    <FlaskConical
                        className="mb-2 size-4 text-[color:var(--signal)]"
                        aria-hidden="true"
                    />
                    Clean-slate Phase 0/2. Data wajib sintetis. Bukan MVP
                    outpatient lama dan bukan klon vendor.
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
