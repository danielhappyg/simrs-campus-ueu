# Wilayah Indonesia source

- Upstream: https://github.com/ibnux/data-indonesia
- Purpose: teaching cascade for Pendaftaran (Provinsi → Kabupaten/Kota → Kecamatan → Kelurahan)
- Imported into Postgres tables `wilayah_*` via `php artisan wilayah:import`
- Runtime API reads the database, not these JSON files

Fetch locally with `scripts/fetch-ibnux-wilayah.sh` before importing.
