## Outcome

<!-- What user or system outcome does this change deliver? -->

## Scope

<!-- Summarize the workflow, modules, and files affected. -->

## Safety and data boundary

- [ ] Uses synthetic data only
- [ ] Contains no credentials, keys, tokens, or personal data
- [ ] Contains no raw ICD workbook, database/dump, runtime log, or unapproved presentation artifact
- [ ] Authorization and audit implications were considered
- [ ] Integration status is labelled honestly as simulation, sandbox, or production
- [ ] Staged files were reviewed explicitly; user-owned `deliverables/` content was excluded unless separately approved

## Verification

<!-- List automated and manual checks. Add screenshots for visible changes. -->

- [ ] SQLite/backend checks
- [ ] PostgreSQL 17 integration checks using the private `laravel` schema (Supabase deployment target)
- [ ] React/TypeScript/accessibility checks
- [ ] Formatting, static analysis, production build, and Markdown checks
- [ ] Remaining manual limitations are stated without converting them into passes

## Deployment and rollback

<!-- Describe migrations, configuration, release order, and rollback/recovery. -->

## Reviewers

<!-- Name the product, technical, and discipline reviewers required. -->
