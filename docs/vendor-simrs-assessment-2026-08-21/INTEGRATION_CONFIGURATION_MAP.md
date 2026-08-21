# Integration and Global Configuration Map

Date: 2026-08-21

The combined lecturer–administrator Settings screen exposes **348 controls** spanning hospital identity, clinical behavior, finance, printing, and external integrations. The table below records only field names, labels, type, and whether a value was present. Actual values were not copied or retained. Secret-like populated fields are shown only as **populated (masked)**.

| Field | Label | Type | Configuration state | Secret-like |
|---|---|---|---|---|
| bpjs_esep | Ya | radio | populated | no |
| bpjs_skdp_minimal_hari_terbit | Minimal Hari Terbit SKDP (Aturan BPJS) | text | populated | no |
| no_wa_pendaftaran_operasi | Nomor WA | textarea | empty | no |
| default_igd_bagian_id | Default Depo di Resep Online IGD | select-one | populated | no |
| default_rawatjalan_bagian_id | Default Depo di Resep Online Rawat Jalan | select-one | populated | no |
| default_rawatinap_bagian_id | Default Depo di Resep Online Rawat Inap | select-one | populated | no |
| default_ibs_bagian_id | Default Depo di Resep Online IBS | select-one | populated | no |
| apotek_margin_penjualan_rj_bpjs_default | Default Margin Farmasi RJ-BPJS | text | populated | no |
| apotek_margin_penjualan_ri_bpjs_default | Default Margin Farmasi RI-BPJS | text | populated | no |
| resepdokter_warning_biaya | Ya | radio | populated | no |
| resepdokter_disallow_ketika_melebihi_stock | Ya | radio | populated | no |
| farmasi_resep_online_aturan_1_mandatory | Ya | radio | populated | no |
| farmasi_resep_online_aturan_2_mandatory | Ya | radio | populated | no |
| farmasi_resep_online_aturan_3_mandatory | Ya | radio | populated | no |
| farmasi_resep_online_aturan_4_mandatory | Ya | radio | populated | no |
| farmasi_resep_online_aturan_5_mandatory | Ya | radio | populated | no |
| farmasi_resep_online_aturan_6_mandatory | Ya | radio | populated | no |
| bridging_bpjs | Ya | radio | populated | no |
| bridging_bpjs_sep | Ya | radio | populated | no |
| bpjs_antrol_realtime | Ya | radio | populated | no |
| bpjs_pendaftaran_validasi_antrol | Ya | radio | populated | no |
| bridging_aplicares | Ya | radio | populated | no |
| bridging_bpjs_apotek | Ya | radio | populated | no |
| bpjs_icare_trigger | Off | radio | empty | no |
| bpjs_skdp_otomatis_appointment | Ya | radio | populated | no |
| bpjs_skdp_otomatis_mjkn | Ya | radio | populated | no |
| bpjs_vclaim_username | Username | text | empty | yes |
| bpjs_vclaim_password | Password | text | empty | yes |
| bpjs_vclaim_delay_login | Delay Login | text | populated | no |
| bpjs_vclaim_delay_noka | Delay Noka | text | populated | no |
| bpjs_ppkpelayanan_code | Kode RS (BPJS) | text | empty | no |
| bpjs_consid | Consumer Id | text | empty | no |
| bpjs_secretkey | Secret Key | text | empty | yes |
| bpjs_userkey | User Key | text | empty | no |
| bpjs_mainurl | URL | select-one | populated | no |
| bpjs_consid_antrean | Consumer Id | text | empty | no |
| bpjs_secretkey_antrean | Secret Key | text | empty | yes |
| bpjs_userkey_antrean | User Key | text | empty | no |
| bpjs_antreanurl | URL | select-one | populated | no |
| bpjs_consid_apotek | Consumer Id | text | empty | no |
| bpjs_secretkey_apotek | Secret Key | text | empty | yes |
| bpjs_userkey_apotek | User Key | text | empty | no |
| bpjs_apotekurl | URL | select-one | populated | no |
| bpjs_erm_api[cons_id] | Consumer Id | text | empty | no |
| bpjs_erm_api[secret_key] | Secret Key | text | empty | yes |
| bpjs_erm_api[user_key] | User Key | text | empty | no |
| bpjs_erm_api[url] | URL | select-one | populated | no |
| eklaim_bridging_type | iDRG | radio | populated | no |
| eklaim_mainurl | URL Target | text | populated | no |
| eklaim_strkey | Str Key | text | populated | no |
| eklaim_kode_tarif | Kode Tarif | select-one | populated | no |
| eklaim_sendclaim | Ya | radio | populated | no |
| eklaim_plafon_mainurl | URL Target | text | populated | no |
| eklaim_plafon_strkey | Str Key | text | populated | no |
| eklaim_plafon_coder_nik | NIK Coder | text | populated | no |
| rsonline_api[base_url] | Base URL | text | populated | no |
| rsonline_api[password] | Password | text | empty | yes |
| lis_mainurl | URL Target | text | empty | no |
| lis_password | Password | text | empty | yes |
| pacs_api[penyedia_pacs] | Sahabat | radio | populated | no |
| pacs_api[base_url] | Base URL (Worklist) | text | populated | no |
| pacs_api[username] | Username | text | empty | yes |
| pacs_api[password] | Password | text | empty | yes |
| pacs_api[viewer_url] | Viewer URL (Local) | text | populated | no |
| pacs_api[public_viewer_url] | Viewer URL (Public) | text | populated | no |
| pacs_api[viewer_method] | Popup | radio | populated | no |
| bridging_satusehat | Ya | radio | populated | no |
| satusehat_organization_id | OrganizationId | text | populated | no |
| satusehat_client_id | Client Key | text | populated (masked) | yes |
| satusehat_client_secret | Secret Key | text | populated (masked) | yes |
| satusehat_limit_date | Limit Tanggal | text | populated | no |
| satusehat_auth_url | Auth URL | select-one | populated | no |
| satusehat_base_url | Base URL | select-one | populated | no |
| eklaim_protocol | Potokol | select-one | populated | no |
| eklaim_mrnumber_is_int | Folder Nomor RM Integer | select-one | populated | no |
| eklaim_merged_filename_format | Format Nama File PDF Gabungan | text | populated | no |
| eklaim_send_all_files | Ya | radio | populated | no |
| eklaim_http_os | OS | select-one | populated | no |
| eklaim_http_address | Address RJ | text | populated | no |
| eklaim_http_folder | Folder RJ | text | populated | no |
| eklaim_http_address_rawatinap | Address RI | text | populated | no |
| eklaim_http_folder_rawatinap | Folder RI | text | populated | no |
| eklaim_ftp_os | OS | select-one | populated | no |
| eklaim_ftp_hostname | Hostname | text | empty | no |
| eklaim_ftp_username | Username | text | empty | yes |
| eklaim_ftp_password | Password | text | empty | yes |
| eklaim_ftp_port | Port | text | populated | no |
| eklaim_ftp_folder | Folder | text | empty | no |
| pendaftaran_igd_checked[pendaftaranUgdCetakLembarSep] | SEP | checkbox | populated | no |
| pendaftaran_rawatjalan_checked[pendaftaranRawatjalanCetakLembarSep] | SEP | checkbox | populated | no |
| pendaftaran_rawatjalan_checked[pendaftaranRawatjalanCetakQueueNumberPreview] | No Antrian (preview) | checkbox | populated | no |
| pendaftaran_rawatinap_checked[pendaftaranRawatinapInputCetakLembarSep] | SEP | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknSep] | SEP | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknResumeMedis] | Resume Medis | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknResumeMedisKlaim] | Resume Medis Klaim | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknSuratBuktiPelayanan] | Surat Bukti Pelayanan | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknRincianBiayaDetail] | Rincian Biaya Detail | checkbox | populated | no |
| rm_rawatjalan_default_input[klaimJknOtomatisKirimEmrPdf] | Otomatis Kirim EMR PDF | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknSep] | SEP | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknCppt] | CPPT | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknResumeMedis] | Resume Medis | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknResumeMedisKlaim] | Resume Medis Klaim | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknSuratBuktiPelayanan] | Surat Bukti Pelayanan | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknRincianBiayaDetail] | Rincian Biaya Detail | checkbox | populated | no |
| rm_rawatinap_default_input[klaimJknOtomatisKirimEmrPdf] | Otomatis Kirim EMR PDF | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknSep] | SEP | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknResumeMedis] | Resume Medis | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknSuratBuktiPelayanan] | Surat Bukti Pelayanan | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknResumeMedisKlaim] | Resume Medis Klaim | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknRincianBiayaDetail] | Rincian Biaya Detail | checkbox | populated | no |
| klaim_rawatjalan_default_input[klaimJknOtomatisKirimEmrPdf] | Otomatis Kirim EMR PDF | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknSep] | SEP | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknCppt] | CPPT | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknResumeMedis] | Resume Medis | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknResumeMedisKlaim] | Resume Medis Klaim | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknSuratBuktiPelayanan] | Surat Bukti Pelayanan | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknRincianBiayaDetail] | Rincian Biaya Detail | checkbox | populated | no |
| klaim_rawatinap_default_input[klaimJknOtomatisKirimEmrPdf] | Otomatis Kirim EMR PDF | checkbox | populated | no |
| checkin_disable_check_sep | Ya | radio | populated | no |
| certificate_api_url | URL API | text | empty | no |
| certificate_api_username | Username | text | empty | yes |
| certificate_api_password | Password | text | empty | yes |
|  | -- Cara Bayar -- Umum Bpjs Asuransi Lain | select-one | populated | no |
| whatsapp_phone_number_id | Phone Number ID | text | empty | no |
| whatsapp_access_token | Token | textarea | empty | yes |
| whatsapp_waba_id | WABA ID | text | empty | no |
| whatsapp_template_skdp | Template SKDP | text | empty | no |
| whatsapp_template_prescription_start | Template Resep Mulai Dikerjakan | text | empty | no |
| openai_base_url | Base URL | text | populated | no |
| openai_api_key | API Key | textarea | populated (masked) | yes |

## Interpretation

- A populated configuration control proves only that a value is present in the page; it does not prove that the integration is enabled, reachable, current, or successfully exchanging data.
- Empty credential fields can mean disabled, configured elsewhere, deliberately redacted, or incomplete. Vendor evidence is required.
- SatuSehat and OpenAI secret material was retrievable in editable page controls. Administrative route access is expected for this account, but secret readback should still be masked/write-only and the exposed credentials should be rotated.
- Outpatient registration displayed VClaim as inactive, while bridging logs still contained BPJS Antrol targets. Configuration, feature state, scheduled work, and historical logs therefore need separate interpretation.

## Required vendor proof for each integration

For BPJS/VClaim/Antrol/Aplicares, SatuSehat, E-Klaim/iDRG, LIS, PACS, TTE/e-sign, RS Online/SIRS/SIRANAP, WhatsApp, ERP/accounting, IoT, and OpenAI/Aisha: provide ownership, environment, endpoint, data contract, authentication method, certificate/secret rotation, timeout/retry behavior, idempotency, reconciliation, error queue, monitoring, data retention, and a redacted successful transaction trace.

