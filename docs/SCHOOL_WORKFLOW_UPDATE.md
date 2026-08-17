# Two-Step Add School Workflow

## What changed

The **Add School** button now opens a two-step workflow:

1. Enter the school name, School ID, municipality, municipality-linked district,
   and sector. Select Formal Education, ALS, or both, then choose the applicable
   offerings. The institution classification is derived automatically.
2. Assign or add a school head, add one or more teachers, and enter learner and
   current-class counts for every applicable grade/year level.

Schools with Formal Education require a 6-digit School ID, including schools
that also offer ALS. ALS-only centers require an 8-digit School ID. Municipality
and district selections are validated again on the server so a district cannot
be saved under the wrong municipality.

Formal offerings are stored as Kindergarten, Elementary (Grades 1–6), JHS
(Grades 7–10), and SHS (Grades 11–12). Quick presets such as K–6, K–10, K–12,
and JHS+SHS only toggle these underlying components; preset names are not stored.
ALS SHS uses separate Grade 11 and Grade 12 learner/class rows, while other ALS
center offerings use one program-level row.

## Updating an existing/live TPMS installation

1. Back up the live database and current TPMS files.
2. Upload the updated application files.
3. In phpMyAdmin, select the `tpms` database and import, in order:
   - `database/migrations/003_school_profile_workflow.sql` if it has not already been applied
   - `database/migrations/004_formal_als_programs.sql`
4. Sign in as Administrator or HR and test Add School.

Do not import `database.sql` over a live database. It is intended only for a
clean/manual installation. Do not edit an already-applied migration; create a
new numbered migration for future database changes.

## Main files

- `pages/schools.php` — popup forms and municipality/district behavior
- `actions/create_school.php` — validates and saves Step 1
- `actions/save_school_setup.php` — transaction-safe Step 2 save
- `includes/functions.php` — offering and level helpers
- `config.php` — allowed sectors, categories, and offerings
- `database/migrations/003_school_profile_workflow.sql` — live database update
- `database/migrations/004_formal_als_programs.sql` — independent Formal/ALS programs and automatic classification
- `database/schema.sql`, `database.sql`, and `setup.php` — clean-install schema

## Database relationships

- One municipality has many districts.
- One district has many schools.
- One school has many curricular offerings.
- One school has many grade/year-level statistic rows.
- One school has many teachers and may identify one teacher as its school head.

`schools.institution_classification` is derived from the normalized offerings.
The compatibility columns (`schools.school_category`, `school_type`,
`learner_count`, and `total_sections`) remain updated for the current dashboard,
reports, and staffing calculations.
