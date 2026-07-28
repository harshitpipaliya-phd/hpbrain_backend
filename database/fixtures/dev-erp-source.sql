-- =============================================================================
-- DEV FIXTURE — NOT A MIGRATION. NEVER RUN THIS AGAINST THE COMPANY DATABASE.
-- =============================================================================
--
-- HP Enterprise Brain reads Organization, Department and Person from the
-- institute's EXISTING ERP tables — it does not own them:
--
--   institute_detail / org_details   -> Organization   (organization.repository.ts)
--   hrms_departments                 -> Department     (department.repository.ts)
--   tbluser / tbluserprofilemaster    -> Person         (person.repository.ts)
--
-- That is correct, and it is what the Product Manifesto means by "an
-- intelligence layer ABOVE existing ERP/LMS/HRMS — we integrate, we do not
-- replace." Everything the Brain *owns* (signals, evidence, cases, hypotheses,
-- reasoning, recommendations, decisions, ESOs, outcomes, learnings, capability
-- proficiency) lives in the hpbrain_* tables created by database/migrations/.
--
-- On the real company server those five ERP tables already exist and hold real
-- data. On a developer laptop they do not, so nothing can boot. This fixture
-- creates them with the exact column shape the repositories expect, plus a
-- small amount of sample data, so the full application is runnable offline.
--
-- Run:   npm run db:fixtures        (dev/CI only — guarded, see below)
-- =============================================================================

CREATE TABLE IF NOT EXISTS institute_detail (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sub_institute_id  INT NOT NULL,
  organization_name VARCHAR(255) NOT NULL,
  organization_code VARCHAR(100),
  industry_type     VARCHAR(100),
  created_by        VARCHAR(64),
  created_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at        TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_institute_detail_sub (sub_institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS org_details (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  sub_institute_id INT NOT NULL,
  legal_name       VARCHAR(255),
  logo             VARCHAR(512),
  created_by       VARCHAR(64),
  created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at       TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_org_details_sub (sub_institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hrms_departments (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  sub_institute_id      INT NOT NULL,
  department            VARCHAR(255) NOT NULL,
  roles_responsibility  TEXT,
  parent_id             INT DEFAULT 0,
  status                TINYINT NOT NULL DEFAULT 1,
  is_calculated         TINYINT NOT NULL DEFAULT 0,
  created_by            VARCHAR(64),
  created_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_hrms_departments_sub (sub_institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbluserprofilemaster (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  sub_institute_id INT NOT NULL,
  name             VARCHAR(100) NOT NULL,
  status           TINYINT NOT NULL DEFAULT 1,
  INDEX idx_profile_sub (sub_institute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbluser (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  employee_no      VARCHAR(64),
  password         VARCHAR(255),
  plain_password   VARCHAR(255),
  first_name       VARCHAR(128),
  last_name        VARCHAR(128),
  email            VARCHAR(255),
  mobile           VARCHAR(32),
  gender           VARCHAR(16),
  birthdate        DATE NULL,
  joined_date      DATE NULL,
  department_id    INT NULL,
  jobtitle_id      INT NULL,
  city             VARCHAR(128),
  state            VARCHAR(128),
  image            VARCHAR(512),
  sub_institute_id INT NOT NULL,
  user_profile_id  INT NULL,
  status           TINYINT NOT NULL DEFAULT 1,
  created_by       VARCHAR(64),
  created_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at       TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_tbluser_sub (sub_institute_id),
  INDEX idx_tbluser_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Sample data. Enough breadth that list screens show rows and analytics
-- screens show a distribution rather than a single point.
-- --------------------------------------------------------------------------

INSERT INTO institute_detail (sub_institute_id, organization_name, organization_code, industry_type, created_by)
SELECT 1, 'Harmony Public School', 'HPS-01', 'Education', 'seed'
WHERE NOT EXISTS (SELECT 1 FROM institute_detail WHERE sub_institute_id = 1);

INSERT INTO org_details (sub_institute_id, legal_name, logo)
SELECT 1, 'Harmony Public School Trust', NULL
WHERE NOT EXISTS (SELECT 1 FROM org_details WHERE sub_institute_id = 1);

INSERT INTO tbluserprofilemaster (sub_institute_id, name, status)
SELECT 1, 'Employee', 1
WHERE NOT EXISTS (SELECT 1 FROM tbluserprofilemaster WHERE sub_institute_id = 1 AND name = 'Employee');

INSERT INTO hrms_departments (sub_institute_id, department, roles_responsibility, parent_id, status, created_by)
SELECT * FROM (
  SELECT 1 AS a, 'Academics'          AS b, 'Curriculum delivery and teaching quality' AS c, 0 AS d, 1 AS e, 'seed' AS f UNION ALL
  SELECT 1, 'Mathematics',   'Mathematics faculty',                     1, 1, 'seed' UNION ALL
  SELECT 1, 'Science',       'Science faculty and laboratories',        1, 1, 'seed' UNION ALL
  SELECT 1, 'Administration','Admissions, records, facilities',         0, 1, 'seed' UNION ALL
  SELECT 1, 'Student Affairs','Counselling, attendance, wellbeing',     0, 1, 'seed'
) AS src
WHERE NOT EXISTS (SELECT 1 FROM hrms_departments WHERE sub_institute_id = 1);

INSERT INTO tbluser (employee_no, first_name, last_name, email, mobile, gender, joined_date, department_id, city, state, sub_institute_id, user_profile_id, status, created_by)
SELECT * FROM (
  SELECT 'HPS-001' AS a,'Asha'   AS b,'Mehta'   AS c,'asha.mehta@example.edu'   AS d,'9000000001' AS e,'F' AS f,'2021-06-01' AS g, 2 AS h,'Surat' AS i,'Gujarat' AS j, 1 AS k, 1 AS l, 1 AS m,'seed' AS n UNION ALL
  SELECT 'HPS-002','Ravi','Patel','ravi.patel@example.edu','9000000002','M','2019-04-15', 2,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-003','Neha','Shah','neha.shah@example.edu','9000000003','F','2022-07-11', 3,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-004','Imran','Qureshi','imran.q@example.edu','9000000004','M','2020-01-20', 3,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-005','Priya','Nair','priya.nair@example.edu','9000000005','F','2018-08-05', 1,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-006','Sanjay','Desai','sanjay.desai@example.edu','9000000006','M','2023-02-01', 4,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-007','Meera','Joshi','meera.joshi@example.edu','9000000007','F','2021-11-09', 5,'Surat','Gujarat',1,1,1,'seed' UNION ALL
  SELECT 'HPS-008','Arun','Verma','arun.verma@example.edu','9000000008','M','2017-03-27', 4,'Surat','Gujarat',1,1,1,'seed'
) AS src
WHERE NOT EXISTS (SELECT 1 FROM tbluser WHERE sub_institute_id = 1);
