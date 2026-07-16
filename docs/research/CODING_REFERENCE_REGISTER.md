# Coding Reference Register

- **Version:** 1.4
- **Evidence checked:** 16 July 2026
- **Scope:** diagnosis and procedure terminology for the SIMRS Campus UEU outpatient reference MVP
- **Product decision:** include computer-assisted coding with mandatory human confirmation
- **Clinical-use boundary:** synthetic teaching cases only; not a production coding authority, grouper, or claim engine

## 1. Decision recorded

Daniel directed that the system include an "auto coding" capability and that the two supplied e-klaim workbooks be used as references. The product response is **computer-assisted coding**: the system may generate ranked candidates from an existing clinician-authored diagnosis or procedure, but only an authorized RMIK coder can create a code assignment. No suggestion is silently promoted to a final code or claim input.

The implementation contract is defined in the [Computer-Assisted Coding Specification](../product/COMPUTER_ASSISTED_CODING_SPEC.md).

The current reference implementation uses the supplied ICD-10 release only for diagnosis sources and the supplied ICD-9-CM release only for completed performed-procedure sources. Candidate generation, manual alternatives, draft creation, coder submission, linked RMIK-supervisor approval, and stale-source invalidation preserve that separation; an encounter with recorded procedures cannot finalize until both diagnosis and procedure assignments are independently approved.

## 2. User-supplied workbook inventory

The raw workbooks remain outside Git until redistribution rights are confirmed. Their content was inspected read-only and compared with fresh exports from the official SATUSEHAT-linked public sheets.

| Reference ID | Supplied filename | Intended use | Sheet and schema | Populated rows | Observed release | Data-quality result | SHA-256 |
|---|---|---|---|---:|---|---|---|
| REF-COD-001 | `[PUBLIC] ICD-9CM e-klaim (1).xlsx` | Procedure/intervention candidate lookup | `ICD9 CM`; `CODE`, `DISPLAY`, `VERSION` | 4,626 | `ICD9CM_2010` | No blank populated fields, duplicate codes, exact duplicate rows, or formulas | `9f625ada077b198e75e5f6a51596191cb9de94be198a967cedf07a52e08f8d78` |
| REF-COD-002 | `[PUBLIC] ICD-10 e-klaim (1).xlsx` | Diagnosis candidate lookup | `ICD10`; `CODE`, `DISPLAY`, `VERSION` | 18,543 | `ICD10_2010` | 998 completely blank trailing rows must be ignored; no duplicate populated codes or formulas | `3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e` |

Observed workbook metadata identifies the creator as `Adiet` and does not itself contain a source URL or license. External provenance was therefore verified on 15 July 2026:

- the local ICD-10 workbook is byte-for-byte identical to a fresh export from the [public sheet linked by SATUSEHAT](https://docs.google.com/spreadsheets/d/12_72PvHRLWny3VEwodEI6TRDc7QqWdiF/edit?gid=0); both have SHA-256 `3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e` and the same 18,543 populated rows in the same order;
- the local ICD-9-CM workbook is byte-for-byte identical to a fresh export from the [public sheet linked by SATUSEHAT](https://docs.google.com/spreadsheets/d/1uw9aEzM61rsp7PVt7dRHt2eATg64qQB_/edit?gid=0); both have SHA-256 `9f625ada077b198e75e5f6a51596191cb9de94be198a967cedf07a52e08f8d78` and the same 4,626 populated rows in the same order.

This proves the supplied files match the current official-linked reference exports. It does not by itself grant permission to republish the raw workbooks in this repository.

## 3. Official cross-check

| Source | Current observation | Product consequence |
|---|---|---|
| Ministry of Health, [Permenkes 26/2021 on INA-CBG](https://jdih.kemkes.go.id/documents/peraturan-menteri-kesehatan-nomor-26-tahun-2021) | JDIH listed the regulation as `Berlaku` when checked. Its coding chapter uses ICD-10 2010 for diagnoses and ICD-9-CM 2010 for procedures and identifies the medical resume/record as the coding source. | The supplied release labels align with the current claim-coding reference, but a code must remain linked to clinician-authored source evidence. |
| Ministry of Health, [SATUSEHAT terminology guide](https://satusehat.kemkes.go.id/platform/docs/id/terminology/) | SATUSEHAT identifies ICD-10 2010 as the diagnosis standard and ICD-9-CM 2010 as the procedure standard. | Store classification system and version explicitly; keep diagnosis and procedure catalogs separate. |
| Ministry of Health, [SATUSEHAT ICD-10 page](https://satusehat.kemkes.go.id/platform/docs/id/terminology/icd/icd-10/) and [ICD-9-CM page](https://satusehat.kemkes.go.id/platform/docs/id/terminology/icd/icd-9-cm/) | Each official page links the public sheet whose export exactly matches Daniel's supplied workbook. | Treat the two checksummed files as verified development reference releases while keeping raw redistribution separately governed. |
| Ministry of Health, [SATUSEHAT terminology standard history](https://satusehat.kemkes.go.id/platform/docs/id/terminology/standar-terminologi/) | The living terminology specification was version `v10.3`, updated 30 June 2026, when checked. | Treat integration terminology as a replaceable, versioned release and recheck before external integration or pilot release. |
| Ministry of Health, [SATUSEHAT Procedure resource](https://satusehat.kemkes.go.id/platform/docs/id/fhir/resources/procedure/) and [HL7 FHIR R4 Procedure](https://hl7.org/fhir/R4/procedure-definitions.html) | Procedure represents an action actually performed, separately links an originating ServiceRequest, and carries status, encounter, performed time, recorder/asserter, and performer; SATUSEHAT uses ICD-9-CM for the procedure code. | Require an explicit clinician attestation, completed status, authored statement, performed time, and performer before procedure coding; never convert an order into a performed procedure. |
| WHO, [ICD-10 2010 instruction manual](https://icd.who.int/browse10/Content/statichtml/ICD10Volume2_en_2010.pdf) | The publication contains a WHO copyright and permission notice. | Do not assume that a third-party spreadsheet can be redistributed merely because it is downloadable or labelled public. |
| WHO, [ICD implementation guidance](https://www.who.int/standards/classifications/classification-of-diseases/icd-implementation) | The implementation roadmap calls for phased piloting, comparison/validation, audits, monitoring, stakeholder preparation, and training. | Evaluate and review the reference behavior before enabling a faculty pilot; preserve a named approval checkpoint. |
| Wang et al., [entity-linking methods for normalizing diagnosis and procedure terms to ICD codes](https://pubmed.ncbi.nlm.nih.gov/32298846/) | The primary study separated candidate generation from ranking, used manually annotated diagnosis/procedure datasets, reported performance at different cutoffs, and measured the effect of a synonym knowledge base. | Report top-1/top-5 separately by source type; keep multilingual aliases versioned and outside approved metrics until expert validation. Do not transfer the study's performance figures to this system. |

## 4. Development-use decision

The supplied workbooks may be used to:

- define and test the import contract;
- populate a local development terminology release after validation;
- test code search, ranking, version display, and source-linked suggestion workflows; and
- construct synthetic teaching cases and evaluation fixtures.

They must not yet be:

- committed to the repository;
- redistributed through Git or release artifacts without confirmed permission;
- treated as a complete coding manual, alphabetical index, inclusion/exclusion rule set, or grouper;
- used to produce an automatically final code; or
- sent to a production claim or SATUSEHAT endpoint.

Before a faculty pilot, download the official-linked exports again, compare hashes and normalized rows, document any change, and record the permitted institutional use.

## 5. Implemented import and release controls

The reference importer now:

1. accept only an explicitly selected code-system type;
2. verify the exact header contract and file checksum;
3. trim text and ignore only completely blank rows;
4. reject partial blank rows, duplicate codes within a release, unexpected versions, invalid code formats, and conflicting displays;
5. stage all rows before an atomic release activation;
6. preserve the raw-file hash, supplied filename, import actor/time, row counts, validation report, and release status;
7. prevent modification of an activated release; and
8. never rewrite historical code assignments when a new release is activated.

On 15 July 2026, both supplied files were imported into the local synthetic-development database through this contract. The active release records preserve the exact hashes and counts in section 2; spot checks returned ICD-10 `R42 — Dizziness and giddiness` and ICD-9-CM `47.0 — Appendectomy`. This is development evidence, not permission to redistribute the raw files or use the catalog as an autonomous coding authority.

On 16 July 2026, the first versioned synthetic retrieval baseline was executed against those exact active releases. Its metric-eligible catalog/safety assertions passed with zero reference misses, while eight of eleven proposed stress/Indonesian expectations remained gaps and were excluded from the reference metric. See the [Synthetic Coding Retrieval Baseline](../operations/CODING_GOLD_SET_BASELINE.md). No clinical accuracy threshold or alias approval was inferred from that result.

## 6. Revalidation triggers

Recheck this register when:

- SATUSEHAT or INA-CBG/IDRG terminology requirements change;
- an official replacement dataset becomes available;
- Daniel approves a different curriculum release or coding depth;
- a workbook hash changes;
- the institution confirms redistribution/licensing terms; or
- the system moves beyond synthetic teaching use.
