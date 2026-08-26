# Formulir intake otoritas institusional G0/S0 — 2026-08-26

**Status:** SIAP DIISI — BELUM ADA IDENTITAS, PENUNJUKAN, PERSETUJUAN, SUARA, ATAU KEPUTUSAN

**Batas data:** `synthetic_only`. Formulir ini bukan mandat, bukan penunjukan, dan bukan bukti persetujuan.

## 0. Ikatan mesin intake

Blok berikut mengikat formulir kosong ini ke snapshot mesin yang sedang berlaku. Nilainya hanya metadata integritas; blok ini bukan keputusan, tanda tangan, appointment, atau external trust-root pin. Jalankan `ruby scripts/validate-g0-s0-intake.rb --mode integrity` setelah setiap perubahan pada sumber yang terikat.

Selama disposisi masih `UNDECIDED`, jumlah minimum orang juga belum final: `reproduce` memakai tiga kursi keputusan dan tujuh baris peran dasar; `replace` menambah kursi keputusan `executive_sponsor` dan, dengan pemisahan default, memerlukan orang kedelapan yang berbeda dari root appointment issuer dan voter lain. Dual-hat tidak boleh diasumsikan; hanya appointment kompatibel yang dibuktikan dan lolos seluruh separation rule yang dapat mengubah hitungan tersebut.

<!-- G0_S0_MACHINE_BINDING_BEGIN -->
```json
{
  "schema_version": 1,
  "intake_state": "UNDECIDED",
  "safety_boundary": {
    "data_boundary": "synthetic_only",
    "real_patient_data": "forbidden",
    "external_integrations": "disabled_no_transmission"
  },
  "separation_contract": {
    "base_role_people_distinct": true,
    "replace_eighth_voter_distinct": true,
    "root_appointment_issuer_may_self_appoint": false,
    "recusal_requires_replacement": true
  },
  "manifest": {
    "reference": "G0_PARITY_BATCH_MANIFEST.json",
    "sha256": "59b0f05b94e2a659b342016c5b9e5e9e011f3a8e4c0f0efd3dda12a7b65fa9ca"
  },
  "batch_a_register": {
    "reference": "G0_BATCH_A_DECISION_REGISTER_2026-08-25.json",
    "sha256": "a5c1e01686fdb576026802331d3228df9a6335f6f7176b7c023c459bfc532098"
  },
  "snapshot_plan": {
    "reference": "G0_OWNER_GOVERNANCE_SNAPSHOT_PLAN_2026-08-25.json",
    "sha256": "3ab2abd46fdcbbd7f13ff6bd87857834c88e1cc373894bbe78fa54ae0a440ee0"
  },
  "source_row": {
    "requirement_id": "PAR-ADM-005",
    "sha256": "ba37208012fd847ac6683b07a832c5db9c1ffcaa27e7d4e3c9fd8385e5aff4d3",
    "decision_status": "pending",
    "canonical_disposition": "pending"
  },
  "policy": {
    "reference": "G0_OWNER_AUTHORITY_POLICY_2026-08-25.json",
    "control_root_sha256": "dee9dd51f7e50242b2ef3b3aaea1f5bfe3868c491db485243b1dcd3571fba26e"
  },
  "source_required_authority_domains": [
    "product_delivery",
    "operations",
    "security_privacy_data"
  ],
  "selected_disposition": null,
  "known_signing_readiness_gaps": [],
  "candidate_signing_profiles": {
    "reproduce": {
      "exact_decision_seats": [
        "product_delivery",
        "operations",
        "security_privacy_data"
      ],
      "minimum_distinct_people": 7
    },
    "replace": {
      "exact_decision_seats": [
        "product_delivery",
        "operations",
        "security_privacy_data",
        "executive_sponsor"
      ],
      "minimum_distinct_people": 8,
      "default_separation": "eighth_distinct_person_required",
      "dual_hat_requires_proven_compatibility": true,
      "additional_role_row": {
        "role_id": "replace_disposition_executive_sponsor_voter",
        "function": "Executive sponsor voter untuk disposisi replace, terpisah dari root appointment issuer dan voter lain",
        "required_tokens": ["executive_sponsor", "appointment_acceptance", "decision_vote"]
      }
    }
  },
  "required_role_rows": [
    {
      "ordinal": 1,
      "role_id": "trust_root_registry_issuer_evidence_author",
      "function": "Trust-root A, identity-registry issuer, evidence author",
      "required_tokens": ["institutional_trust_root", "identity_registry_issuer", "registry_root"]
    },
    {
      "ordinal": 2,
      "role_id": "trust_root_implementer",
      "function": "Trust-root B, implementer",
      "required_tokens": ["institutional_trust_root", "registry_root"]
    },
    {
      "ordinal": 3,
      "role_id": "executive_sponsor_appointment_issuer",
      "function": "Executive sponsor dan root appointment issuer",
      "required_tokens": ["executive_sponsor", "appointment_issuer", "policy_approval", "appointment_issuance"]
    },
    {
      "ordinal": 4,
      "role_id": "independent_reviewer",
      "function": "Reviewer independen untuk policy, appointment, dan S0",
      "required_tokens": ["independent_reviewer", "registry_review", "session_review"]
    },
    {
      "ordinal": 5,
      "role_id": "product_delivery_s0_chair",
      "function": "Otoritas `product_delivery` dan chair S0",
      "required_tokens": ["product_delivery", "appointment_acceptance", "decision_vote"]
    },
    {
      "ordinal": 6,
      "role_id": "operations_s0_facilitator",
      "function": "Lead `operations` dan facilitator S0",
      "required_tokens": ["operations", "appointment_acceptance", "decision_vote"]
    },
    {
      "ordinal": 7,
      "role_id": "security_privacy_data_independent_control",
      "function": "Otoritas kontrol independen",
      "required_tokens": ["security_privacy_data", "appointment_acceptance", "decision_vote"]
    }
  ],
  "external_trust_root_pin_status": "absent"
}
```
<!-- G0_S0_MACHINE_BINDING_END -->

