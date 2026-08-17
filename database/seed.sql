-- TalaGuro TPMS optional development seed data.
USE tpms;

-- ────────────────────────────────────────────────────────────
-- SEED: Default Admin User
-- Username: admin   Password: Admin@2024  (change after first login!)
-- Hash generated with: password_hash('Admin@2024', PASSWORD_BCRYPT, ['cost'=>12])
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO users (username, password_hash, full_name, role, is_active)
VALUES (
    'admin',
    '$2y$12$QItRSj7Da/1JnEDpEPmoaOQ6PqAPVSfFbY0hnwiQx1P18XiH2jBgS',
    'System Administrator',
    'admin',
    1
);

-- ────────────────────────────────────────────────────────────
-- SEED: Aurora Municipalities and Districts
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO municipalities (id, municipality_name, province_name) VALUES
(1, 'Baler', 'Aurora'), (2, 'Casiguran', 'Aurora'), (3, 'Dilasag', 'Aurora'),
(4, 'Dinalungan', 'Aurora'), (5, 'Dingalan', 'Aurora'), (6, 'Dipaculao', 'Aurora'),
(7, 'Maria Aurora', 'Aurora'), (8, 'San Luis', 'Aurora');

INSERT IGNORE INTO districts (id, district_name, municipality_id) VALUES
(1, 'Baler', 1), (2, 'Casiguran', 2), (3, 'Dilasag', 3), (4, 'Dinalungan', 4),
(5, 'Dingalan', 5), (6, 'Dipaculao North', 6), (7, 'Dipaculao South', 6),
(8, 'Maria Aurora East', 7), (9, 'Maria Aurora West', 7), (10, 'San Luis', 8);

-- ────────────────────────────────────────────────────────────
-- SEED: Sample Schools
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO schools
    (id, school_name, school_id_code, municipality, municipality_id, sector, school_category,
     offers_formal_education, offers_als, institution_classification, school_type, als_subtype, district_id)
VALUES
(1, 'Sample National High School',        '300001',   'Baler',        1, 'public', 'formal', 1, 0, 'Secondary', 'JHS/SHS', NULL, 1),
(2, 'Barangay Elementary School',         '300002',   'Baler',        1, 'public', 'formal', 1, 0, 'Elementary', 'Elementary', NULL, 1),
(3, 'Sample Junior High School',          '300003',   'Maria Aurora', 7, 'public', 'formal', 1, 0, 'JHS', 'JHS', NULL, 8),
(4, 'Sample Senior High School',          '300004',   'Dipaculao',    6, 'public', 'formal', 1, 0, 'SHS', 'SHS', NULL, 6),
(5, 'Aurora Community Learning Center',   '90000001', 'San Luis',     8, 'public', 'als', 0, 1, 'ALS-only', 'ALS', 'CBLC', 10),
(6, 'Aurora School-Based Learning Center','90000002', 'Dilasag',      3, 'public', 'als', 0, 1, 'ALS-only', 'ALS', 'SBLC', 3),
(7, 'Aurora ALS Senior High',             '90000003', 'Casiguran',    2, 'public', 'als', 0, 1, 'ALS-only', 'ALS', 'ALS-SHS', 2);

INSERT IGNORE INTO school_curricular_offerings (school_id, offering_code) VALUES
(1, 'JHS'), (1, 'SHS'), (2, 'ELEMENTARY'), (3, 'JHS'), (4, 'SHS'),
(5, 'CBLC'), (6, 'SBLC'), (7, 'ALS-SHS');

-- ────────────────────────────────────────────────────────────
-- SEED: Sample Teachers (demonstation only)
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO teachers
    (employee_number, last_name, first_name, middle_name, birthdate,
     gender, position, appointment_type, school_id, grade_level,
     specialization, highest_education, data_privacy_consent)
VALUES
    ('EMP001','dela Cruz','Maria','Santos','1985-03-15',
     'Female','Teacher I','Permanent',1,'Grade 7-10','Mathematics','Bachelor''s Degree','Yes'),
    ('EMP002','Reyes','Jose','Andres','1978-07-22',
     'Male','Teacher III','Permanent',1,'Grade 11-12','English','Master''s Degree','Yes'),
    ('EMP003','Santos','Ana','Lim','1990-11-05',
     'Female','Teacher II','Permanent',2,'Grade 1-3','Science','Bachelor''s Degree','Yes'),
    ('EMP004','Bautista','Carlo','Ramos','1983-04-18',
     'Male','Master Teacher I','Permanent',3,'Grade 4-6','Filipino','With Masteral Units','Yes'),
    ('EMP005','Garcia','Luz','Torres','1995-09-30',
     'Female','Teacher I','Provisional',4,'Grade 7-8','TLE','Bachelor''s Degree','Yes');
