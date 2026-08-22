# EduFlow Institute Suite 8.1.0-RC2

Complete consolidated export based on the full 8.0.0-RC1 production plugin.

## Included
- Preserves complete EduFlow admin menu and all existing operational modules.
- One-click Admission Approve now orchestrates student conversion, initial payment creation/verification, access activation and account invitation when email exists.
- Initial admission payment is idempotent and does not double-count Amount Paid.
- Admission form adds Payment Method, Transaction/UTR, Access Period and Custom Access End.
- Reuses the already-connected YTC Google Meet & Calendar Automation v1.3.1 when available; no second OAuth is required.
- Google System Health reports the YTC bridge provider as connected.
- Canonical Google event mapping remains in EduFlow for duplicate prevention.
- Existing database/data remain non-destructive; dbDelta only adds missing admission columns.

## Production note
Keep YTC Google Meet & Calendar Automation active while this bridge is in use. Legacy retirement remains manual until reconciliation passes.
