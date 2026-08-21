# EduFlow Institute Suite 8.0.0-RC1

This is the V1 release candidate. The public product name is **EduFlow Institute Suite**. The stable WordPress folder, slug, constants, database prefix, and PHP class prefix remain `eduflow-core` / `EDUFLOW_CORE_*` / `eduflow_*` to preserve upgrades from Phases 1–7. The package and plugin header both use `8.0.0-RC1`; there is no parallel `1.0.0` package version.

## Phase 8 hardening

- Existing EduFlow roles are now reconciled on upgrade, not merely created; Teacher and Student retain only own-profile/own-class EduFlow capabilities.
- The job runner has an atomic option lock, stale-running-job recovery, bounded claims, and fails unknown job types instead of silently completing them.
- Google mappings now add an institute-scoped unique Google Event ID constraint while retaining one mapping per lecture and stable batch/demo sharing.
- Migration imports use per-source-record MySQL advisory locks to prevent concurrent runs importing one legacy source twice.
- System Health now reports overall HEALTHY/WARNING/ACTION REQUIRED, schema, roles, IDs, cron lock, jobs, Google, notifications, migration conflicts, institute configuration, and security warnings without secrets.
- A paginated, institute-scoped Audit Log completes the final admin menu.
- Static hardening checks cover dangerous execution functions, fake identifiers, secrets, dynamic prefixes, write-action nonces, REST permissions, and excluded Google Sheets behavior.

## Production rollout

Before install or upgrade, create and verify a full database backup, back up WordPress files, and record every active plugin/version. Upgrade the existing `eduflow-core` folder in staging first; do not uninstall it. Verify activation, schema/index state, capabilities, cron, portals, payments/access, shared batch/demo Meets, attendance, notifications, reports, and Migration Center before controlled production rollout.

## Rollback

If live validation fails, deactivate this release candidate, restore the previous `eduflow-core` ZIP, and re-test. Institutional data is intentionally retained on deactivation and uninstall. Restore the database only when an observed schema/data issue requires it and only from a verified backup. There is no destructive automatic rollback and no automatic legacy-plugin deactivation.

## Release gate

Recommendation: **READY WITH LIVE TESTS**. Source/domain checks and packaging pass, but a real WordPress/MySQL/browser environment and live Google credentials were unavailable. Production deployment remains gated on the staging checklist above.

## Previous Phase 7 release

Phase 7 adds a read-only Legacy Migration Center and production cutover readiness layer.

- Defensive table detection reports unknown YTC, BatchFlow, ClassFlow, and EduFlow-like schemas as manual mapping required rather than guessing columns.
- Reusable admission, student, teacher, payment, batch, class, demo, Meet, and generic table adapters are registered through reviewed source definitions.
- Mandatory dry runs normalize, validate, reconcile, fingerprint, and classify records without business-table writes or canonical sequence consumption.
- Real imports require explicit backup confirmation and execute as resumable, idempotent batches through the existing job queue.
- Migration maps now retain target database IDs and source fingerprints; changed sources become conflicts instead of silently overwriting canonical data.
- Dedicated run and conflict tables support progress, failures, manual audited resolutions, logs, and cutover status.
- Student and teacher reconciliation uses mapping, canonical ID, normalized mobile, and valid email; names alone never merge people.
- Unknown schemas have no import action until a reviewed adapter definition and import callback are installed.
- No legacy tables, plugin files, Google events, WordPress accounts, roles, or records are changed by detection or dry-run operations.

## Repository detection result

No legacy YTC/EduFlow plugin implementation or documented legacy schema exists in this repository. Therefore Phase 7 deliberately ships no fabricated table-to-field mapping. A staging database may reveal candidate tables, which the Migration Center reports as unsupported until their schema is reviewed and registered.

## Controlled migration procedure

1. Clone production files and database into staging and create a separate verified backup.
2. Activate/upgrade EduFlow Core, verify System Health, and run Detect Legacy Data.
3. Review candidate schemas and register explicit source adapter definitions outside legacy plugins.
4. Run dry runs in dependency order; resolve every ambiguity and verify counts with source owners.
5. Confirm a fresh production backup, run selected entity imports in dependency order, and monitor batched run logs.
6. Re-run dry runs, validate canonical counts, access, payments, shared batch/demo Meets, portals, and reports.
7. Proceed only when readiness reports READY FOR CONTROLLED CUTOVER. Legacy plugin deactivation remains a separate manual decision.

## Previous Phase 6 release

Phase 6 adds institute-scoped student and demo attendance, teacher class operations and workload reporting, queued notifications, operational analytics, and authorized CSV exports.

- Canonical attendance records enforce one student per lecture and one record per demo booking.
- Manual batch and 1-to-1 rosters work without Google Meet attendance data; teacher ownership and manager capabilities are checked server-side.
- Student Portal attendance excludes cancelled/not-marked records from its documented denominator.
- Notifications now use pending/queued/sent/failed/read/cancelled lifecycle fields, deterministic deduplication, WordPress email, and a provider-neutral WhatsApp hook.
- Attendance, Notifications, and Reports admin screens plus private REST resources reuse existing EduFlow services.
- Aggregate institute-scoped reports cover students, attendance, teachers, classes, demos, payments, access, renewals, and failures.
- CSV export is capability/nonces protected and excludes secrets and private configuration.

## Phase 7 migration requirements

- Legacy attendance and notification sources require explicit detection, dry-run mapping, conflict review, import verification, and retirement reporting through the existing Migration framework.
- No legacy data is read, changed, imported, or deleted by Phase 6.
- Google Sheets remains excluded.

## Previous Phase 5 release

Phase 5 adds secure Student and Teacher portals plus a Manager Control Center while preserving shared Batch and canonical Demo Meet mappings.

- Frontend Student Portal with own profile, batch/teacher, entitlement, schedule, shared Meet access, and notifications.
- Frontend Teacher Portal restricted to assigned batch, 1-to-1, trial, and canonical demo lectures.
- Separate secure account linking with canonical ID/mobile login resolution and WordPress password-reset activation.
- Meet authorization validates authentication, institute, assignment, ownership, class status, student status, active access dates, and real synced Meet availability.
- Manager Control Center adds operational metrics, cross-module search, service-backed actions, account linking, activation, and payment-backed renewal.
- Demo sessions support mentor/time rescheduling against the same Google mapping, booking cancellation, and idempotent conversion to Admission.
- Idempotent notification foundation supports portal, email-ready, and WhatsApp-ready delivery states.
- Private portal REST resources and a signed demo-prospect Meet endpoint prepare the same services for mobile clients.

## Known limitations and Phase 6 integration points

- Email-ready and WhatsApp-ready are delivery states only; outbound provider adapters and delivery receipts are Phase 6 work.
- Student activation requires a valid email; mobile-only students remain fully supported in Student Master and can be linked manually to an existing secure WordPress account.
- Portal URLs use query endpoints or shortcodes; branded institute routing and theme templates are future presentation work.
- Live WordPress role/login, browser responsiveness, MySQL concurrency, mail delivery, and Google attendee behavior require staging validation.
- Google Sheets remains explicitly excluded.
