# Kontrak bukti penunjukan dan keputusan G0

Direktori ini adalah akar bukti khusus untuk register penunjukan dan sesi keputusan G0. Saat ini tidak ada artefak appointment, lifecycle event, session, vote, atau approval karena belum ada identitas/mandat/tanda tangan otoritatif.

## Batas

- Data hanya sintetis.
- Tidak boleh menyimpan kredensial, private key, token, atau data pasien nyata.
- Tidak boleh memuat klaim pengiriman BPJS/VClaim/SATUSEHAT/E-Klaim atau endpoint hidup.
- File bukti masa depan harus berupa JSON reguler di dalam direktori ini, mengikuti closed schema validator, dan diikat SHA-256.
- Public-key ID, public-only SPKI, fingerprint, dan detached signature boleh direkam; material private key tidak boleh direkam.
- Validator memindai artefak JSON secara rekursif untuk nama maupun nilai yang menyerupai password, passphrase, generic/session/access/refresh token, bearer/authorization/cookie secret, API key, private key, HMAC/signing/recovery material, credential, atau secret assignment. Public-only SPKI, fingerprint, dan detached signature tetap diperbolehkan.

## Kontrak kriptografi

- Algoritma terminal: hanya `RS256`, RSA minimal 3072 bit, exponent 65537.
- Public key: strict Base64 dari DER SPKI public-only; fingerprint SHA-256 harus dapat dihitung ulang.
- Payload: JSON kanonis dengan key ASCII terurut, UTF-8 NFC, integer 64-bit tanpa float, dan timestamp UTC detik.
- Pesan: domain-separated dan length-framed; `purpose` merupakan bagian pesan, sehingga signature tidak dapat dipindahkan antar-artefak.
- Envelope tanda tangan: jenis artefak, purpose, signer subject, key ID, algoritma, timestamp autentik, SHA-256 payload semantik, serta ID/revisi/SHA/root identity-registry snapshot seluruhnya masuk byte kanonis yang ditandatangani.
- Registry aktif: sedikitnya 2 trust-root signature dari person berbeda dan trust-root SHA yang diberikan secara independen ke validator.
- Verifikasi historis: envelope selalu memakai snapshot registry yang disebutkannya; key revisi baru tidak berlaku mundur, sedangkan revocation/suspension baru tidak menghapus signature historis yang dibuat sebelum waktu efektif.
- Aktivasi registry: `max(snapshot_at, seluruh trust-root signed_at/verified_at yang diwajibkan)`; semua signature non-root harus berada pada atau sesudah aktivasi snapshot yang diikatnya.
- Reviewer receipt: payload receipt tertutup menandatangani digest payload yang direview, reviewer/key/purpose, author, implementer, metode/referensi, timestamp, dan binding snapshot registry; bukan sekadar menandatangani ulang business payload.
- Test boleh membuat ephemeral private key hanya di memori. Repository, fixture JSON, log, dan bukti tidak boleh menyimpan private key.

## Bukti yang akan diperlukan

1. Payload appointment kanonis, acceptance appointee, signature issuer, dan registry receipt reviewer independen.
2. Event delegation/suspension/resumption/revocation append-only dengan rantai prior-event SHA.
3. Vote yang mengikat row/register/policy/appointment/evidence/control/prior-decision SHA.
4. Canonical session receipt yang ditinjau oleh identitas berbeda dari subject, evidence author, implementer, dan seluruh decision signer.
5. Prefix root + count untuk snapshot appointment/event historis; penambahan log sesudah cutoff boleh, perubahan prefix ditolak.
6. Rantai `prior_session_sha256` dan `prior_decision_sha256` yang linear untuk amendment; fork, parallel final, dan stale prior digest ditolak.
7. Snapshot kebijakan dan register keputusan A–G yang bernomor serta berantai: ID/revisi/timestamp/cutoff, content/control root, predecessor reference, dan predecessor SHA. Sesi lama mengikat snapshot lama; revisi keputusan baru mengikat snapshot baru.
8. Bukti kronologi kausal source capture → policy snapshot → sponsor → reviewer → appointment signatures → effective time → vote → session receipt.
9. Metadata appointee dari snapshot registry historis yang sama untuk acceptance, issuer, dan reviewer; pembaruan metadata berikutnya tidak mengubah sejarah.

Menambahkan file di direktori ini tidak dengan sendirinya membuat penunjukan atau keputusan sah. Register terstruktur dan seluruh binding harus lolos validator.
