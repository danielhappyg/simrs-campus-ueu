@extends('reports.layout')

@section('report-content')
    @php
        $formatDate = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Jakarta')->translatedFormat('d M Y')
            : '—';
        $formatDateTime = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i').' WIB'
            : '—';
    @endphp

    <section aria-labelledby="identity-title">
        <div class="section-heading">
            <h2 id="identity-title">Konteks bukti pembelajaran</h2>
            <span class="status-good">Dirilis setelah finalisasi</span>
        </div>
        <div class="identity-grid">
            <div class="field"><span class="field-label">Nama pasien sintetis</span><span class="field-value">{{ $report['patient']['fullName'] }}</span></div>
            <div class="field"><span class="field-label">Nomor rekam medis</span><span class="field-value">{{ $report['patient']['mrn'] ?? '—' }}</span></div>
            <div class="field"><span class="field-label">Nomor encounter</span><span class="field-value">{{ $report['encounter']['number'] }}</span></div>
            <div class="field"><span class="field-label">Sesi simulasi</span><span class="field-value">{{ $report['session']['code'] }}</span></div>
            <div class="field"><span class="field-label">Skenario / versi</span><span class="field-value">{{ $report['session']['scenarioTitle'] }} · v{{ $report['session']['scenarioVersion'] }}</span></div>
            <div class="field"><span class="field-label">Lokasi</span><span class="field-value">{{ $report['encounter']['location'] }}</span></div>
            <div class="field"><span class="field-label">Periode</span><span class="field-value">{{ $formatDateTime($report['encounter']['periodStart']) }} – {{ $formatDateTime($report['encounter']['periodEnd']) }}</span></div>
            <div class="field"><span class="field-label">Finalisasi</span><span class="field-value">{{ $formatDateTime($report['document']['finalizedAt']) }}</span></div>
        </div>
    </section>

    <section class="section" aria-labelledby="outcomes-title">
        <div class="section-heading"><h2 id="outcomes-title">Tujuan pembelajaran skenario</h2></div>
        <ol>
            @forelse ($report['session']['learningOutcomes'] as $outcome)
                <li>{{ $outcome }}</li>
            @empty
                <li class="empty">Tujuan pembelajaran belum dikonfigurasi.</li>
            @endforelse
        </ol>
    </section>

    <section class="section" aria-labelledby="summary-title">
        <div class="section-heading"><h2 id="summary-title">Ringkasan bukti yang diproyeksikan</h2></div>
        <div class="stats-grid">
            <div class="stat-card"><span class="field-label">Peristiwa sumber</span><span class="field-value">{{ $report['timeline']['summary']['displayedEventCount'] }}</span></div>
            <div class="stat-card"><span class="field-label">Koreksi</span><span class="field-value">{{ $report['timeline']['summary']['correctionCount'] }}</span></div>
            <div class="stat-card"><span class="field-label">Supervisi</span><span class="field-value">{{ $report['timeline']['summary']['supervisionCount'] }}</span></div>
            <div class="stat-card"><span class="field-label">Handoff</span><span class="field-value">{{ $report['timeline']['summary']['handoffCount'] }}</span></div>
            <div class="stat-card"><span class="field-label">Catatan bersama</span><span class="field-value">{{ $report['sourceCounts']['logicalNotes'] }}</span></div>
            <div class="stat-card"><span class="field-label">Versi catatan</span><span class="field-value">{{ $report['sourceCounts']['noteVersions'] }}</span></div>
        </div>
        @if ($report['timeline']['summary']['truncated'])
            <p class="notice"><strong>Proyeksi dibatasi</strong>Menampilkan {{ $report['timeline']['summary']['displayedEventCount'] }} dari {{ $report['timeline']['summary']['totalAvailableEventCount'] }} peristiwa yang tersedia.</p>
        @endif
    </section>

    <section class="section" aria-labelledby="timeline-title">
        <div class="section-heading">
            <h2 id="timeline-title">Linimasa lintas profesi</h2>
            <span class="status-good">Kronologis · terkurasi</span>
        </div>
        <ol class="timeline">
            @forelse ($report['timeline']['events'] as $event)
                <li>
                    <h3>{{ $event['sequence'] }}. {{ $event['category']['label'] }} · {{ $event['title'] }}</h3>
                    @if ($event['detail'])
                        <p>{{ $event['detail'] }}</p>
                    @endif
                    <p class="timeline-meta">
                        {{ $event['actor']['name'] }} · {{ $event['actor']['program'] }} / {{ $event['actor']['role'] }} ·
                        <time datetime="{{ $event['primaryAt'] }}">{{ $formatDateTime($event['primaryAt']) }}</time>
                    </p>
                    @if ($event['showsRecordedTimeDifference'])
                        <p class="timeline-meta">Dicatat pada {{ $formatDateTime($event['recordedAt']) }}; berbeda dari waktu kejadian klinis.</p>
                    @endif
                    <p class="timeline-meta">Sumber: {{ $event['source']['label'] }} {{ $event['source']['version'] ?? '' }}</p>
                    @foreach ($event['tags'] as $tag)
                        <span class="tag">{{ $tag['label'] }}</span>
                    @endforeach
                </li>
            @empty
                <li class="empty">Belum ada peristiwa material untuk diproyeksikan.</li>
            @endforelse
        </ol>
    </section>

    <section class="section" aria-labelledby="notes-title">
        <div class="section-heading">
            <h2 id="notes-title">Catatan debrief bersama dan riwayat versi</h2>
            <span class="status-warning">Bukti pengajaran · bukan catatan klinis</span>
        </div>
        @forelse ($report['notes'] as $note)
            <article class="note-card">
                <div class="note-version">
                    <span class="field-label">{{ $note['type'] }}</span>
                    <h3>Catatan {{ $loop->iteration }} · {{ count($note['versions']) }} versi</h3>
                </div>
                @foreach (array_reverse($note['versions']) as $version)
                    <div class="note-version {{ $loop->first ? 'latest' : '' }}">
                        <div class="section-heading">
                            <h3>Versi {{ $version['versionNumber'] }} {{ $loop->first ? '· terbaru' : '' }}</h3>
                            <span>{{ $formatDateTime($version['authoredAt']) }}</span>
                        </div>
                        <p class="narrative">{{ $version['body'] }}</p>
                        <p class="timeline-meta">{{ $version['author']['name'] }} · {{ $version['author']['program'] }} / {{ $version['author']['role'] }}</p>
                        @if ($version['changeReason'])
                            <p><strong>Alasan perubahan:</strong> {{ $version['changeReason'] }}</p>
                        @endif
                    </div>
                @endforeach
            </article>
        @empty
            <p class="empty">Belum ada catatan debrief bersama.</p>
        @endforelse
    </section>

    <section class="section" aria-labelledby="rubric-title">
        <div class="section-heading">
            <h2 id="rubric-title">Referensi rubrik</h2>
            <span class="status-warning">Non-scoring</span>
        </div>
        <p>Referensi berikut tidak memuat skor, bobot, nilai, ambang lulus, atau keputusan kompetensi otomatis.</p>
        @forelse ($report['rubricReferences'] as $rubric)
            <article class="source-card" style="margin-top: 12px">
                <span class="field-label">{{ $rubric['code'] }} · {{ $rubric['version'] }}</span>
                <h3>{{ $rubric['title'] }}</h3>
                <p><span class="status-warning">{{ $rubric['status'] }}</span> · {{ $rubric['sourceLabel'] }}</p>
                @if ($rubric['learningOutcomes'] !== [])
                    <p><strong>Tujuan tertaut:</strong></p>
                    <ul>
                        @foreach ($rubric['learningOutcomes'] as $outcome)
                            <li>{{ $outcome['number'] }}. {{ $outcome['label'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <p class="empty">Belum ada referensi rubrik pada skenario ini.</p>
        @endforelse
    </section>

    <section class="section" aria-labelledby="generation-title">
        <div class="section-heading"><h2 id="generation-title">Metadata pratinjau</h2></div>
        <div class="two-column">
            <div class="field"><span class="field-label">Pratinjau dihasilkan</span><span class="field-value">{{ $formatDateTime($report['document']['generatedAt']) }}</span></div>
            <div class="field"><span class="field-label">Klasifikasi</span><span class="field-value">{{ $report['document']['classification'] }}</span></div>
        </div>
    </section>
@endsection