## 1. Tujuan dan arti keputusan sumber

Formulir ini mengumpulkan fakta institusional minimum untuk mengaktifkan identity registry, menyetujui kebijakan otoritas, menerbitkan penunjukan, dan menutup Sesi S0 secara dapat diverifikasi.

Keputusan pada register Batch A sebelum S0 adalah **kandidat sumber provisional** yang harus sudah mempunyai status, disposisi, target, alasan, dan bukti yang pasti agar policy dapat mengikat byte serta SHA yang tepat. Kandidat itu belum menjadi keputusan kriptografis otoritatif. Keputusan baru menjadi otoritatif setelah S0 mencocokkannya secara tepat, seluruh kursi wajib memberikan suara bertanda tangan, kuorum terpenuhi, dan reviewer independen menerbitkan receipt sesi.

Perubahan material setelah policy atau appointment ditandatangani—termasuk status, disposisi, target, kondisi, source-register SHA, atau row SHA—adalah **NO-GO**. Snapshot harus diarsipkan, sumber dan policy direvisi secara linear, disetujui ulang, lalu appointment yang mengikat snapshot baru diterbitkan sebelum S0.

## 2. Kandidat awal S0 — disposisi wajib dipilih institusi

Register mesin masih mencatat `PAR-ADM-005` sebagai `pending`. Proposal manusia Batch A saat ini menyarankan **Replace**, sedangkan draf awal formulir ini pernah menyebut **Reproduce**. Perbedaan itu material: jangan menandatangani policy, appointment, vote, atau receipt sebelum institusi memilih satu disposisi dan register sumber diperbarui secara konsisten.

| Bidang | Kandidat untuk keputusan institusi |
| --- | --- |
| PAR | `PAR-ADM-005` |
| Menu legacy | `Menu` |
| Status register mesin saat ini | `pending` — belum ada keputusan |
| Disposisi proposal Batch A | `replace` — belum disetujui |
| Alternatif yang harus diputuskan | `reproduce` hanya bila institusi secara eksplisit memilihnya dan register sumber direvisi |
| Target | Navigasi berbasis capability yang dikelola melalui release terkontrol |
| Lead akuntabel | `operations` |
| Kursi dasar jika `reproduce` | `product_delivery`, `operations`, `security_privacy_data` |
| Kursi tambahan jika `replace` | `executive_sponsor`; total empat kursi keputusan |
| Kuorum keputusan | Tiga orang unik untuk `reproduce`; empat orang unik untuk `replace` dengan pemisahan default |

