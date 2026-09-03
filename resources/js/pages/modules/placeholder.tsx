import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import type { BreadcrumbItem } from '@/types';

type MenuItem = {
    slug: string;
    label: string;
    vendor_route: string;
    status: 'live' | 'consolidated' | 'visual';
    href: string;
};

type Props = {
    category: string;
    categoryLabel: string;
    menus: MenuItem[];
    selected: MenuItem | null;
};

const tileAccents = [
    'bg-[#123b63]',
    'bg-[#1b75bc]',
    'bg-[#0f766e]',
    'bg-[#c2410c]',
    'bg-[#3d7cb2]',
    'bg-[#0d2b4a]',
] as const;

function statusLabel(status: MenuItem['status']): string {
    switch (status) {
        case 'live':
            return 'Bisa dipakai';
        case 'consolidated':
            return 'Mengikuti menu terkait';
        case 'visual':
            return 'Menu saja';
        default: {
            const exhaustive: never = status;

            return exhaustive;
        }
    }
}

export default function ModulePlaceholder({
    category,
    categoryLabel,
    menus,
    selected,
}: Props) {
    const [query, setQuery] = useState('');
    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') {
            return menus;
        }

        return menus.filter(
            (menu) =>
                menu.label.toLowerCase().includes(needle) ||
                menu.vendor_route.toLowerCase().includes(needle),
        );
    }, [menus, query]);

    return (
        <>
            <Head
                title={
                    selected
                        ? `${selected.label} · ${categoryLabel}`
                        : categoryLabel
                }
            />

            <div className="mx-auto flex w-full max-w-[1400px] flex-1 flex-col gap-5 px-3 py-5 md:px-5">
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-[0.68rem] font-semibold tracking-[0.12em] text-[#1b75bc] uppercase">
                            {categoryLabel}
                        </p>
                        <h1 className="mt-1 text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            Pilih menu
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-[#64748b]">
                            Susunan menu mengikuti SIMRS Sahabat. Menu bertanda
                            “Bisa dipakai” membuka fungsi yang sudah ada. Menu
                            lain ditampilkan visual saja — fungsinya belum
                            diimplementasikan.
                        </p>
                    </div>
                    <p className="text-xs text-[#64748b]">
                        {menus.length} menu
                    </p>
                </header>

                {selected ? (
                    <div
                        role="status"
                        className="rounded-xl border border-[#fed7aa] bg-[#fff7ed] px-4 py-3 text-sm text-[#9a3412]"
                    >
                        <p className="font-semibold">{selected.label}</p>
                        <p className="mt-1">
                            Menu ini sudah dicatat dari Sahabat. Fungsi
                            operasional belum diimplementasikan. Tidak ada data
                            yang bisa diubah di sini.
                        </p>
                    </div>
                ) : null}

                <label className="grid max-w-md gap-1 text-xs font-medium tracking-wide text-[#64748b] uppercase">
                    Pencarian menu
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Pencarian Menu..."
                        className="border-input min-h-11 rounded-md border bg-white px-3 text-sm font-normal tracking-normal text-[#0f172a] shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30"
                    />
                </label>

                {filtered.length === 0 ? (
                    <p className="text-sm text-[#64748b]">
                        Tidak ada menu yang cocok dengan pencarian.
                    </p>
                ) : (
                    <ul
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
                        aria-label={`Menu ${categoryLabel}`}
                    >
                        {filtered.map((menu, index) => {
                            const isSelected = selected?.slug === menu.slug;

                            return (
                                <li key={menu.slug}>
                                    <Link
                                        href={menu.href}
                                        aria-current={
                                            isSelected ? 'page' : undefined
                                        }
                                        className={`flex min-h-[9.5rem] flex-col items-center justify-center gap-3 rounded-xl border bg-white px-3 py-4 text-center shadow-sm transition-colors hover:border-[#1b75bc] hover:bg-[#f8fbff] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none ${
                                            isSelected
                                                ? 'border-[#1b75bc] ring-2 ring-[#1b75bc]/30'
                                                : 'border-[#e2e8f0]'
                                        }`}
                                    >
                                        <span
                                            className={`grid size-14 place-items-center rounded-full text-lg font-semibold text-white ${tileAccents[index % tileAccents.length]}`}
                                            aria-hidden
                                        >
                                            {menu.label.slice(0, 1)}
                                        </span>
                                        <span className="text-sm font-medium text-[#0f172a]">
                                            {menu.label}
                                        </span>
                                        <span className="text-[0.7rem] text-[#64748b]">
                                            {statusLabel(menu.status)}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <p className="sr-only">Kategori: {category}</p>
            </div>
        </>
    );
}

ModulePlaceholder.layout = (props: Props) => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Beranda', href: '/' },
        {
            title: props.categoryLabel,
            href: `/modul/${props.category}`,
        },
    ];

    if (props.selected) {
        breadcrumbs.push({
            title: props.selected.label,
            href: props.selected.href,
        });
    }

    return { breadcrumbs };
};
