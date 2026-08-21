# New SIMRS Rebuild Documentation

Status: planning baseline  
Created: 2026-08-21  
Scope: clean-slate, tool-agnostic reconstruction of the complete vendor SIMRS followed by user-led improvement

## Start here

Read `MASTER_REBUILD_BLUEPRINT.md` first. It defines the strategy, evidence rules, phases, gates, ownership and relationship between legacy parity and later improvements.

Phase 0 working artifacts (owners, decisions, reuse map, evidence index): [`phase-0/README.md`](phase-0/README.md).

## Documentation map

| Document | Purpose | Primary readers |
|---|---|---|
| `MASTER_REBUILD_BLUEPRINT.md` | Authoritative program strategy and operating model | UEU sponsors, product owner, program lead, vendor/technical leads |
| `PARITY_REQUIREMENTS_MATRIX.md` | Traceability baseline for all 268 old-system menus | Product, analysts, developers, testers, lecturers |
| `PRODUCT_AND_PARITY_STRATEGY.md` | Defines parity, scope, vertical slices and product rules | Product owner, lecturers, analysts |
| `DELIVERY_ROADMAP.md` | Phases, milestones, dependencies and release gates | Program and delivery teams |
| `REQUIREMENTS_GOVERNANCE.md` | Requirements lifecycle, evidence labels and change control | Product, governance, QA |
| `TOOL_SELECTION_GUIDE.md` | Technology-neutral evaluation, proof scenario and scoring matrix | Sponsors, architecture, engineering, procurement |
| `PROJECT_TEAM_AND_RACI.md` | Required roles, domain ownership and decision responsibilities | Sponsors, program and workstream leads |
| `REFERENCE_ARCHITECTURE.md` | Tool-agnostic target architecture and bounded contexts | Architects and engineering teams |
| `DATA_AND_INTEGRATION_STRATEGY.md` | Data model, master data, APIs, events and external systems | Data, integration and engineering teams |
| `SECURITY_PRIVACY_AND_AUDIT.md` | Identity, authorization, privacy, secrets and audit controls | Security, compliance, engineering |
| `OPERATIONS_AND_RELIABILITY.md` | Environments, observability, release, backup and recovery | Platform, operations, support |
| `TESTING_AND_UAT_STRATEGY.md` | Test levels, synthetic scenarios, parity UAT and quality gates | QA, users, engineering |
| `DATA_MIGRATION_AND_CUTOVER.md` | Data classification, migration, rehearsal, rollback and cutover | Data, operations, governance |
| `USER_RESEARCH_AND_CHANGE_MANAGEMENT.md` | Post-parity feedback, pilots, training and adoption | Product, lecturers, change leads |
| `RISK_REGISTER.md` | Program, product, technical and adoption risks | Sponsors and all workstream owners |
| `GLOSSARY.md` | Shared terminology and evidence language | Everyone |
| `DOCUMENTATION_QA.md` | Completeness, link, traceability and credential-safety checks | Program, QA and reviewers |
| `MASTER_EXECUTION_PROMPT.md` | Copy-paste orchestration prompt for Codex, Claude Code, Cursor or another coding tool | Product owner and implementation lead |
| `templates/` | Repeatable requirement, decision, test and feedback records | All workstreams |

## Source-of-truth order

When documents conflict, use this order:

1. approved architecture or product decision record;
2. approved requirement and acceptance criteria;
3. verified legacy-system evidence;
4. current regulatory/integration specification;
5. approved user-research decision after parity;
6. proposal or inference.

The old vendor system is the functional reference for parity, not the technical architecture to copy.

## Non-negotiable boundaries

- Use synthetic or specifically approved de-identified data during development and teaching.
- Never carry old passwords, API keys, certificates or secret values into the new platform.
- Preserve workflow familiarity for parity, but do not reproduce known security, audit, external-HTTP or maintainability defects.
- A visible old menu is not automatically a requirement to reproduce a broken, duplicate or unused implementation. It must be classified and approved.
- Parity defects and new feature requests remain separate until the parity gate is passed.
- No production or external-health integration is enabled without explicit environment approval and successful sandbox validation.

## Maintenance rule

Every material change must update the relevant requirement, decision record, acceptance test and traceability entry in the same change. Documentation is part of the deliverable, not a later administrative task.
