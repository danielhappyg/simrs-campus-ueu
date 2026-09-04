import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Ambulance,
    Archive,
    Banknote,
    BedDouble,
    BookOpen,
    Building2,
    CalendarClock,
    ClipboardList,
    ClipboardPlus,
    FileCheck2,
    FileClock,
    FileSearch,
    FileText,
    FlaskConical,
    FolderOpen,
    HeartPulse,
    Hospital,
    Languages,
    LayoutGrid,
    Monitor,
    Package,
    Pill,
    Printer,
    Receipt,
    ScanLine,
    Settings,
    ShieldCheck,
    Stethoscope,
    Syringe,
    Thermometer,
    Truck,
    UserRound,
    Users,
    Utensils,
    Wallet,
    Warehouse,
} from 'lucide-react';

export type MenuIconTone = {
    plate: string;
    ink: string;
};

const tones = {
    navy: { plate: 'bg-[#e8eef5]', ink: 'text-[#123b63]' },
    blue: { plate: 'bg-[#e8f3fb]', ink: 'text-[#1b75bc]' },
    sky: { plate: 'bg-[#eaf4fa]', ink: 'text-[#3d7cb2]' },
    teal: { plate: 'bg-[#e7f5f3]', ink: 'text-[#0f766e]' },
    orange: { plate: 'bg-[#fdeee3]', ink: 'text-[#c2410c]' },
    slate: { plate: 'bg-[#f1f5f9]', ink: 'text-[#475569]' },
} as const satisfies Record<string, MenuIconTone>;

type Rule = {
    match: RegExp;
    icon: LucideIcon;
    tone: MenuIconTone;
};

const rules: Rule[] = [
    {
        match: /\b(igd|ugd|triage|emergency)\b/,
        icon: HeartPulse,
        tone: tones.orange,
    },
    {
        match: /\b(ambulance|lakalantas)\b/,
        icon: Ambulance,
        tone: tones.orange,
    },
    {
        match: /\b(rawat\s*inap|ranap|bangsal|kelas|tt|bed|dirawat)\b/,
        icon: BedDouble,
        tone: tones.navy,
    },
    {
        match: /\b(rawat\s*jalan|rajal|poliklinik|unit\s*&\s*poliklinik)\b/,
        icon: Stethoscope,
        tone: tones.blue,
    },
    {
        match: /\b(assesmen|asesmen|soap|cppt|emr|ipp|odontogram|diag\s*keperawatan)\b/,
        icon: ClipboardPlus,
        tone: tones.teal,
    },
    {
        match: /\b(laboratorium|lab\b|specimen|flask)\b/,
        icon: FlaskConical,
        tone: tones.teal,
    },
    {
        match: /\b(radiologi|rontgen|imaging)\b/,
        icon: ScanLine,
        tone: tones.sky,
    },
    {
        match: /\b(apotek|obat|resep|farmasi|depo|stock|stok|perpetual|retur|pemesanan|buffer)\b/,
        icon: Pill,
        tone: tones.teal,
    },
    { match: /\b(gizi|utensils)\b/, icon: Utensils, tone: tones.orange },
    { match: /\b(operasi|ibs|instrumen)\b/, icon: Syringe, tone: tones.orange },
    {
        match: /\b(rehabilitasi|fisioterapi|terapi|okupasi)\b/,
        icon: Activity,
        tone: tones.sky,
    },
    {
        match: /\b(bank\s*darah|darah|blood)\b/,
        icon: HeartPulse,
        tone: tones.orange,
    },
    { match: /\b(jenazah)\b/, icon: FileText, tone: tones.slate },
    { match: /\b(imunisasi|vaksin)\b/, icon: Syringe, tone: tones.blue },
    {
        match: /\b(filing|berkas|register\s*filing)\b/,
        icon: FolderOpen,
        tone: tones.navy,
    },
    {
        match: /\b(klaim|idrg|sep|bpjs|bridging|satusehat)\b/,
        icon: ShieldCheck,
        tone: tones.blue,
    },
    {
        match: /\b(monitor\s*klaim|monitor\s*resep)\b/,
        icon: Monitor,
        tone: tones.blue,
    },
    {
        match: /\b(tarif|komponen\s*biaya|cara\s*bayar|diskon|tagihan|pendapatan|pengeluaran|setoran|piutang|jasa\s*medis|kasir|kuitansi)\b/,
        icon: Receipt,
        tone: tones.navy,
    },
    { match: /\b(bank)\b/, icon: Banknote, tone: tones.navy },
    { match: /\b(wallet|pembayaran)\b/, icon: Wallet, tone: tones.navy },
    {
        match: /\b(pasien|pengguna|staff|dokter|perujuk|pekerjaan|pendidikan|suku|bahasa)\b/,
        icon: UserRound,
        tone: tones.blue,
    },
    { match: /\b(group|users|menu)\b/, icon: Users, tone: tones.slate },
    {
        match: /\b(settings|printer|service|template|w2)\b/,
        icon: Settings,
        tone: tones.slate,
    },
    {
        match: /\b(supplier|warehouse|gudang|distribusi|trolley|kartu\s*stock)\b/,
        icon: Warehouse,
        tone: tones.teal,
    },
    {
        match: /\b(alat\s*medis|paket\s*obat)\b/,
        icon: Package,
        tone: tones.teal,
    },
    { match: /\b(display|admisi|antrian)\b/, icon: Monitor, tone: tones.sky },
    {
        match: /\b(laporan|rekap|rl\s*\d|trend|10\s*besar|statistik|indikasi|kunjungan|penyakit|kematian|ptm|stp)\b/,
        icon: ClipboardList,
        tone: tones.navy,
    },
    {
        match: /\b(log\b|esign|activity|manual\s*book)\b/,
        icon: FileClock,
        tone: tones.slate,
    },
    {
        match: /\b(clinical\s*pathway|pathway|target)\b/,
        icon: FileCheck2,
        tone: tones.blue,
    },
    { match: /\b(temperature|suhu)\b/, icon: Thermometer, tone: tones.orange },
    { match: /\b(languages|bahasa)\b/, icon: Languages, tone: tones.sky },
    { match: /\b(hospital|rumah\s*sakit)\b/, icon: Hospital, tone: tones.navy },
    { match: /\b(building|gedung)\b/, icon: Building2, tone: tones.navy },
    {
        match: /\b(calendar|jadwal|waktu)\b/,
        icon: CalendarClock,
        tone: tones.blue,
    },
    { match: /\b(archive|arsip)\b/, icon: Archive, tone: tones.slate },
    { match: /\b(search|cari)\b/, icon: FileSearch, tone: tones.sky },
    { match: /\b(book|buku)\b/, icon: BookOpen, tone: tones.blue },
    { match: /\b(printer)\b/, icon: Printer, tone: tones.slate },
    { match: /\b(truck|kirim)\b/, icon: Truck, tone: tones.teal },
    { match: /\b(layout|grid)\b/, icon: LayoutGrid, tone: tones.slate },
];

const fallback: Rule = {
    match: /.*/,
    icon: LayoutGrid,
    tone: tones.slate,
};

export function resolveMenuIcon(
    label: string,
    slug = '',
): {
    icon: LucideIcon;
    tone: MenuIconTone;
} {
    const haystack = `${label} ${slug}`.toLowerCase().replace(/[_/.-]+/g, ' ');

    for (const rule of rules) {
        if (rule.match.test(haystack)) {
            return { icon: rule.icon, tone: rule.tone };
        }
    }

    return { icon: fallback.icon, tone: fallback.tone };
}
