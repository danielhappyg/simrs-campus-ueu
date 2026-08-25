# Paket penunjukan pemilik dan sesi keputusan G0 — 2026-08-25

**Status:** PROPOSAL — BELUM ADA PENUNJUKAN ATAU KEPUTUSAN

**Cakupan:** 268 kapabilitas Batch A–G, data sintetis saja

**Larangan:** dokumen ini tidak menunjuk seseorang, tidak memberi mandat, tidak merekam suara, dan tidak menyetujui keputusan apa pun.

Nama, jabatan, atau unit yang pernah muncul pada dokumen naratif hanya boleh diperlakukan sebagai kandidat yang belum diverifikasi. Tidak ada peran interim atau organisasi yang menjadi penunjukan aktif tanpa rekaman terstruktur, penerimaan appointee, tanda tangan issuer, serta verifikasi independen yang lolos validator.

## Berkas otoritatif mesin

- `G0_INSTITUTIONAL_IDENTITY_KEY_REGISTRY_2026-08-26.json`: trust registry proposal yang masih kosong; belum ada subject, public key, atau trust-root signature.
- `G0_OWNER_AUTHORITY_POLICY_2026-08-25.json`: kebijakan proposal, katalog kapasitas/peran, pemisahan tugas, aturan disposisi, dan matriks persyaratan tepat 268 PAR.
- `G0_OWNER_APPOINTMENT_REGISTER_2026-08-25.json`: register terbuka dengan `appointments: []` dan `events: []`.
- `G0_DECISION_SESSION_REGISTER_2026-08-25.json`: register terbuka dengan `sessions: []`.
- `G0_OWNER_DECISION_EVIDENCE_2026-08-25/`: akar bukti tertutup untuk artefak JSON bertanda tangan di masa depan.

Seluruh digest mengikat manifest, register A–G, kebijakan, penunjukan, baris PAR, bukti/kontrol, suara, dan sesi. Perubahan setelah tanda tangan, hash kedaluwarsa, revisi paralel, atau sumber yang tidak cocok harus gagal tertutup.

## Trust root dan tanda tangan nyata

Validator tidak menerima teks yang sekadar berbentuk signature. Penutupan terminal menggunakan detached `RS256` yang benar-benar diverifikasi dengan Ruby OpenSSL atas byte JSON kanonis. Kunci RSA harus public-only SPKI, sedikitnya 3072 bit, exponent 65537, strict Base64, dan fingerprint SHA-256 yang dapat dihitung ulang. Private key tidak pernah masuk repository.

Pesan tanda tangan memakai domain `SIMRS-UEU-G0-OWNER-SIGNATURE-V1`, purpose tertutup, serta framing panjang domain/purpose/envelope. Envelope kanonis selalu mengikat jenis artefak, purpose, institutional subject ID penanda tangan, key ID, algoritma, `signed_at`/`verified_at`, SHA-256 payload semantik, serta ID/revisi/SHA/root snapshot identity registry yang tepat. Timestamp acceptance, issuer, reviewer, event, vote, session receipt, dan root signature adalah bagian byte yang ditandatangani; mengubah timestamp tanpa tanda tangan baru harus gagal.

JSON kanonis memakai key ASCII terurut, string UTF-8 NFC, integer 64-bit tanpa float, dan timestamp UTC presisi detik. Dengan demikian perubahan spasi tidak menjadi otoritas baru, sedangkan perubahan nilai, timestamp, purpose, subject, key, registry snapshot, atau payload pasti membatalkan tanda tangan.

Snapshot identity registry aktif kelak membutuhkan sedikitnya dua tanda tangan trust-root dari dua orang berbeda. SHA trust root **harus diberikan dari luar file repository** melalui `--trusted-identity-root-sha256`; file JSON tidak boleh mempercayai pin miliknya sendiri. Tanpa pin independen, policy approval dan sesi terminal tetap gagal tertutup.

Rotasi registry harus berupa rantai snapshot linear. Revisi kedua dan seterusnya wajib menunjuk file snapshot sebelumnya di akar bukti, mengikat SHA file tersebut, memverifikasi ulang dua root signature terdahulu, serta mempertahankan setiap subject dan material public-key historis. Mengganti prefix, melompati revisi, membuat siklus, atau menghapus key historis gagal tertutup.

Setiap signature diverifikasi terhadap snapshot registry historis yang dinyatakan di envelope, bukan otomatis terhadap registry terbaru. Kunci yang baru muncul pada revisi 2 tidak dapat mengesahkan artefak revisi 1. Suspension/revocation pada snapshot baru mempertahankan signature lama yang dibuat sebelum waktu efektifnya, tetapi mencegah pemakaian ulang setelah waktu tersebut.

Setiap snapshot registry mempunyai waktu aktivasi yang dapat dihitung ulang: nilai maksimum dari `snapshot_at` dan seluruh trust-root signature yang diwajibkan. Acceptance, issuer signature, reviewer receipt, lifecycle event, vote, dan session receipt yang mengikat snapshot tersebut tidak boleh bertimestamp sebelum aktivasi. `valid_from` key yang lebih awal tidak dapat dipakai untuk membuat signature revision 2 secara surut sebelum root revision 2 selesai menyetujui snapshot.

