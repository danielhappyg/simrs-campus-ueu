<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak — SIMRS Campus UEU</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Plus Jakarta Sans", "Segoe UI", sans-serif;
            color: #0f172a;
            background: #e2e8f0;
        }
        .sheet {
            width: 210mm;
            min-height: 148mm;
            margin: 12px auto;
            padding: 16mm 14mm;
            background: #fff;
            border: 1px solid #cbd5e1;
            page-break-after: always;
        }
        h1 { font-size: 18px; margin: 0 0 4px; color: #123b63; }
        .sub { font-size: 12px; color: #64748b; margin-bottom: 14px; }
        dl { display: grid; grid-template-columns: 160px 1fr; gap: 6px 12px; margin: 0; font-size: 13px; }
        dt { color: #64748b; }
        dd { margin: 0; font-weight: 600; }
        .queue {
            font-size: 64px;
            font-family: "IBM Plex Mono", ui-monospace, monospace;
            text-align: center;
            color: #1b75bc;
            margin: 24px 0 8px;
        }
        .sep-box {
            border: 2px solid #123b63;
            padding: 12px;
            margin-top: 8px;
        }
        .wrist, .card {
            border: 2px dashed #1b75bc;
            padding: 16px;
            text-align: center;
        }
        .consent-hospital {
            font-size: 14px;
            font-weight: 700;
            color: #123b63;
        }
        .consent-section {
            font-size: 13px;
            margin: 14px 0 4px;
            color: #123b63;
        }
        .consent-form p {
            font-size: 12px;
            line-height: 1.45;
            margin: 0 0 8px;
        }
        .consent-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 28px;
        }
        .consent-sign-block {
            text-align: center;
            font-size: 12px;
        }
        .consent-signature {
            display: block;
            max-width: 100%;
            height: 72px;
            margin: 8px auto;
            object-fit: contain;
            border-bottom: 1px solid #cbd5e1;
        }
        .consent-signature-empty {
            margin: 28px 0 8px;
            color: #64748b;
        }
        .actions { text-align: center; margin: 16px; }
        button {
            background: #1b75bc;
            color: #fff;
            border: 0;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        @media print {
            body { background: #fff; }
            .actions { display: none; }
            .sheet { margin: 0; border: 0; width: auto; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Cetak / simpan PDF</button>
    </div>

    @php
        $patient = $encounter->patient;
        $queue = $encounter->queue_number !== null ? str_pad((string) $encounter->queue_number, 3, '0', STR_PAD_LEFT) : '—';
        $visit = $encounter->visit_date?->format('d/m/Y') ?? $encounter->registered_at->format('d/m/Y');
        $payer = $labels['payer'];
    @endphp

    @foreach ($documents as $document)
        <section class="sheet">
            @if ($document === 'bukti')
                <h1>Bukti pendaftaran</h1>
                <p class="sub">SIMRS Campus UEU · Universitas Esa Unggul · {{ $printedAt->format('d/m/Y H:i') }}</p>
                <dl>
                    <dt>No. antrian</dt><dd>{{ $queue }}</dd>
                    <dt>No. RM</dt><dd>{{ $patient?->medical_record_number }}</dd>
                    <dt>Nama</dt><dd>{{ $patient?->full_name }}</dd>
                    <dt>Jenis kelamin</dt><dd>{{ $labels['sex'] }}</dd>
                    <dt>Status pernikahan</dt><dd>{{ $labels['marital'] }}</dd>
                    <dt>Agama</dt><dd>{{ $labels['religion'] }}</dd>
                    <dt>NIK</dt><dd>{{ $patient?->nik ?? '—' }}</dd>
                    <dt>Wilayah</dt><dd>{{ $labels['wilayah'] }}</dd>
                    <dt>Kode wilayah</dt><dd style="font-family:monospace;font-size:11px">{{ $labels['wilayah_codes'] }}</dd>
                    <dt>Tanggal kunjungan</dt><dd>{{ $visit }}</dd>
                    <dt>Setting</dt><dd>{{ $labels['care_setting'] }}</dd>
                    <dt>Poli / unit</dt><dd>{{ $encounter->clinic_name }}</dd>
                    <dt>Dokter</dt><dd>{{ $encounter->doctor_name ?? '—' }}</dd>
                    <dt>Jadwal</dt><dd>{{ $encounter->schedule_label ?? '—' }}</dd>
                    <dt>Cara masuk</dt><dd>{{ $labels['admission'] }}</dd>
                    <dt>Asal kunjungan</dt><dd>{{ $labels['origin'] }}</dd>
                    <dt>Cara bayar</dt><dd>{{ $payer }}</dd>
                    <dt>No. asuransi</dt><dd>{{ $encounter->insurance_number ?? '—' }}</dd>
                    <dt>Kode booking</dt><dd>{{ $encounter->booking_code ?? '—' }}</dd>
                    <dt>Kode kunjungan</dt><dd style="font-family:monospace;font-size:11px">{{ $encounter->public_id }}</dd>
                </dl>
            @elseif ($document === 'antrian')
                <h1>Nomor antrian</h1>
                <p class="sub">{{ $encounter->clinic_name }} · {{ $visit }}</p>
                <div class="queue">{{ $queue }}</div>
                <p class="sub" style="text-align:center">{{ $patient?->full_name }} · {{ $patient?->medical_record_number }}</p>
            @elseif ($document === 'sep')
                <h1>Surat Eligibilitas Peserta (SEP)</h1>
                <p class="sub">SIMRS Campus UEU · {{ $printedAt->format('d/m/Y H:i') }}</p>
                <div class="sep-box">
                    <dl>
                        <dt>No. SEP</dt>
                        <dd>SIM-SEP-{{ $encounter->registered_at->format('ymd') }}-{{ $queue }}</dd>
                        <dt>Peserta</dt><dd>{{ $patient?->full_name }}</dd>
                        <dt>No. kartu</dt><dd>{{ $encounter->insurance_number ?: '—' }}</dd>
                        <dt>Tgl SEP</dt><dd>{{ $visit }}</dd>
                        <dt>Jenis pelayanan</dt><dd>{{ $labels['care_setting'] }}</dd>
                        <dt>Poli tujuan</dt><dd>{{ $encounter->clinic_name }}</dd>
                        <dt>DPJP</dt><dd>{{ $encounter->doctor_name ?? '—' }}</dd>
                        <dt>Diagnosa</dt><dd>—</dd>
                    </dl>
                </div>
            @elseif ($document === 'gelang')
                <h1>Gelang pasien</h1>
                <div class="wrist">
                    <strong>{{ $patient?->full_name }}</strong><br>
                    {{ $patient?->medical_record_number }} · {{ $patient?->date_of_birth?->format('d/m/Y') }}<br>
                    {{ $encounter->clinic_name }} · Antrian {{ $queue }}
                </div>
            @elseif ($document === 'kartu')
                <h1>Kartu pasien</h1>
                <div class="card">
                    <div class="sub">SIMRS Campus UEU</div>
                    <strong>{{ $patient?->full_name }}</strong>
                    <p>No. RM {{ $patient?->medical_record_number }}</p>
                </div>
            @elseif ($document === 'consent')
                @include('prints.partials.consent')
            @endif
        </section>
    @endforeach
</body>
</html>
