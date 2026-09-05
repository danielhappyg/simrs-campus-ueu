import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { resolveMenuIcon } from '@/lib/simrs-menu-icon';
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
                <header className="flex flex-wrap items-end justify-between gap-3 border-b border-[#e2e8f0] pb-4">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight text-[#0f172a] md:text-2xl">
                            {selected?.label ?? categoryLabel}
                        </h1>
                        <p className="mt-1 text-sm text-[#64748b]">
                            {selected ? categoryLabel : `${menus.length} menus`}
                        </p>
                    </div>
                </header>

                <label className="relative grid max-w-md gap-1 text-xs font-medium tracking-wide text-[#64748b] uppercase">
                    Menu search
                    <span className="relative">
                        <Search
                            aria-hidden
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#94a3b8]"
                        />
                        <input
                            type="search"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search menus..."
                            className="min-h-11 w-full rounded-md border border-input bg-white pr-3 pl-10 text-sm font-normal tracking-normal text-[#0f172a] shadow-xs outline-none focus-visible:border-[#1b75bc] focus-visible:ring-[3px] focus-visible:ring-[#1b75bc]/30"
                        />
                    </span>
                </label>

                {filtered.length === 0 ? (
                    <p className="text-sm text-[#64748b]">
                        No menus match your search.
                    </p>
                ) : (
                    <ul
                        className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6"
                        aria-label={`${categoryLabel} menus`}
                    >
                        {filtered.map((menu) => {
                            const isSelected = selected?.slug === menu.slug;
                            const { icon: Icon, tone } = resolveMenuIcon(
                                menu.label,
                                menu.slug,
                            );

                            return (
                                <li key={menu.slug}>
                                    <Link
                                        href={menu.href}
                                        aria-current={
                                            isSelected ? 'page' : undefined
                                        }
                                        className={`group flex min-h-[8.75rem] flex-col items-stretch gap-3 rounded-2xl border bg-white px-3.5 py-4 transition-[transform,box-shadow,border-color,background-color] duration-200 ease-out hover:-translate-y-0.5 hover:border-[#1b75bc]/55 hover:bg-[#f8fbff] hover:shadow-[0_10px_24px_-16px_rgba(18,59,99,0.45)] focus-visible:ring-2 focus-visible:ring-[#1b75bc] focus-visible:outline-none ${
                                            isSelected
                                                ? 'border-[#1b75bc] shadow-[0_10px_24px_-16px_rgba(27,117,188,0.55)] ring-2 ring-[#1b75bc]/25'
                                                : 'border-[#e2e8f0] shadow-[0_1px_2px_rgba(15,23,42,0.04)]'
                                        }`}
                                    >
                                        <span
                                            className={`grid size-12 place-items-center rounded-2xl ${tone.plate} ${tone.ink} transition-transform duration-200 ease-out group-hover:scale-[1.04]`}
                                            aria-hidden
                                        >
                                            <Icon
                                                className="size-[1.35rem] stroke-[1.75]"
                                                absoluteStrokeWidth
                                            />
                                        </span>
                                        <span className="line-clamp-2 text-left text-[0.8125rem] leading-snug font-semibold text-[#0f172a]">
                                            {menu.label}
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}
                <p className="sr-only">Category: {category}</p>
            </div>
        </>
    );
}

ModulePlaceholder.layout = (props: Props) => {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/' },
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