Ruang lingkup yang harus disetujui secara substantif:

- menu hanya terlihat bagi peran yang memiliki capability yang disetujui;
- route/server authorization harus menegakkan izin yang sama dengan visibilitas menu;
- deep link dari peran yang salah harus ditolak tanpa perubahan state;
- perubahan navigasi harus melalui release, review, audit, dan rollback terkontrol;
- rollback harus mengembalikan keadaan menu **dan** route sebelumnya dengan bukti release; dan
- editor menu runtime tanpa kontrol, eskalasi hak akses, serta perubahan tanpa audit tetap dikecualikan.

Keputusan institusi — pilih tepat satu:

- [ ] `approve + replace` sesuai proposal Batch A, dengan ruang lingkup di atas.
- [ ] `approve + reproduce`; alasan perbedaan dari proposal Batch A dan revisi register sumber: ________________________________
- [ ] `revise`, `defer`, atau `reject`; status, alasan, kondisi, dan dampak: ________________________________
- [ ] Memilih PAR Batch A lain; PAR, status, disposisi, target, alasan, dan kondisi: ________________________________

Sebelum pilihan ditetapkan, status tetap **UNDECIDED**. Jika kandidat atau disposisi berbeda dari proposal sumber, hentikan proses. Register sumber, daftar otoritas, kuorum, bukti, policy, dan appointment harus direvisi atau dihitung ulang sebelum payload ditandatangani.

## 3. Tujuh peran dasar; orang kedelapan untuk `replace`

Tujuh baris dasar berikut harus diisi oleh tujuh orang berbeda. Jika institusi memilih `replace`, baris kedelapan juga wajib diisi oleh orang berbeda sebagai executive-sponsor voter; root appointment issuer pada baris 3 tidak boleh menunjuk dirinya sendiri. Pemisahan evidence author dan implementer dari trust-root dapat menambah jumlah orang bila institusi menghendaki pemisahan custody yang lebih kuat.

| No. | Fungsi minimum | Authorization/authority | Purpose public key yang diperlukan | Nama/ID institusional |
| ---: | --- | --- | --- | --- |
| 1 | Trust-root A, identity-registry issuer, evidence author | `institutional_trust_root`, `identity_registry_issuer` | `registry_root` | ____________________ |
| 2 | Trust-root B, implementer | `institutional_trust_root` | `registry_root` | ____________________ |
| 3 | Executive sponsor dan root appointment issuer | `executive_sponsor`, `appointment_issuer` | `policy_approval`, `appointment_issuance` | ____________________ |
| 4 | Reviewer independen untuk policy, appointment, dan S0 | `independent_reviewer` | `registry_review`, `session_review` | ____________________ |
| 5 | Otoritas `product_delivery` dan chair S0 | `product_delivery` | `appointment_acceptance`, `decision_vote` | ____________________ |
| 6 | Lead `operations` dan facilitator S0 | `operations` | `appointment_acceptance`, `decision_vote` | ____________________ |
| 7 | Otoritas kontrol independen | `security_privacy_data` | `appointment_acceptance`, `decision_vote` | ____________________ |
| 8 (`replace` saja) | Executive sponsor voter untuk disposisi replace, terpisah dari root appointment issuer dan voter lain | `executive_sponsor` | `appointment_acceptance`, `decision_vote` | ____________________ |

Pemisahan wajib:

- dua trust-root harus merupakan dua manusia berbeda;
- appointer berbeda dari setiap appointee;
- reviewer independen berbeda dari sponsor, appointer, appointee, chair, facilitator, evidence author, implementer, voter, dan decision signer;
- evidence author berbeda dari implementer;
- tiga pemegang kursi dasar `product_delivery`, `operations`, dan `security_privacy_data` harus tiga orang unik; `replace` menambah executive-sponsor voter sebagai orang keempat yang unik;
- recusal membuat kursi kosong dan harus digantikan appointment lain yang sah;
- satu orang dengan dua peran hanya diperbolehkan bila kombinasi kapasitasnya di-whitelist, memiliki appointment dan tanda tangan terpisah, serta tetap dihitung satu orang untuk kuorum; dan
- identitas service tidak boleh menjadi appointee, voter, reviewer manusia, atau penambah kuorum.

