# Integration notes

This package integrates two source branches:

- `tpmsv1.10.0-modular-fixed` supplies the protected modular structure,
  compatibility URLs, local-secret configuration, chat, activity history, and
  maintenance boundaries.
- `TalaGuro-TPMS-ALS-multi-CLC-final` supplies the normalized school-program
  workflow and the teacher-to-many-CLC assignment model.

## Preserved compatibility

Root page URLs such as `teachers.php`, `schools.php`, `als.php`, and
`reports.php` remain thin public entries. Existing forms and JavaScript may
continue to call `actions/*.php`; those files delegate to the relevant module.
Internal `app/`, `config/`, `database/`, `includes/`, `modules/`, and `tools/`
folders remain blocked from direct web access.

The build retains My Activity from v1.10.0 while adding ALS
Centers, Requirement Planning, and Bulk Upload navigation from the ALS branch.

## Database boundary

The code expects schema migrations 001 through 005. Normal page requests only
verify required tables and columns; they do not silently modify the database.
Use the numbered SQL files for an existing database and use `setup.php` only
for a new installation.

The live database, uploaded personnel photos, production password, and
production encryption key are not included.

## Verification still required before production

1. Restore a current live database backup into staging.
2. Run migrations 001 through 005 against that staging database.
3. Configure `config/local.php` with the staging database and the same stable
   encryption key used by the current installation.
4. Test login, role and district selection, Teachers, school setup, ALS CLC
   assignment create/edit/history, Reports, Retirement Watch, Planning,
   exports, uploads, Chat, and Activity Logs.
5. Confirm uploaded photos are preserved and writable.
6. Deploy only after the staging results and backup have been checked.
