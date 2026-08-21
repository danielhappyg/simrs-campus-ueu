import { Link } from '@inertiajs/react';
import {
    ClipboardList,
    FileText,
    FlaskConical,
    LayoutDashboard,
    Pill,
    Receipt,
    Stethoscope,
    UserRoundPlus,
    Wallet,
    Building2,
} from 'lucide-react';
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

const moduleNavItems: NavItem[] = [
    {
        title: 'Meja kerja',
        href: '/desk',
        icon: LayoutDashboard,
    },
    {
        title: 'Pendaftaran',
        href: '/desk/pendaftaran',
        icon: UserRoundPlus,
    },
    {
        title: 'Pemeriksaan',
        href: '/desk/pemeriksaan',
        icon: Stethoscope,
    },
    {
        title: 'Rekam Medis',
        href: '/desk/rekam-medis',
        icon: FileText,
    },
    {
        title: 'Apotek',
        href: '/desk/apotek',
        icon: Pill,
    },
    {
        title: 'Klaim',
        href: '/desk/klaim',
        icon: Receipt,
    },
    {
        title: 'Laporan',
        href: '/desk/laporan',
        icon: ClipboardList,
    },
    {
        title: 'BPJS',
        href: '/desk/bpjs',
        icon: Building2,
    },
    {
        title: 'Kasir',
        href: '/desk/kasir',
        icon: Wallet,
    },
];

const secondaryNavItems: NavItem[] = [
    {
        title: 'Kerja saya',
        href: '/work',
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
                            <Link href="/desk" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={moduleNavItems} label="Modul rumah sakit" />
                <NavMain items={secondaryNavItems} label="Tugas saya" />
                <div className="mx-3 mt-auto mb-3 hidden rounded-md border border-white/15 bg-white/5 p-3 text-xs leading-relaxed text-sky-100 group-data-[collapsible=icon]:hidden md:block">
                    <FlaskConical
                        className="mb-2 size-4 text-[color:var(--signal)]"
                        aria-hidden="true"
                    />
                    Data pada platform ini wajib sintetis dan hanya untuk
                    pembelajaran. Tidak ada BPJS produksi.
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
