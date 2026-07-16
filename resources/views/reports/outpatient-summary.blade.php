@extends('reports.layout')

@section('report-content')
    @php
        $formatDate = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Jakarta')->translatedFormat('d M Y')
            : '—';
        $formatDateTime = static fn (?string $value): string => $value
            ? \Carbon\CarbonImmutable::parse($value)->timezone('Asia/Jakarta')->translatedFormat('d M Y, H:i').' WIB'
            : '—';
        $text = static fn (mixed $value): string => is_string($value) && trim($value) !== '' ? $value : 'Belum didokumentasikan';
    @endphp

    <section aria-labelledby="identity-title">
        <div class="section-heading">
            <h2 id="identity-title">Identitas dan konteks encounter</h2>
            <span class="status-good">Sumber final simulasi</span>
        </div>
        <div class="identity-grid">
            <div class="field">
                <span class="field-label">Nama pasien</span>
                <span class="field-value">{{ $report['patient']['fullName'] }}</span>
            </div>
            <div class="field">
                <span class="field-label">Nomor rekam medis</span>
                <span class="field-value">{{ $report['patient']['mrn'] ?? '—' }}</span>
            </div>
            <div class="field">
                <span class="field-label">Tanggal lahir / usia saat kunjungan</span>
                <span class="field-value">
                    {{ $formatDate($report['patient']['birthDate']) }}
                    @if ($report['patient']['ageAtEncounter'] !== null)
                        · {{ $report['patient']['ageAtEncounter'] }} tahun
                    @endif
                </span>
            </div>
            <div class="field">
                <span class="field-label">Jenis kelamin administratif</span>
                <span class="field-value">{{ $report['patient']['administrativeSex'] }}</span>
            </div>
            <div class="field">
                <span class="field-label">Nomor encounter</span>
                <span class="field-value">{{ $report['encounter']['number'] }}</span>
            </div>
            <div class="field">
                <span class="field-label">Layanan / lokasi</span>
                <span class="field-value">{{ $report['encounter']['serviceType'] }} · {{ $report['encounter']['location'] }}</span>
            </div>
            <div class="field">
                <span class="field-label">Periode kunjungan</span>
                <span class="field-value">{{ $formatDateTime($report['encounter']['periodStart']) }} – {{ $formatDateTime($report['encounter']['periodEnd']) }}</span>
            </div>
            <div class="field">
                <span class="field-label">Sesi / status</span>
                <span class="field-value">{{ $report['encounter']['sessionCode'] }} · {{ $report['encounter']['status'] }}</span>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="sources-title">
        <div class="section-heading">
            <h2 id="sources-title">Provenance sumber yang diringkas</h2>
        </div>
        <div class="source-grid">
            @foreach ($report['sourceStatus'] as $source)
                <div class="source-card">
                    @if ($source)
                        <span class="meta-label">{{ $source['label'] }}</span>
                        <h3>Versi {{ $source['versionNumber'] }} · {{ $source['status'] }}</h3>
                        <p>{{ $source['author'] ?? 'Penulis tidak tersedia' }}<br />Waktu klinis {{ $formatDateTime($source['clinicalOccurrenceAt']) }}</p>
                        <span class="code-pill">{{ $source['publicId'] }}</span>
                    @else
                        <span class="meta-label">Sumber tidak tersedia</span>
                        <p class="empty">Tidak ada sumber final untuk bagian ini.</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="section" aria-labelledby="history-title">
        <div class="section-heading"><h2 id="history-title">Keluhan dan riwayat</h2></div>
        <div class="two-column">
            <div>
                <span class="field-label">Keluhan utama</span>
                <p class="narrative">{{ $text($report['sections']['history']['chiefComplaint']) }}</p>
            </div>
            <div>
                <span class="field-label">Durasi / onset</span>
                <p class="narrative">{{ $text($report['sections']['history']['onsetDuration']) }}</p>
            </div>
            <div>
                <span class="field-label">Riwayat penyakit sekarang</span>
                <p class="narrative">{{ $text($report['sections']['history']['presentIllness']) }}</p>
            </div>
            <div>
                <span class="field-label">Riwayat penyakit dahulu</span>
                <p class="narrative">{{ $text($report['sections']['history']['pastMedical']) }}</p>
            </div>
            <div>
                <span class="field-label">Riwayat keluarga</span>
                <p class="narrative">{{ $text($report['sections']['history']['family']) }}</p>
            </div>
            <div>
                <span class="field-label">Riwayat sosial</span>
                <p class="narrative">{{ $text($report['sections']['history']['social']) }}</p>
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="allergy-title">
        <div class="section-heading"><h2 id="allergy-title">Alergi dan obat sebelum kunjungan</h2></div>
        <div class="two-column">
            <div class="field">
                <span class="field-label">Status alergi</span>
                <span class="field-value">{{ $report['sections']['allergyAndMedicationHistory']['allergy']['state'] }}</span>
                @if ($report['sections']['allergyAndMedicationHistory']['allergy']['details'])
                    <p>{{ $report['sections']['allergyAndMedicationHistory']['allergy']['details'] }}</p>
                @endif
            </div>
            <div class="field">
                <span class="field-label">Obat yang sedang digunakan</span>
                <span class="field-value">{{ $report['sections']['allergyAndMedicationHistory']['currentMedication']['state'] }}</span>
                @if ($report['sections']['allergyAndMedicationHistory']['currentMedication']['details'])
                    <p>{{ $report['sections']['allergyAndMedicationHistory']['currentMedication']['details'] }}</p>
                @endif
            </div>
        </div>
    </section>

    <section class="section" aria-labelledby="examination-title">
        <div class="section-heading"><h2 id="examination-title">Pemeriksaan dan tanda vital</h2></div>
        <div class="three-column">
            @forelse ($report['sections']['examination']['vitals'] as $vital)
                <div class="field">
                    <span class="field-label">{{ $vital['label'] }}</span>
                    <span class="field-value">{{ $vital['value'] }} {{ $vital['unit'] }}</span><br />
                    <span class="code-pill">LOINC {{ $vital['code'] }}</span>
                </div>
            @empty
                <p class="empty">Tanda vital belum tersedia.</p>
            @endforelse
        </div>
        <div class="two-column" style="margin-top: 12px">
            <div>
                <span class="field-label">Kesadaran / keadaan umum</span>
                <p class="narrative">{{ $text($report['sections']['examination']['consciousness']) }} · {{ $text($report['sections']['examination']['general']) }}</p>
            </div>
            <div>
                <span class="field-label">Pemeriksaan terfokus</span>
                <p class="narrative">{{ $text($report['sections']['examination']['focused']) }}</p>
            </div>
        </div>
        <div style="margin-top: 12px">
            <span class="field-label">Ringkasan asesmen</span>
            <p class="narrative">{{ $text($report['sections']['examination']['assessmentSummary']) }}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="diagnoses-title">
        <div class="section-heading"><h2 id="diagnoses-title">Diagnosis dan koding yang ditinjau manusia</h2></div>
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Pernyataan klinisi</th><th>Peran / kepastian</th><th>Kode simulasi disetujui</th></tr>
                </thead>
                <tbody>
                    @forelse ($report['sections']['diagnoses'] as $diagnosis)
                        <tr>
                            <td>{{ $diagnosis['authoredText'] }}</td>
                            <td>{{ $diagnosis['role'] }} · {{ $diagnosis['certainty'] }}</td>
                            <td>
                                @if ($diagnosis['coding'])
                                    <span class="code-pill">{{ $diagnosis['coding']['system'] }} {{ $diagnosis['coding']['code'] }}</span><br />
                                    {{ $diagnosis['coding']['display'] }}<br />
                                    <small>{{ $diagnosis['coding']['version'] }} · ditinjau manusia</small>
                                @else
                                    <span class="empty">Belum ada kode final simulasi.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty">Diagnosis final tidak tersedia.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section" aria-labelledby="results-title">
        <div class="section-heading"><h2 id="results-title">Pesanan dan hasil sintetis</h2></div>
        @forelse ($report['sections']['results'] as $item)
            <div class="note-card">
                <div class="note-version latest">
                    <h3>{{ $item['service'] }}</h3>
                    @if ($item['result'])
                        <p><span class="status-good">{{ $item['result']['status'] }}</span> · {{ $item['result']['reportDisplay'] }} · {{ $formatDateTime($item['result']['effectiveAt']) }}</p>
                        <p class="narrative">{{ $text($item['result']['conclusion']) }}</p>
                        @if ($item['result']['components'] !== [])
                            <div class="table-scroll" style="margin-top: 10px">
                                <table>
                                    <thead><tr><th>Komponen</th><th>Nilai</th></tr></thead>
                                    <tbody>
                                        @foreach ($item['result']['components'] as $component)
                                            <tr>
                                                <td>{{ $component['display'] ?? $component['code'] ?? 'Komponen hasil' }}</td>
                                                <td>{{ $component['value'] ?? '—' }} {{ $component['unit'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @else
                        <p class="empty">Tidak ada hasil saat ini.</p>
                    @endif
                </div>
            </div>
        @empty
            <p class="empty">Tidak ada pesanan diagnostik pada encounter ini.</p>
        @endforelse
    </section>

    <section class="section" aria-labelledby="procedures-title">
        <div class="section-heading"><h2 id="procedures-title">Prosedur yang dilakukan</h2></div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Prosedur</th><th>Pelaksanaan</th><th>Outcome</th><th>Koding</th></tr></thead>
                <tbody>
                    @forelse ($report['sections']['procedures'] as $procedure)
                        <tr>
                            <td>{{ $procedure['authoredText'] }}<br /><small>{{ $procedure['bodySiteText'] ?? 'Lokasi tidak dinyatakan' }}</small></td>
                            <td>{{ $formatDateTime($procedure['performedStartAt']) }}<br />{{ $procedure['performerText'] }}</td>
                            <td>{{ $text($procedure['outcomeText']) }}</td>
                            <td>
                                @if ($procedure['coding'])
                                    <span class="code-pill">{{ $procedure['coding']['system'] }} {{ $procedure['coding']['code'] }}</span><br />
                                    {{ $procedure['coding']['display'] }}<br />
                                    <small>{{ $procedure['coding']['version'] }} · ditinjau manusia</small>
                                @else
                                    <span class="empty">Belum ada kode final simulasi.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">Tidak ada prosedur yang dilakukan dan disetujui.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section" aria-labelledby="medications-title">
        <div class="section-heading"><h2 id="medications-title">Obat dan outcome farmasi</h2></div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Obat</th><th>Aturan penggunaan</th><th>Indikasi</th><th>Outcome penyerahan</th></tr></thead>
                <tbody>
                    @forelse ($report['sections']['medications'] as $medication)
                        <tr>
                            <td><strong>{{ $medication['authoredMedication'] }}</strong><br />{{ $medication['form'] }} {{ $medication['strength'] }}</td>
                            <td>{{ $medication['dose'] }} · {{ $medication['route'] }} · {{ $medication['frequency'] }} · {{ $medication['duration'] }}<br /><small>{{ $medication['directions'] }}</small></td>
                            <td>{{ $medication['indicationText'] ?? '—' }}</td>
                            <td>
                                @if ($medication['dispense'])
                                    <span class="status-good">{{ $medication['dispense']['outcome'] }}</span><br />
                                    {{ $medication['dispense']['quantity'] }}
                                @else
                                    <span class="empty">Tidak ada catatan penyerahan.</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty">Tidak ada permintaan obat pada encounter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="section" aria-labelledby="closure-title">
        <div class="section-heading"><h2 id="closure-title">Rencana, edukasi, dan penutupan</h2></div>
        <div class="two-column">
            <div><span class="field-label">Rencana asuhan</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['carePlan']) }}</p></div>
            <div><span class="field-label">Kondisi saat meninggalkan layanan</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['leavingCondition']) }}</p></div>
            <div><span class="field-label">Disposisi</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['disposition']) }}</p></div>
            <div><span class="field-label">Tindak lanjut</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['followUpPlan'] ?? $report['sections']['planAndClosure']['medicalFollowUp']) }}</p></div>
            <div><span class="field-label">Rujukan</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['referralPlan']) }}</p></div>
            <div><span class="field-label">Edukasi / instruksi</span><p class="narrative">{{ $text($report['sections']['planAndClosure']['educationInstructions'] ?? $report['sections']['planAndClosure']['education']) }}</p></div>
        </div>
        <div style="margin-top: 12px">
            <span class="field-label">Ringkasan rawat jalan yang disetujui</span>
            <p class="narrative">{{ $text($report['sections']['planAndClosure']['outpatientSummary']) }}</p>
        </div>
    </section>

    <section class="section" aria-labelledby="generation-title">
        <div class="section-heading"><h2 id="generation-title">Metadata pratinjau</h2></div>
        <div class="two-column">
            <div class="field"><span class="field-label">Encounter difinalisasi</span><span class="field-value">{{ $formatDateTime($report['document']['finalizedAt']) }}</span></div>
            <div class="field"><span class="field-label">Pratinjau dihasilkan</span><span class="field-value">{{ $formatDateTime($report['document']['generatedAt']) }}</span></div>
        </div>
    </section>
@endsection
