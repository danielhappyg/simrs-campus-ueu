# Role and Permission Catalogue

Date: 2026-08-21

## Account boundary

UEU confirmed that `DOSEN RMIK UEU` is intentionally a combined lecturer–administrator account. Its broad administrative menu is therefore expected. This catalogue evaluates governance and separation from student access, not whether lecturers should have no administration capability.

## Group reconciliation

- Groups: **65**.
- Groups with at least one displayed membership: **40**.
- Empty groups: **25**.
- Displayed memberships: **537**; users can belong to multiple groups, so this is not a unique-user count.
- `DEFAULT (PERLU MAPPING)`: **5** memberships that require ownership and mapping review.
- `super admin IT`: **10** displayed memberships.
- `Super Administrator`: **17** displayed memberships.

| No. | Group | Displayed users |
|---:|---|---:|
| 1 | Akuntan & Inkaso | 0 |
| 2 | Ambulance | 6 |
| 3 | Bank Darah | 5 |
| 4 | BENDAHARA | 0 |
| 5 | Casemix | 4 |
| 6 | DEFAULT (PERLU MAPPING) | 5 |
| 7 | Direktur | 3 |
| 8 | Dokter & Perawat Gigi | 1 |
| 9 | Dokter IGD | 18 |
| 10 | Dokter Rajal & Ranap | 34 |
| 11 | Dokter Rajal & Ranap ICU | 2 |
| 12 | Farmasi | 39 |
| 13 | Farmasi Gudang | 3 |
| 14 | Farmasi Koor | 0 |
| 15 | GIZI | 9 |
| 16 | IBS | 23 |
| 17 | informasi | 1 |
| 18 | INSTALASI PERSALINAN | 1 |
| 19 | KABID KEPERAWATAN | 2 |
| 20 | Kamar Jenazah | 3 |
| 21 | Karu Ranap | 1 |
| 22 | Kasi Medis | 1 |
| 23 | Kasir | 5 |
| 24 | Kasir IGD | 0 |
| 25 | kasir Magang | 0 |
| 26 | kepala kasir | 0 |
| 27 | Kepala RM | 0 |
| 28 | Keuangan | 6 |
| 29 | Klaim IPP | 12 |
| 30 | Koor Marketing/Humas | 0 |
| 31 | Laboratorium | 22 |
| 32 | Laboratorium PA | 0 |
| 33 | LAPORAN | 1 |
| 34 | Laporan Analisi | 2 |
| 35 | Magang | 0 |
| 36 | MAHASISWA UEU | 6 |
| 37 | Manager | 0 |
| 38 | Marketing/Humas | 1 |
| 39 | OK | 0 |
| 40 | Pembiayaan | 0 |
| 41 | Pendaftaran | 0 |
| 42 | Penetapan Biaya | 0 |
| 43 | Penjaminan | 0 |
| 44 | Perawat | 18 |
| 45 | Radiologi | 14 |
| 46 | Rajal | 0 |
| 47 | Ranap | 1 |
| 48 | Rawat Inap | 146 |
| 49 | Rawat Jalan | 57 |
| 50 | Rehab Medik | 5 |
| 51 | Rekam Medis | 18 |
| 52 | Rekam Medis Pelaporan | 6 |
| 53 | RM KLAIM | 0 |
| 54 | SITB | 1 |
| 55 | super admin IT | 10 |
| 56 | Super Administrator | 17 |
| 57 | TESRJ | 0 |
| 58 | UGD JANGMED | 0 |
| 59 | UGD KABID YANJANGMED | 0 |
| 60 | UGD YANMED | 0 |
| 61 | VK | 26 |
| 62 | Wadir | 0 |
| 63 | Wadir Keuangan | 0 |
| 64 | Wadir Pelayanan | 0 |
| 65 | yanmed | 2 |

## Student role: MAHASISWA UEU

- Displayed memberships: **6**.
- Permission nodes selected: **211 of 280**.
- The permission tree has 280 nodes while the lecturer-administrator shell exposes 268 visible items; the difference must be reconciled as hidden, legacy, conditional, or role-only nodes.

| Permission category | Selected | Total | Coverage |
|---|---:|---:|---:|
| PENDAFTARAN | 4 | 5 | 80% |
| PEMERIKSAAN | 14 | 20 | 70% |
| RM | 7 | 7 | 100% |
| KLAIM | 3 | 6 | 50% |
| LAPORAN | 125 | 125 | 100% |
| BPJS | 3 | 6 | 50% |
| APOTEK | 20 | 20 | 100% |
| GF | 23 | 23 | 100% |
| KASIR | 2 | 19 | 11% |
| MANAJEMENDATA | 9 | 46 | 20% |
| IOT | 0 | 1 | 0% |
| FARMASIIBS | 0 | 1 | 0% |
| HELP | 1 | 1 | 100% |

The student role includes all visible medical-record permissions, all pharmacy and warehouse-pharmacy permissions, all 125 report permission nodes, broad examination and registration permissions, and limited cashier/master-data access. This is a high-priority teaching-safety and least-privilege review item unless server-side denials, isolated synthetic data, or course-specific controls materially reduce effective access.

## Governance questions

1. Is the supplied lecturer-administrator credential a named personal account, a shared teaching account, or a service/demo account?
2. Are student accounts unique, time-bounded, cohort-bound, and automatically disabled after teaching use?
3. Do menus and server-side action checks use the same authorization policy?
4. Which roles can view, create, edit, delete, approve, print, export, integrate, and change settings?
5. Are privileged actions and permission changes logged immutably with actor, timestamp, before/after values, and source IP/device?
6. Can settings administration be separated from ordinary lecturer operation while retaining lecturer control of teaching workflows?