Kebijakan otoritas dan register keputusan A–G juga memiliki snapshot bernomor, timestamp/cutoff, content/control root, dan rantai predecessor linear. Appointment, session, dan decision mengikat snapshot historis yang tepat. Koreksi kebijakan atau baris sumber membuat revisi baru; sesi lama tetap diverifikasi terhadap snapshot lama, sedangkan revisi keputusan baru harus mengikat snapshot baru serta `prior_session_sha256`/`prior_decision_sha256` yang sah. Fork, stale predecessor, atau mutasi snapshot lama gagal tertutup.

Rantai waktunya wajib kausal: seluruh source `captured_at` tidak boleh melewati policy `snapshot_at`; sponsor menandatangani sesudah snapshot; reviewer memverifikasi sesudah sponsor; issuer/acceptance/reviewer appointment menyusul aktivasi policy; appointment efektif sebelum vote; dan session receipt menyusul seluruh vote serta penutupan sesi. Setiap policy historis divalidasi penuh—closed schema, source binding, control root, approval kriptografis, serta kronologi—sebelum boleh dipakai mengesahkan sesi lama. Snapshot proposal/pending tidak pernah menjadi otoritas historis.

Koreksi policy revision 2 tidak boleh mengurangi accountable authority, co-owner, special separation role, independent control, statutory scope, eligible role/disposition, atau batas `synthetic_only`. Versi kontrak ini menolak perubahan applicability; perubahan otoritas kelak memerlukan kontrak perubahan lintas-otoritas terpisah.

### Onboarding identitas manual

1. Registry officer memverifikasi institutional subject ID stabil, tipe `person|service`, nama, unit, jabatan, status, dan issuer terhadap sumber institusi di luar repository.
2. Pemilik kunci membuat RSA private key pada perangkat/keystore institusi; hanya public SPKI Base64, key ID, fingerprint, purpose, dan masa berlaku yang diserahkan.
3. Dua trust-root person memeriksa snapshot, identity set, purpose, serta fingerprint; keduanya menandatangani payload root yang sama.
4. Trust-root SHA disalurkan melalui kanal konfigurasi validator yang independen dan dibandingkan dengan pin yang disetujui. Jangan menyalin pin dari JSON yang sedang divalidasi.
5. Policy sponsor dan reviewer independen menandatangani payload kebijakan; reviewer harus sesudah sponsor.
6. Baru setelah itu appointment dapat diterbitkan, diterima appointee, dan diverifikasi reviewer sebelum `effective_at`.

Identitas `UEU-SERVICE-*` hanya boleh dipakai untuk `automated_integrity_receipt`. Ia tidak boleh menjadi appointee manusia, signer keputusan, reviewer manusia, atau menambah kuorum.

## Batas kewenangan

Setiap keputusan terminal wajib memiliki persetujuan berbasis peran yang bulat dari:

1. `product_delivery` sebagai otoritas produk/bisnis;
2. lead domain yang akuntabel;
3. seluruh co-owner/source-owner yang berlaku;
4. kontrol keamanan/privasi/data, keselamatan klinis/RMIK, atau kontrol keuangan independen bila berlaku; dan
5. sponsor/domain statutori untuk ruang lingkup statutori atau kesehatan publik.

Kehadiran produk tidak menggantikan domain lain. Orang yang sama dapat memegang dua peran hanya jika kombinasi kapasitasnya ada pada whitelist, memiliki dua penunjukan dan dua tanda tangan yang terpisah, serta tetap dihitung sebagai satu orang untuk kuorum. Kombinasi tidak dikenal atau pasangan yang tidak kompatibel ditolak.

Pemisahan minimum:

- appointer berbeda dari appointee;
- reviewer register berbeda dari subject, evidence author, implementer, dan decision signer;
- executor migrasi berbeda dari approver pemetaan;
- cashier/treasury berbeda dari reconciler keuangan independen; dan
- penulis formula laporan berbeda dari sponsor statutori.

## Formulir penunjukan — siap diisi, masih kosong

Semua kolom berikut wajib diisi pada artefak JSON, bukan hanya pada narasi ini.

| Bidang | Nilai yang harus direkam |
| --- | --- |
| Appointment ID | `APP-G0-...` unik dan stabil |
| Subject | institutional ID, `identity_type`, nama tampilan, jabatan, unit dari snapshot registry historis |
| Otoritas | authority domain, role, capacity |
| Cakupan | daftar PAR tepat dan Batch manifest yang diturunkan |
| Hak keputusan | consent/recuse; status dan disposisi yang diizinkan |
| Mandat | issuer institutional ID, role, mandate reference |
| Masa berlaku | effective/expiry bertimestamp |
| Konflik | `none` atau disclosure substantif |
| Delegasi | parent, scope, depth; tidak boleh melebar atau hidup lebih lama |
| Binding | identity/trust registry yang dipin, SHA kebijakan, manifest, register A–G, canonical payload |
| Persetujuan | acceptance appointee dan signature issuer |
| Verifikasi | receipt reviewer independen, metode, referensi, public-key ID |

