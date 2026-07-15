import { Link } from '@inertiajs/react';
import { ClipboardList, FlaskConical } from 'lucide-react';
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
import { work } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Antrean kerja',
        href: work(),
        icon: ClipboardList,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={work()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                <div className="mx-3 mt-auto mb-3 hidden rounded-md border border-white/15 bg-white/5 p-3 text-xs leading-relaxed text-sky-100 group-data-[collapsible=icon]:hidden md:block">
                    <FlaskConical
                        className="mb-2 size-4 text-orange-300"
                        aria-hidden="true"
                    />
                    Data pada platform ini wajib sintetis dan hanya untuk
                    pembelajaran.
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
