<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="robots" content="noindex,nofollow" />
        <title>{{ $report['document']['title'] }} · SIMULASI</title>
        <style>
            :root {
                color-scheme: light;
                --canvas: #f1f5f9;
                --surface: #ffffff;
                --surface-subtle: #f8fafc;
                --text: #0f172a;
                --muted: #475569;
                --border: #cbd5e1;
                --primary: #00639f;
                --primary-soft: #eaf4f8;
                --accent: #f05828;
                --simulation-bg: #fff7ed;
                --simulation-text: #7c2d12;
                --success-bg: #f0fdf4;
                --success-text: #166534;
                --warning-bg: #fffbeb;
                --warning-text: #92400e;
            }

            * {
                box-sizing: border-box;
            }

            html {
                background: var(--canvas);
                color: var(--text);
                font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                font-variant-numeric: tabular-nums;
            }

            body {
                margin: 0;
                min-width: 320px;
            }

            a {
                color: var(--primary);
            }

            .screen-tools {
                align-items: center;
                background: #0f172a;
                display: flex;
                gap: 12px;
                justify-content: space-between;
                padding: 12px max(16px, calc((100vw - 1040px) / 2));
            }

            .screen-tools a,
            .screen-tools button {
                align-items: center;
                background: #ffffff;
                border: 1px solid #ffffff;
                border-radius: 8px;
                color: #0f172a;
                cursor: pointer;
                display: inline-flex;
                font: inherit;
                font-size: 14px;
                font-weight: 700;
                justify-content: center;
                min-height: 44px;
                padding: 10px 16px;
                text-decoration: none;
            }

            .screen-tools button {
                background: var(--accent);
                border-color: var(--accent);
                color: #ffffff;
            }

            .report-shell {
                margin: 28px auto;
                max-width: 1040px;
                padding: 0 16px;
                position: relative;
            }

            .report-page {
                background: var(--surface);
                border: 1px solid var(--border);
                border-radius: 12px;
                box-shadow: 0 18px 45px rgba(15, 23, 42, 0.1);
                overflow: hidden;
                position: relative;
            }

            .watermark {
                color: rgba(240, 88, 40, 0.065);
                font-size: clamp(36px, 8vw, 88px);
                font-weight: 900;
                left: 50%;
                letter-spacing: 0.08em;
                pointer-events: none;
                position: fixed;
                text-align: center;
                top: 48%;
                transform: translate(-50%, -50%) rotate(-24deg);
                white-space: nowrap;
                z-index: 0;
            }

            .report-header,
            main,
            .report-footer {
                position: relative;
                z-index: 1;
            }

            .report-header {
                border-bottom: 4px solid var(--primary);
                padding: 28px 32px 24px;
            }

            .brand-row {
                align-items: flex-start;
                display: flex;
                gap: 16px;
                justify-content: space-between;
            }

            .brand {
                align-items: center;
                display: flex;
                gap: 12px;
            }

            .brand img {
                height: 48px;
                width: 48px;
            }

            .eyebrow {
                color: var(--primary);
                font-size: 12px;
                font-weight: 800;
                letter-spacing: 0.12em;
                margin: 0;
                text-transform: uppercase;
            }

            h1 {
                font-size: 28px;
                line-height: 1.2;
                margin: 5px 0 0;
            }

            h2 {
                color: var(--primary);
                font-size: 19px;
                line-height: 1.35;
                margin: 0;
            }

            h3 {
                font-size: 15px;
                line-height: 1.45;
                margin: 0;
            }

            p,
            li,
            td,
            th,
            dt,
            dd {
                font-size: 13px;
                line-height: 1.55;
            }

            .simulation-badge {
                background: var(--simulation-bg);
                border: 2px solid var(--accent);
                border-radius: 999px;
                color: var(--simulation-text);
                font-size: 12px;
                font-weight: 900;
                letter-spacing: 0.06em;
                padding: 8px 12px;
                text-align: center;
                text-transform: uppercase;
            }

            .notice {
                background: var(--simulation-bg);
                border: 1px solid #fdba74;
                border-radius: 8px;
                color: var(--simulation-text);
                margin-top: 20px;
                padding: 12px 14px;
            }

            .notice strong {
                display: block;
                margin-bottom: 3px;
            }

            main {
                padding: 28px 32px 36px;
            }

            .identity-grid,
            .source-grid,
            .two-column,
            .three-column,
            .stats-grid {
                display: grid;
                gap: 12px;
            }

            .identity-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .source-grid,
            .three-column,
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .two-column {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .field,
            .source-card,
            .stat-card {
                background: var(--surface-subtle);
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 12px;
            }

            .field-label,
            .meta-label {
                color: var(--muted);
                display: block;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: 0.04em;
                margin-bottom: 3px;
                text-transform: uppercase;
            }

            .field-value {
                font-size: 14px;
                font-weight: 650;
                overflow-wrap: anywhere;
            }

            .section {
                border-top: 1px solid #e2e8f0;
                margin-top: 24px;
                padding-top: 20px;
            }

            .section-heading {
                align-items: baseline;
                display: flex;
                gap: 10px;
                justify-content: space-between;
                margin-bottom: 12px;
            }

            .narrative {
                background: var(--surface-subtle);
                border-left: 4px solid var(--primary);
                border-radius: 0 8px 8px 0;
                margin: 0;
                padding: 12px 14px;
                white-space: pre-wrap;
            }

            .empty {
                color: var(--muted);
                font-style: italic;
            }

            table {
                border-collapse: collapse;
                width: 100%;
            }

            th,
            td {
                border: 1px solid var(--border);
                padding: 9px 10px;
                text-align: left;
                vertical-align: top;
            }

            th {
                background: var(--primary-soft);
                color: #0c4a6e;
                font-size: 11px;
                letter-spacing: 0.03em;
                text-transform: uppercase;
            }

            .code-pill,
            .tag {
                border: 1px solid #bae6fd;
                border-radius: 999px;
                display: inline-block;
                font-size: 11px;
                font-weight: 750;
                margin: 2px 3px 2px 0;
                padding: 3px 7px;
            }

            .code-pill {
                background: #f0f9ff;
                color: #075985;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            }

            .tag {
                background: #f8fafc;
                border-color: #cbd5e1;
                color: #334155;
            }

            .status-good {
                background: var(--success-bg);
                border: 1px solid #86efac;
                border-radius: 7px;
                color: var(--success-text);
                display: inline-block;
                font-size: 11px;
                font-weight: 800;
                padding: 4px 7px;
            }

            .status-warning {
                background: var(--warning-bg);
                border: 1px solid #fcd34d;
                border-radius: 7px;
                color: var(--warning-text);
                display: inline-block;
                font-size: 11px;
                font-weight: 800;
                padding: 4px 7px;
            }

            .timeline {
                list-style: none;
                margin: 0;
                padding: 0;
            }

            .timeline li {
                border-left: 3px solid #7dd3fc;
                margin-left: 10px;
                padding: 0 0 18px 18px;
                position: relative;
            }

            .timeline li::before {
                background: var(--primary);
                border: 3px solid #ffffff;
                border-radius: 50%;
                box-shadow: 0 0 0 1px #7dd3fc;
                content: "";
                height: 11px;
                left: -7px;
                position: absolute;
                top: 4px;
                width: 11px;
            }

            .timeline-meta {
                color: var(--muted);
                margin: 3px 0 0;
            }

            .note-card {
                border: 1px solid var(--border);
                border-radius: 9px;
                margin-top: 12px;
                overflow: hidden;
            }

            .note-version {
                border-top: 1px solid #e2e8f0;
                padding: 14px;
            }

            .note-version:first-child {
                border-top: 0;
            }

            .note-version.latest {
                background: #f0f9ff;
            }

            .report-footer {
                background: #0f172a;
                color: #e2e8f0;
                padding: 18px 32px;
            }

            .report-footer p {
                margin: 0;
            }

            .footer-grid {
                display: flex;
                gap: 12px;
                justify-content: space-between;
            }

            @media (max-width: 760px) {
                .screen-tools,
                .brand-row,
                .footer-grid {
                    align-items: stretch;
                    flex-direction: column;
                }

                .report-shell {
                    margin: 16px auto;
                }

                .report-header,
                main,
                .report-footer {
                    padding-left: 18px;
                    padding-right: 18px;
                }

                .identity-grid,
                .source-grid,
                .two-column,
                .three-column,
                .stats-grid {
                    grid-template-columns: 1fr;
                }

                .table-scroll {
                    overflow-x: auto;
                }

                table {
                    min-width: 640px;
                }
            }

            @page {
                margin: 13mm;
                size: A4 portrait;
            }

            @media print {
                html,
                body {
                    background: #ffffff;
                }

                .screen-tools {
                    display: none !important;
                }

                .report-shell {
                    margin: 0;
                    max-width: none;
                    padding: 0;
                }

                .report-page {
                    border: 0;
                    border-radius: 0;
                    box-shadow: none;
                    overflow: visible;
                }

                .section,
                .note-card,
                table,
                tr,
                .source-card {
                    break-inside: avoid;
                }

                .watermark {
                    color: rgba(240, 88, 40, 0.09);
                }

                a {
                    color: inherit;
                    text-decoration: none;
                }
            }
        </style>
    </head>
    <body>
        <nav class="screen-tools" aria-label="Tindakan laporan">
            <a href="{{ $backUrl }}">← Kembali ke debrief</a>
            <button id="print-report" type="button">Cetak / Simpan sebagai PDF</button>
        </nav>

        <div class="watermark" aria-hidden="true">SIMULASI · DATA SINTETIS</div>

        <div class="report-shell">
            <article class="report-page">
                <header class="report-header">
                    <div class="brand-row">
                        <div class="brand">
                            <img src="{{ asset('brand/ueu-mark.png') }}" alt="" />
                            <div>
                                <p class="eyebrow">SIMRS Campus UEU</p>
                                <h1>{{ $report['document']['title'] }}</h1>
                            </div>
                        </div>
                        <div class="simulation-badge">{{ $report['document']['classification'] }}</div>
                    </div>

                    <div class="notice" role="note">
                        <strong>Batas penggunaan</strong>
                        {{ $report['document']['legalStatus'] }} {{ $report['document']['sourcePolicy'] }}
                    </div>
                </header>

                <main id="main-report">
                    @yield('report-content')
                </main>

                <footer class="report-footer">
                    <div class="footer-grid">
                        <p><strong>{{ $report['document']['classification'] }}</strong></p>
                        <p>Jenis: {{ $report['document']['type'] }} · Encounter {{ $report['encounter']['number'] }}</p>
                    </div>
                </footer>
            </article>
        </div>

        <script>
            document.getElementById('print-report')?.addEventListener('click', () => window.print());
        </script>
    </body>
</html>
