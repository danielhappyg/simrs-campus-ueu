<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Cetak pengajaran — SIMRS Campus UEU</title>
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
        .banner {
            background: #fdeee3;
            color: #9a3412;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            text-align: center;
            padding: 6px 8px;
            margin-bottom: 12px;
            border: 1px solid #f26a1b;
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
        $payer = match ($encounter->payer_type) {
            'BPJS' => 'BPJS (simulasi)',
            'LAINNYA' => 'Lainnya',
            default => 'Umum',
        };
    @endphp

    @foreach ($documents as $document)
        <section class="sheet">
            <div class="banner">Dokumen pengajaran · bukan klaim / SEP BPJS asli · data sintetis</div>

            @if ($document === 'bukti')
                <h1>Bukti pendaftaran</h1>
                <p class="sub">SIMRS Campus UEU · Universitas Esa Unggul · {{ $printedAt->format('d/m/Y H:i') }}</p>
                <dl>
                    <dt>No. antrian</dt><dd>{{ $queue }}</dd>
                    <dt>No. RM</dt><dd>{{ $patient?->medical_record_number }}</dd>
                    <dt>Nama</dt><dd>{{ $patient?->full_name }}</dd>
                    <dt>NIK (sintetis)</dt><dd>{{ $patient?->nik ?? '—' }}</dd>
                    <dt>Tanggal kunjungan</dt><dd>{{ $visit }}</dd>
                    <dt>Poli / unit</dt><dd>{{ $encounter->clinic_name }}</dd>
                    <dt>Dokter</dt><dd>{{ $encounter->doctor_name ?? '—' }}</dd>
                    <dt>Jadwal</dt><dd>{{ $encounter->schedule_label ?? '—' }}</dd>
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
                <h1>SEP pengajaran (simulasi)</h1>
                <p class="sub">Bukan SEP BPJS Kesehatan. Tidak dikirim ke VClaim.</p>
                <div class="sep-box">
                    <dl>
                        <dt>No. SEP simulasi</dt>
                        <dd>SIM-SEP-{{ $encounter->registered_at->format('ymd') }}-{{ $queue }}</dd>
                        <dt>Peserta</dt><dd>{{ $patient?->full_name }}</dd>
                        <dt>No. kartu (ajar)</dt><dd>{{ $encounter->insurance_number ?: 'SYNTH-BPJS' }}</dd>
                        <dt>Tgl SEP</dt><dd>{{ $visit }}</dd>
                        <dt>Jenis pelayanan</dt><dd>Rawat jalan pengajaran</dd>
                        <dt>Poli tujuan</dt><dd>{{ $encounter->clinic_name }}</dd>
                        <dt>DPJP</dt><dd>{{ $encounter->doctor_name ?? '—' }}</dd>
                        <dt>Diagnosa</dt><dd>Tidak dikode — dokumen siluet pengajaran</dd>
                    </dl>
                </div>
            @elseif ($document === 'gelang')
                <h1>Gelang pasien (ajar)</h1>
                <div class="wrist">
                    <strong>{{ $patient?->full_name }}</strong><br>
                    {{ $patient?->medical_record_number }} · {{ $patient?->date_of_birth?->format('d/m/Y') }}<br>
                    {{ $encounter->clinic_name }} · Antrian {{ $queue }}
                </div>
            @elseif ($document === 'kartu')
                <h1>Kartu pasien (ajar)</h1>
                <div class="card">
                    <div class="sub">SIMRS Campus UEU</div>
                    <strong>{{ $patient?->full_name }}</strong>
                    <p>No. RM {{ $patient?->medical_record_number }}</p>
                    <p class="sub">Kartu sintetis — tidak berlaku di fasilitas lain</p>
                </div>
            @elseif ($document === 'consent')
                <h1>General consent (ajar)</h1>
                <p class="sub">Pernyataan pengajaran, bukan persetujuan klinis sah.</p>
                <p>Saya, <strong>{{ $patient?->responsible_party_name ?: $patient?->full_name }}</strong>, menyatakan data pada kunjungan ini adalah kasus sintetis untuk pembelajaran RMIK.</p>
                <p style="margin-top:48px">Tanda tangan: ______________________ &nbsp; Tanggal: {{ $visit }}</p>
            @endif
        </section>
    @endforeach
</body>
</html>
