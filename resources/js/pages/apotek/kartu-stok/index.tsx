import { Head } from '@inertiajs/react';
import { PharmacyStockCard } from '@/components/clinical/pharmacy/pharmacy-stock-card';
import type { PharmacyStockCardProps } from '@/components/clinical/pharmacy/types';

export default function PharmacyStockCardPage(props: PharmacyStockCardProps) {
    return (
        <>
            <Head title="Kartu Stok Apotek" />
            <PharmacyStockCard {...props} />
        </>
    );
}
