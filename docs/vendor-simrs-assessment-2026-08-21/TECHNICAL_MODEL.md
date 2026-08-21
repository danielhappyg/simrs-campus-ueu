# Technical Model of the Vendor SIMRS

Assessment date: 2026-08-21

## Executive model

The observed system is a stateful, server-rendered PHP web application behind Apache on Ubuntu. A persistent authenticated application shell exposes 13 business categories, while functional pages use controller/action-style URLs and many POST-backed grid and process endpoints. The interface uses legacy jQuery/jQuery UI conventions, modal/dialog forms, and server-generated HTML. Newer functional generations coexist with older routes rather than visibly replacing them.

This model is evidence-bounded. It does not claim a confirmed framework, database engine, physical topology, or source-code architecture.

## Logical architecture

```text
Browser
  |  HTTPS, session cookie, server-rendered HTML, jQuery/AJAX forms
  v
Apache 2.4.58 on Ubuntu
  |
  v
PHP 8.3.6 application
  |-- registration controllers
  |-- examination/clinical controllers
  |-- medical-record controllers
  |-- claims/BPJS controllers
  |-- pharmacy and central-warehouse controllers
  |-- cashier/revenue-cycle controllers
  |-- reporting controllers
  |-- master-data, access, settings and log controllers
  |
  +--> unknown relational database and file/document stores
  +--> print/PDF/TTE services
  +--> BPJS/VClaim/Antrol/Aplicares
  +--> SATUSEHAT FHIR
  +--> E-Klaim/iDRG
  +--> LIS/PACS
  +--> RS Online/SIRS/SIRANAP
  +--> WhatsApp, ERP/accounting, IoT, OpenAI/Aisha
```

## Observed implementation characteristics

| Layer | Evidence | Interpretation |
|---|---|---|
| Web server | Apache 2.4.58 (Ubuntu) response header | Confirmed exposed server/version, not the full topology. |
| Runtime | PHP 8.3.6 response header | Confirmed exposed PHP version, not the application framework. |
| Client | jQuery 1.7.2 and jQuery UI 1.8.x assets | Legacy browser/UI dependency surface that needs an SBOM and upgrade plan. |
| Session | Secure and HttpOnly `SIMRS-SMC` cookie, four-hour lifetime; SameSite not observed | Stateful browser session. Complete policy and server-side invalidation remain unknown. |
| Routing | Controller/action paths such as `/module/controller/grid` and `/process_form` | Strong evidence of server-rendered modular controllers and POST-backed grid/form actions. |
| UI composition | Application shell, modal/dialog screens, many forms and grids | Conventional legacy rich web application rather than a modern separated SPA/API client. |
| Module generations | v2, v3, EMR IPP, iDRG and legacy routes coexist | Suggests incremental evolution and possible parallel code/data paths. Canonical versions are unknown. |
| Deployment operations | Dashboard warns that `application/logs/git.log` is absent and refers to `autoupdate.sh` cron | Indicates a Git/script-based update mechanism or health check, but actual release governance is unknown. |

## Module and transaction structure

The full structural crawl covered 268 menu destinations. Of these, 265 same-origin screens loaded; the external cleartext admission display was not opened; the manual PDF was acquired separately; and the Log Activity screen was observed from the shell but failed on direct navigation.

Every loaded registration, examination, medical-record, claim, BPJS, pharmacy, warehouse and reporting destination exposed form/input structure. The predominant pattern is:

```text
menu route -> filter/search form -> POST grid action -> row/dialog form
          -> POST process action -> refreshed grid/report/print output
```

This pattern supports a coherent modular application, but it also means menu visibility alone cannot prove server-side action authorization, validation, transaction isolation, audit logging, or safe correction/reversal behavior.

## Conceptual data model

The complete workflow requires, at minimum, these related records:

- patient/master identity and duplicate/merge history;
- registration/encounter and queue state;
- admission, ward/class/bed occupancy and transfer history;
- clinical assessments, diagnoses, procedures, orders, results and signatures;
- prescriptions, dispensing, returns and medication/stock ledgers;
- tariffs, charge items, bills, receipts, receivables and settlements;
- coding, record completeness, filing/custody and amendments;
- claims, grouping, submission, adjudication and payment status;
- external-integration messages, retries, acknowledgements and reconciliation;
- user, group, permission, session and audit events;
- hospital, unit, clinician, service, diagnosis/procedure, payer, supplier and document-template master data.

The actual schema, database technology, identifiers, foreign keys, locking, soft-delete rules, history tables, encryption, tenancy, and data-retention implementation are unknown.

## Integration model

The Settings form and menus expose a wide integration surface: BPJS/VClaim/Antrol/Aplicares, SatuSehat, E-Klaim/iDRG, LIS, PACS, TTE/e-sign, RS Online/SIRS/SIRANAP, PDF/FTP, WhatsApp, ERP/accounting, IoT, and OpenAI/Aisha. Presence is not operational proof.

Observed state is mixed:

- registration displayed VClaim as inactive;
- the bridging log contained historical BPJS Antrol targets;
- sampled appointment-service calls targeted a localhost URL and returned Not Found;
- SatuSehat, IoT and e-sign surfaces existed but sampled operational views were empty;
- secret material for SatuSehat and OpenAI was retrievable from editable Settings controls.

Each integration therefore needs separate proof of environment, credentials, endpoint, message contract, success and failure traces, retry/idempotency, reconciliation, monitoring, and ownership.

## Security and trust boundaries

Key boundaries are:

1. public pages versus authenticated functions;
2. lecturer–administrator versus student accounts;
3. ordinary administration versus secret/configuration administration;
4. clinical/financial/stock data versus reporting and export;
5. local application versus vendor/external APIs;
6. teaching/synthetic data versus any historical, seeded, or identifiable data;
7. application logs versus audit evidence and vendor support access.

The combined lecturer–administrator role is intentional. The principal authorization questions are student least privilege, named-account governance, server-side action enforcement, segregation of especially sensitive settings, and immutable auditability.

## Unknown physical and operational architecture

The browser cannot establish:

- server count, hosting provider, region, network segmentation, WAF or load balancer;
- database engine/version, replicas, storage encryption, key management or connection security;
- container/VM topology, OS hardening, monitoring, EDR, vulnerability management or patch SLA;
- background queues, cron jobs, message brokers and job-failure handling;
- source repository ownership, CI/CD, code review, dependency scanning or release signing;
- backup schedule, off-site/immutable copies, RPO/RTO or restore-test evidence;
- capacity, concurrency, latency, availability or failover behavior;
- support access, vendor remote administration, privileged-access management or incident response.

These are procurement evidence requirements, not optional implementation details.

## Architecture decision required before purchase

The vendor must identify the canonical module generation and provide a supported migration/retirement plan for legacy variants. UEU also needs a written separation between:

- a campus teaching configuration using only approved synthetic data and sandbox integrations; and
- any production-capable hospital configuration using real identities, health data, payer services, certificates or production APIs.

Without that boundary, a teaching system can unintentionally inherit production risk while still lacking production-grade controls.