Tidak boleh menyimpan kredensial atau private key. Pemindaian rekursif juga menolak password/passphrase, generic/session/access/refresh token, bearer/authorization/cookie secret, API key, HMAC/signing/recovery material, dan pola secret assignment pada setiap artefak terpisah. Public-only SPKI, fingerprint, key ID, dan detached signature tetap diperbolehkan. Suspension, resumption, revocation, dan delegation merupakan event append-only; revocation tidak menghapus sejarah. Tanda tangan/event wajib mendahului aktivasi; penunjukan harus sudah aktif pada timestamp sesi dan vote.

Metadata subject appointment—`identity_type`, nama, jabatan, dan unit—dicocokkan terhadap snapshot identity registry historis yang ditandatangani appointment, bukan registry terbaru. Pembaruan metadata institusional pada revision berikutnya mempertahankan appointment/sesi lama; appointment baru wajib memakai metadata revision baru.

Reviewer receipt tidak menandatangani business payload secara longgar. Receipt memiliki payload semantik tertutup sendiri yang mengikat `reviewed_payload_sha256`, reviewer/key/purpose, evidence author, implementer, metode/referensi verifikasi, `verified_at`, serta snapshot registry. Perubahan satu bidang receipt tanpa tanda tangan ulang membatalkan receipt.

## Urutan tepat delapan sesi

Sesi dapat dipersiapkan secara paralel, tetapi penandatanganan mengikuti rantai digest linear. Kursi yang recuse tetap kosong dan harus diisi oleh appointment lain yang sah.

| Urutan | Kode | Cakupan | Isian wajib sebelum ditutup |
| ---: | --- | --- | --- |
| 0 | S0 | Ratifikasi penunjukan dan pemeriksaan batas T0 | policy/manifest/register SHA; chair/facilitator appointments; bukti acceptance, issuer, reviewer; konflik/delegasi |
| 1 | S1 | Batch A — shared controls | row SHA; product, lead, semua co-owner; evidence/control roots; keputusan dan suara |
| 2 | S2 | Batch B — patient/encounter | sumber A; registration/admission lead; clinical/RMIK/finance/security yang berlaku |
| 3 | S3 | Batch C — core care/RMIK | sumber A–B; clinical/nursing/RMIK dan seluruh co-owner |
| 4 | S4 | Batch D — diagnostics/allied/surgery | sumber A–C; lead diagnostik/layanan dan lintas-domain terkait |
| 5 | S5 | Batch E — pharmacy/warehouse | sumber A–D; pharmacy/GF, clinical, finance, security sesuai scope |
| 6 | S6 | Batch F — finance/claims/BPJS simulation | sumber A–E; finance/cashier/claims serta kontrol independen |
| 7 | S7 | Batch G — reports/statutory/public health | sumber A–F; reporting/formula/source owners/security/sponsor statutori |

## Rekaman keputusan per baris — siap diisi, masih kosong

Setiap keputusan harus mengikat:

- PAR ID, Batch, row SHA, dan SHA register Batch;
- status keputusan, disposisi, target, exclusion, condition, dan unresolved-gate owners;
- evidence roots dan control roots;
- seluruh appointment ID serta digest;
- prior decision digest (atau `null` untuk keputusan pertama);
- vote per peran, institutional identity, appointment digest, timestamp, public-key ID, payload SHA, dan signature;
- decision SHA dan canonical session SHA; dan
- receipt reviewer independen.

Persetujuan harus bulat. Minimum dua orang unik untuk keputusan reproduce biasa dan minimum tiga bila kontrol independen berlaku. Identitas alias, vote duplikat, atau dua appointment milik satu orang tidak boleh menambah kuorum.

Aturan tambahan disposisi:

- `replace`: sponsor eksekutif;
- `consolidate`: sponsor, security, seluruh owner member, owner terminal, dan otoritas statutori bila berlaku; terminal wajib `approve` + `reproduce|replace`;
- `retire|exclude`: sponsor, domain, operations, RMIK/retention, security, serta otoritas statutori bila berlaku;
- `defer`: sponsor, domain, seluruh unresolved-gate owner, security, serta otoritas statutori bila berlaku.

## Keadaan saat ini

- Kebijakan: `proposal`, approval `pending`.
- Identity/key registry: `proposal`, identity **0**, root signature **0**, external trust-root pin belum ada.
- Penunjukan: **0**.
- Event lifecycle: **0**.
- Sesi: **0**.
- Suara/keputusan terminal: **0**.
- G0: tetap terbuka dan harus gagal tertutup.

## Referensi

- `G0_PARITY_BATCH_MANIFEST.json`
- `G0_AUTHORITY_MAP_2026-08-25.md`
- `G0_BATCH_A_DECISION_REGISTER_2026-08-25.json` sampai `G0_BATCH_G_DECISION_REGISTER_2026-08-25.json`
- `REQUIREMENTS_GOVERNANCE.md`
- `DATA_AND_INTEGRATION_STRATEGY.md`
