import { Head } from '@inertiajs/react';

type Props = {
    category: string;
    categoryLabel: string;
};

export default function ModulePlaceholder({
    category,
    categoryLabel,
}: Props) {
    return (
        <>
            <Head title={categoryLabel} />

            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col items-start justify-center gap-3 px-4 py-16 md:px-6">
                <p className="text-sm font-medium tracking-wide text-[#64748b] uppercase">
                    {categoryLabel}
                </p>
                <h1 className="text-3xl font-semibold tracking-tight text-[#0f172a]">
                    Modul belum diaktifkan
                </h1>
                <p className="max-w-xl text-base leading-relaxed text-[#64748b]">
                    Kategori {categoryLabel} belum memiliki modul aktif pada
                    fase ini. Modul akan ditambahkan sesuai program parity.
                </p>
                <p className="sr-only">Kategori: {category}</p>
            </div>
        </>
    );
}

ModulePlaceholder.layout = (props: Props) => ({
    breadcrumbs: [
        {
            title: 'Beranda',
            href: '/',
        },
        {
            title: props.categoryLabel,
            href: `/modul/${props.category}`,
        },
    ],
});
