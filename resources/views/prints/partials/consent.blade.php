@php
    use App\Support\Registration\EncounterConsent;

    $consent = $encounter->consent;
    $patientName = $patient?->full_name;
    $guardianName = $patient?->responsible_party_name ?: $patientName;
@endphp

<div class="consent-form">
    <div class="consent-hospital">{{ EncounterConsent::HOSPITAL_NAME }}</div>
    <div class="sub">{{ EncounterConsent::HOSPITAL_ADDRESS }}</div>
    <h1>{{ EncounterConsent::FORM_TITLE }}</h1>
    <p class="sub">{{ EncounterConsent::FORM_SUBTITLE }}</p>

    <p>Yang bertanda tangan di bawah ini:</p>
    <dl>
        <dt>Nama</dt><dd>{{ $guardianName }}</dd>
        <dt>Alamat</dt><dd>{{ $patient?->address_line ?: '—' }}</dd>
        <dt>No. Telp</dt><dd>{{ $patient?->phone ?: '—' }}</dd>
        <dt>No. RM</dt><dd>{{ $patient?->medical_record_number }}</dd>
        <dt>Pasien</dt><dd>{{ $patientName }}</dd>
        <dt>Tanggal kunjungan</dt><dd>{{ $visit }}</dd>
        <dt>Poli / unit</dt><dd>{{ $encounter->clinic_name }}</dd>
    </dl>

    <p>Selaku pasien/wali hukum {{ EncounterConsent::HOSPITAL_NAME }}, dengan ini menyatakan persetujuan:</p>
    <p>{{ EncounterConsent::INTRO }}</p>

    @foreach (EncounterConsent::clauses() as $clause)
        <h2 class="consent-section">{{ $clause['title'] }}</h2>
        <p>{{ $clause['body'] }}</p>
    @endforeach

    <div class="consent-signatures">
        <div class="consent-sign-block">
            <div class="sub">Yang menjelaskan</div>
            @if ($consent?->explainer_signature_png)
                <img class="consent-signature" src="{{ $consent->explainer_signature_png }}" alt="Tanda tangan yang menjelaskan">
            @else
                <div class="consent-signature-empty">______________________</div>
            @endif
            <div><strong>{{ $consent?->explainer_name ?: '______________________' }}</strong></div>
        </div>
        <div class="consent-sign-block">
            <div class="sub">Pasien / penanggung jawab</div>
            @if ($consent?->patient_signature_png)
                <img class="consent-signature" src="{{ $consent->patient_signature_png }}" alt="Tanda tangan pasien atau penanggung jawab">
            @else
                <div class="consent-signature-empty">______________________</div>
            @endif
            <div><strong>{{ $consent?->patient_or_guardian_name ?: ($guardianName ?: '______________________') }}</strong></div>
        </div>
    </div>
    <p class="sub">Tanggal: {{ $consent?->signed_at?->format('d/m/Y H:i') ?? $visit }}</p>
</div>
