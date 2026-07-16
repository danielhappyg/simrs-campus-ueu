# Synthetic Coding Retrieval Baseline

- **Evaluation date:** 2026-07-16
- **Gold set:** `outpatient-reference-v1` / `1.0.0-draft.1`
- **Dataset SHA-256:** `61a08d53865c359d76032bdc127d5670c46eab0736f4b6474f3478d5144b6443`
- **Status:** draft; expert validation required
- **Mode:** synthetic teaching data only
- **Engine:** `DETERMINISTIC_LEXICAL` / `coding-reference.v1`
- **Final threshold authority:** Daniel Happy Putra after RMIK and medicine review

## What this baseline proves

The evaluator runs the same bounded terminology-search service used by diagnosis and performed-procedure suggestions against the exact active releases required by the dataset:

| System | Release | Rows | Required SHA-256 |
|---|---|---:|---|
| ICD-10 | `ICD10_2010` | 18,543 | `3c22aa15012dd2e15576657e49001291fd21a5b30ce797998a495aac548c5f4e` |
| ICD-9-CM | `ICD9CM_2010` | 4,626 | `9f625ada077b198e75e5f6a51596191cb9de94be198a967cedf07a52e08f8d78` |

It validates the dataset schema, synthetic-only marker, source/classification pairing, code membership, release version, row count, and checksum before evaluating any case. It performs no clinical write, suggestion-run creation, assignment creation, alias activation, or audit event.

This is a **retrieval baseline**, not a clinical coding-accuracy claim. The metric-eligible `REFERENCE_ASSERTION` cohort contains checksummed catalog code/display pairs plus explicit negative and ambiguity controls. Proposed abbreviations, spelling variants, Indonesian phrases, and insufficient-specificity examples remain `PENDING_EXPERT_REVIEW` and are excluded from the reference metric.

## Reproducible result

| Source type | Eligible cases | Target top-1 | Target top-5 | No-candidate safety | Review-safety | Reference misses |
|---|---:|---:|---:|---:|---:|---:|
| Diagnosis / ICD-10 | 8 | 7/7 (100.0%) | 7/7 (100.0%) | 1/1 | — | 0 |
| Performed procedure / ICD-9-CM | 8 | 6/6 (100.0%) | 6/6 (100.0%) | 1/1 | 1/1 | 0 |

There are 27 cases in total: 16 metric-eligible reference assertions and 11 proposals awaiting expert review. Nineteen current expectations are observed and eight are gaps. The eight gaps are deliberately excluded from the reference result:

| Proposed case | Current gap | Required next decision |
|---|---|---|
| `BPPV` → `H81.1` | No candidate | RMIK/medicine approve, revise, or reject abbreviation mapping. |
| `Pusing dan rasa melayang` → `R42` | No candidate | Validate Indonesian clinical phrase and acceptable ambiguity. |
| `Hipertensi primer` → `I10` | No candidate | Validate Indonesian term and curriculum depth. |
| `Nyeri punggung bawah` → `M54.5` | No candidate | Validate phrase and whether documentation is sufficiently specific. |
| `appendectmy` → `47.0` | No candidate | Decide whether controlled spelling aliases are permitted. |
| `Appendektomi` → `47.0` | No candidate | Validate Indonesian procedure term. |
| `Elektrokardiogram` → `89.52` | No candidate | Validate Indonesian procedure term. |
| `Terapi fisik lainnya` → `93.39` | No candidate | Physiotherapy/RMIK review is required before activation. |

The spelling-stress case `Dizzines and giddiness` currently retrieves `R42` at rank 1 with `REVIEW_REQUIRED`; the insufficient-specificity cases remain no-candidate or review-labelled. These observations are preserved without converting them into approved mappings.

## Regression found and corrected

The first baseline probe showed that a complete exact phrase could be excluded before ranking when common words generated more than 300 database candidates. The search service now constructs its bounded pool in three deterministic layers:

1. guaranteed exact code/display and versioned-alias matches;
2. bounded code/display prefix matches; and
3. bounded per-token candidates using meaningful terms before common fallback terms.

Ranking remains deterministic by score and code. When several candidates share the top score, their confidence band is `REVIEW_REQUIRED`, not `STRONG_MATCH`. The regression is covered with more than 300 noisy distractors and a tied generic-term test.

## Evaluation rationale and limits

Candidate generation and ranking should be evaluated separately from the final human coding decision. A primary entity-linking study evaluated diagnosis and procedure candidate generation on separately annotated datasets and reported results at different cutoffs; it also found that a synonym knowledge base materially changed candidate coverage. That supports separate top-1/top-5 reporting and a controlled alias gate, but its results are not transferred to this Indonesian teaching system. [Wang et al., 2020](https://pubmed.ncbi.nlm.nih.gov/32298846/)

WHO implementation guidance calls for phased piloting, comparison/validation, audits, and monitoring rather than an unreviewed direct transition. The reference MVP therefore reports observed behavior first and leaves the pilot threshold and terminology approval to the named checkpoint. [WHO ICD implementation guidance](https://www.who.int/standards/classifications/classification-of-diseases/icd-implementation)

This baseline does not prove:

- clinical correctness of any proposed Indonesian phrase;
- general accuracy on real clinician documentation;
- safe autonomous assignment, grouping, billing, or claim submission;
- an approved pilot threshold; or
- permission to use real patient data.

## Reproduction

With the two checksummed releases active locally:

```bash
php artisan coding:evaluate-gold-set --fail-on-reference-miss
php artisan coding:evaluate-gold-set --json
```

`--fail-on-reference-miss` fails only when a metric-eligible reference assertion regresses. Pending expert cases remain visible gaps without failing the reference contract. The command never activates aliases and never treats a passing retrieval result as a final code assignment.