## 4. Formulir identitas dan public key — satu lembar per orang yang diwajibkan

### A. Identitas institusional

| Bidang | Isian institusi |
| --- | --- |
| Institutional subject ID stabil, format `UEU-PERSON-*` | ________________________________ |
| `identity_type` | `person` |
| Nama tampilan terverifikasi | ________________________________ |
| Unit | ________________________________ |
| Jabatan | ________________________________ |
| Status | [ ] `active` [ ] `suspended` [ ] `revoked` |
| Waktu efektif status jika suspended/revoked | ________________________________ |
| Institutional issuer subject ID | ________________________________ |
| Authorization roles yang disetujui | ________________________________ |
| Sumber institusi yang dipakai untuk verifikasi identitas | ________________________________ |

### B. Mandat/appointment

| Bidang | Isian institusi |
| --- | --- |
| Authority domain | ________________________________ |
| Authority role | ________________________________ |
| Capacity | ________________________________ |
| Scope PAR dan Batch | ________________________________ |
| Hak: session action, decision status, disposition | ________________________________ |
| Issuer institutional ID dan role | ________________________________ |
| Nomor/referensi mandat | ________________________________ |
| Effective at | ________________________________ |
| Expires at | ________________________________ |
| Konflik: `none` atau `disclosed` | ________________________________ |
| Rincian konflik bila disclosed | ________________________________ |
| Delegasi: parent, scope, depth, may-redelegate | ________________________________ |

### C. Public key — materi publik saja

| Bidang | Isian institusi |
| --- | --- |
| Key ID stabil, format `UEU-PUBKEY-*` | ________________________________ |
| Algorithm | `RS256` |
| RSA public SPKI Base64 | ________________________________ |
| RSA modulus/exponent | Minimal 3072 bit; exponent 65537 |
| Fingerprint SHA-256 public key | ________________________________ |
| Allowed purposes | ________________________________ |
| Valid from | ________________________________ |
| Valid until | ________________________________ |
| Revoked at/reason, bila ada | ________________________________ |
| Lokasi custody private key, cukup nama sistem/prosedur—bukan secret | ________________________________ |

**Dilarang menyerahkan atau menyimpan:** private key, password, passphrase, API key, bearer/authorization value, cookie/session/access/refresh token, HMAC/signing secret, recovery material, credential integrasi, atau secret assignment apa pun.

## 5. Persetujuan policy dan trust-root pin

| Bidang | Isian institusi |
| --- | --- |
| Referensi mandat executive sponsor | ________________________________ |
| Referensi bukti adopsi policy | ________________________________ |
| Evidence author institutional ID | ________________________________ |
| Implementer institutional ID | ________________________________ |
| Reviewer independen institutional ID | ________________________________ |
| Metode dan referensi verifikasi | ________________________________ |
| Kanal terpisah untuk menyampaikan approved trust-root SHA-256 | ________________________________ |
| Custodian/penerima pin pada kanal tersebut | ________________________________ |

Trust-root SHA-256 tidak boleh dipercaya dengan menyalinnya dari JSON yang sedang divalidasi. Pin final harus disampaikan melalui kanal konfigurasi/custody institusional yang independen dan dicocokkan saat validasi.

## 6. Urutan aktivasi

