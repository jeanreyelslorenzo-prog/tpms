# ALS teacher-to-CLC assignments

## Data model

- `teachers.school_id` is still the teacher's single official or plantilla station.
- `teacher_clc_assignments` stores every ALS CLC served by that teacher.
- Each assignment has a school year, an optional primary-CLC flag, and an Active/Inactive status.
- A teacher appears once in `teachers`, regardless of how many CLCs are assigned.

The unique key `(teacher_id, clc_school_id, school_year)` prevents duplicate
assignment rows. Foreign keys automatically remove assignment rows if the
teacher or CLC itself is deliberately deleted.

## User workflow

1. Open **Teachers**, then add or edit the teacher.
2. Keep **School Station** as the teacher's official station.
3. In **ALS CLC Assignments**, enter the school year in `YYYY-YYYY` format.
4. Check every CLC served by the teacher.
5. Optionally mark one checked center as **Primary CLC**.
6. Save the teacher.

Opening **Add Teacher** from an ALS center automatically selects that center as
the initial primary CLC. Unchecking a CLC during a later edit marks the
assignment Inactive; it does not delete the teacher or the history row.

The teacher profile displays assignment history. The ALS center, Schools,
Teachers, Reports, Retirement Watch, exports, and Requirement Planning pages
recognize active CLC assignments. Queries start from `teachers` and use
`EXISTS`, so a teacher serving multiple CLCs is not duplicated in a result set.

## Existing live installation

Back up the live database, then import only:

`database/migrations/005_als_teacher_clc_assignments.sql`

Do not run `setup.php` again and do not re-import `database.sql` over a live
database. The migration is additive and preserves all existing teachers and
schools. Teachers whose official station is already an ALS-only center are
backfilled into that center as the primary active CLC. Teachers at formal
schools that also offer ALS are not auto-assigned, because not every teacher at
those schools necessarily serves the ALS program.

## New installation

The table is already included in `setup.php`, `database/schema.sql`, and
`database.sql`. Follow the normal new-installation steps in `README.md`.
