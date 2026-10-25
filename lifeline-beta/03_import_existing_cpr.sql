-- =====================================================================
-- IMPORT YOUR EXISTING CPR MEMBERS / OFFICERS INTO LIFELINE
-- Template: adjust the staging columns to match what your database has.
-- After this, those people do NOT self-register as CPR: they just open the
-- Register tab once, confirm their name, and choose a password.
-- =====================================================================
USE lifeline;

-- 1) Staging table (temporary copy of your existing records)
CREATE TABLE IF NOT EXISTS cpr_import (
  student_id  CHAR(11)     NOT NULL,
  full_name   VARCHAR(60)  NOT NULL,
  course      VARCHAR(80)  NULL,
  role        ENUM('member','officer') NOT NULL DEFAULT 'member',
  squad       VARCHAR(40)  NULL,     -- 'Alpha Team' / 'Bravo Team' / 'Charlie Team'
  zone        VARCHAR(60)  NULL,     -- 'Main Campus / Admin' etc.
  PRIMARY KEY (student_id)
) ENGINE=InnoDB;

-- 2) Fill the staging table. Pick ONE option:
--    (a) From a CSV file (header row: student_id,full_name,course,role,squad,zone)
-- LOAD DATA LOCAL INFILE 'C:/path/to/cpr_members.csv'
--   INTO TABLE cpr_import
--   FIELDS TERMINATED BY ',' ENCLOSED BY '"'
--   LINES TERMINATED BY '\n'
--   IGNORE 1 LINES
--   (student_id, full_name, course, role, squad, zone);
--
--    (b) From another database on the same MySQL server (edit names):
-- INSERT INTO cpr_import (student_id, full_name, course, role, squad, zone)
-- SELECT s.student_no, CONCAT(s.first_name,' ',s.last_name), s.course,
--        IF(s.position='Officer','officer','member'), s.team, s.sector
-- FROM   old_database.cpr_members s;

-- 3) Create/refresh the user accounts (no password yet = "not activated")
INSERT INTO users (student_id, full_name, course, role)
SELECT student_id, full_name, COALESCE(course, 'BS Information Technology'), role
FROM   cpr_import
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  role      = VALUES(role);          -- password_hash is left untouched

-- 4) Put them on the roster (unknown squad/zone falls back to Alpha / Main Campus)
INSERT INTO cpr_members (student_id, squad_id, zone_id, duty_status)
SELECT i.student_id,
       COALESCE(sq.id, 1),
       COALESCE(zn.id, 1),
       'On Duty'
FROM   cpr_import i
LEFT JOIN squads sq ON sq.name = i.squad
LEFT JOIN zones  zn ON zn.name = i.zone
ON DUPLICATE KEY UPDATE
  squad_id = VALUES(squad_id),
  zone_id  = VALUES(zone_id);

-- 5) OPTIONAL but recommended: give each person a one-time activation code so
--    nobody can claim another member's account just by knowing their ID + name.
--    Hand the plain code to the person privately; it is erased once they activate.
-- UPDATE users SET activation_code_sha256 = SHA2('K7Q2-M9XP', 256) WHERE student_id = '23-1122-334';

-- 6) Clean up
-- DROP TABLE cpr_import;