1. Institusi memverifikasi tujuh identitas dasar; jika memilih `replace`, verifikasi juga executive-sponsor voter kedelapan beserta mandat, konflik, dan pemisahan tugasnya.
2. Pemilik key membuat dan menyimpan private key pada perangkat/keystore institusi; hanya public key dan metadata publik diserahkan.
3. Institusi memilih status dan disposisi kandidat sumber Batch A; register sumber diperbarui sehingga proposal, register mesin, policy, dan intake memakai semantik yang sama. Keputusan itu tetap provisional sampai S0.
4. Snapshot identity registry dibuat; dua trust-root menandatangani payload root yang sama; pin disampaikan melalui kanal terpisah.
5. Policy dibangun dari manifest serta SHA register A–G yang tepat; executive sponsor menandatangani setelah snapshot, lalu reviewer independen memverifikasi.
6. Appointment diterbitkan oleh sponsor, diterima appointee, dan direview sebelum `effective_at`; semuanya mengikat policy, manifest, source-register SHA, serta registry snapshot yang sama.
7. Source/policy/appointment SHA dibekukan. Perbedaan satu byte adalah NO-GO dan memerlukan revisi linear serta tanda tangan baru.
8. S0 dilaksanakan dengan chair dan facilitator yang appointment-nya aktif dan dipegang orang berbeda. Untuk `reproduce`, tiga kursi dasar memberikan `consent`; untuk `replace`, tiga kursi dasar plus kursi `executive_sponsor` memberikan `consent`. `Recuse` membuat kursi kosong dan harus digantikan appointment sah sebelum keputusan bulat.
9. Reviewer independen menerbitkan receipt setelah seluruh vote dan penutupan sesi.
10. Validator memeriksa signature, kuorum, causal timestamps, source match, digest, dan append-only history. Penutupan S0 tidak berarti seluruh G0 selesai; keputusan Batch A–G lain tetap memerlukan S1–S7.

## 7. Guardrail tetap

- Seluruh bukti, skenario, appointment, keputusan, dan pengujian memakai data sintetis saja.
- Tidak ada data pasien nyata, identitas nyata di data klinis, atau pemindahan data produksi.
- Tidak ada koneksi atau transmisi live ke BPJS, VClaim, SATUSEHAT, perangkat produksi, sertifikat TTE produksi, atau endpoint eksternal lain.
- Klaim/BPJS/Apotek tetap `Soon` sampai secara eksplisit dibangun dan diotorisasi melalui keputusan baru.
- Tidak ada formulir ini yang mengaktifkan akses produksi, membuat credential, atau mengubah batas integrasi.
- Nama kandidat dalam dokumen terdahulu bukan appointment; hanya institutional subject yang diverifikasi, menerima appointment, ditandatangani issuer, dan direview independen yang dapat dipakai.

## 8. Checklist kesiapan penutupan S0

- [ ] Institusi memilih tepat satu status/disposisi ADM-005, register sumber memakai semantik yang sama, atau jalur alternatif lengkap ditetapkan.
- [ ] Tujuh orang dasar telah diverifikasi; jika `replace`, executive-sponsor voter kedelapan juga telah diverifikasi; seluruh pemisahan tugas lulus.
- [ ] Setiap identity record lengkap, aktif, dan mempunyai issuer yang sah.
- [ ] Setiap public key public-only, RS256, minimal 3072 bit, exponent 65537, fingerprint cocok, purpose serta masa berlaku tepat.
- [ ] Dua trust-root menandatangani snapshot yang sama dan pin dikirim melalui kanal terpisah.
- [ ] Policy mengikat manifest, 268 row, dan SHA register A–G yang tepat; sponsor dan reviewer menandatangani secara kausal.
- [ ] Appointment product, operations, security, chair, dan facilitator mengikat snapshot yang sama, sudah diterima, diterbitkan, direview, dan aktif; `replace` juga mempunyai appointment executive-sponsor voter yang terpisah dari root issuer.
- [ ] Konflik dan delegasi direkam; tidak ada recusal yang meninggalkan kursi kosong.
- [ ] Source decision, row SHA, register SHA, evidence root, control root, appointment digest, dan vote payload cocok tepat.
- [ ] `Reproduce`: tiga orang unik memberikan consent untuk tiga kursi; `replace`: empat orang unik memberikan consent untuk empat kursi termasuk `executive_sponsor`; jika tidak, keputusan dinyatakan NO-GO.
- [ ] Receipt reviewer dibuat setelah vote dan penutupan sesi.
- [ ] Pemindaian secret bersih; repository hanya memuat public key dan detached signature.
- [ ] Batas `synthetic_only` dan non-transmission diverifikasi.
- [ ] Status akhir dicatat jujur: S0 tertutup atau NO-GO; G0 keseluruhan tetap terbuka sampai S1–S7 dan seluruh 268 keputusan selesai.
