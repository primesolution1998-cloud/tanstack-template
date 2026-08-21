# EduFlow Institute Suite 8.0.0-RC1

The plugin keeps the `eduflow-core` folder and internal identifiers for uninterrupted WordPress upgrades. `8.0.0-RC1` is the single release-candidate version used in both the plugin header and package filename.

Phase 7 provides a schema-adapter-based, dry-run-first Legacy Migration Center with source fingerprinting, conflict review, resumable jobs, audit logs, and controlled-cutover readiness. Legacy sources are queried read-only and unknown schemas are never guessed.

Phase 6 adds manual attendance, delivery-ready notifications, institute analytics, teacher workload reporting, and protected CSV exports while preserving the shared batch/demo Google event model.

EduFlow Core 5.0.0 is the reusable, multi-institute foundation for the EduFlow Institute Suite. Phase 1 contains institute configuration, capability-based roles, atomic canonical IDs, audit records, health diagnostics, an idempotent internal job queue, migration scaffolding, and a private REST health endpoint.

## Installation

Install `eduflow-core-v5.0.0.zip` through **Plugins → Add New → Upload Plugin**, activate it, then visit **EduFlow → Settings**. Activation creates only tables prefixed with the current WordPress `$wpdb->prefix`. Deactivation and uninstall preserve all institutional data.

## Security and tenancy

The health endpoint (`GET /wp-json/eduflow/v1/health`) requires `eduflow_manage_institute`. Services accept an institute database ID rather than embedding a customer identity. YTC Education is only the initial configuration. Teacher and student roles receive only own-class and own-profile capabilities; future modules must additionally validate record ownership.

## Extension points

Job handlers attach to `eduflow_run_job_{job_type}` and must be idempotent. Audit IP collection is opt-in through `eduflow_audit_ip_address`. Migration stages are declarations only: this release performs no legacy detection or import.

## Data retention

No foreign-key cascade deletion is used. The plugin never edits legacy plugin tables. Automatic uninstall deletion is deliberately disabled.


## Phase 2 operations

Admissions accept normalized Indian mobile numbers without requiring email. Approval-to-student conversion and payment-to-access application are transactionally idempotent. Payment verification records access activation or renewal history, while the hourly queue runner expires elapsed access. WordPress account creation remains deliberately separate and never creates passwords or placeholder email addresses.


## Phase 3 scheduling

Teacher availability, batch enrollment, preserved assignment history, recurring lectures, conflict checks, rescheduling and cancellation now extend the same institute-scoped services. Lecture timestamps are stored in UTC alongside the originating timezone. Meet URLs and external event IDs remain null until a real Phase 4 integration supplies them.


## Phase 4 Google Calendar and Meet

Institute administrators can configure OAuth 2.0 under **EduFlow → Settings → Google Integration**. Secrets and OAuth tokens are encrypted at rest and never rendered back into forms. Upcoming lecture jobs create or update real Google Calendar events with official conference data; returned Meet and event URLs are retained only after a successful Google response. No Google Sheets behavior is included.

The private Meet endpoint is `GET /wp-json/eduflow/v1/classes/{id}/meet`; it returns a URL only after validating the current administrator, linked teacher, or actively assigned student against the institute and lecture.


## Canonical demo time slots

Demo bookings are grouped by a canonical key derived from institute, date, normalized start/end times, and mentor. A slot owns one Demo Session ID and one EduFlow lecture, so every individual booking shares its single Google Calendar event and Meet URL. Bookings keep separate contact, attendance, feedback, follow-up, and conversion state. Moving a booking updates both sessions' attendee sync without creating a per-booking event.


## Phase 5 portals and control center

Authenticated frontend portals are available at `/?eduflow_portal=student` and `/?eduflow_portal=teacher`, or through `[eduflow_student_portal]` and `[eduflow_teacher_portal]` shortcodes. Portal data is resolved through institute-scoped account links. Student Meet access additionally requires an active access record whose date window includes today.

Managers use **EduFlow → Control Center** for operational metrics, cross-module search, existing module actions, account linking, secure student activation, and payment-backed renewals. Notifications are idempotent records with portal, email-ready, and WhatsApp-ready channels; Phase 5 does not send through a WhatsApp provider.
