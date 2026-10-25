-- =====================================================================
-- Lifeline seed data (lookups + default roster + announcements + trainings)
-- Run AFTER 01_schema.sql. Safe to re-run (INSERT IGNORE).
-- =====================================================================
USE lifeline;

INSERT IGNORE INTO buildings (id, name, sort_order) VALUES
(1,'Main Campus Areas',1),
(2,'Admin Building',2),
(3,'LCA Building',3),
(4,'VPA Building',4),
(5,'Engineering Building',5),
(6,'FAME Building',6),
(7,'Herrero Building',7);

INSERT IGNORE INTO rooms (id, building_id, name) VALUES
(1,1,'MAC Lab'),
(2,1,'SAO'),
(3,1,'Quadrangle'),
(4,1,'Chapel'),
(5,2,'Admin Offices'),
(6,2,'Faculty Room'),
(7,2,'Student Lounge'),
(8,2,'A301'),
(9,2,'A302'),
(10,2,'A303'),
(11,2,'A304'),
(12,2,'A305'),
(13,2,'A501'),
(14,2,'A502'),
(15,2,'A503'),
(16,2,'A504'),
(17,3,'Arzatech'),
(18,3,'SITE Faculty Office'),
(19,3,'CISCO Lab'),
(20,3,'L301'),
(21,3,'L302'),
(22,3,'L303'),
(23,3,'L304'),
(24,3,'L305'),
(25,3,'L306'),
(26,3,'L401'),
(27,3,'L402'),
(28,3,'L403'),
(29,3,'L404'),
(30,3,'L405'),
(31,3,'L406'),
(32,3,'Nursing Laboratory'),
(33,3,'AVT'),
(34,4,'V201'),
(35,4,'V202'),
(36,4,'V203'),
(37,4,'V204'),
(38,4,'V205'),
(39,4,'V206'),
(40,4,'V207'),
(41,4,'V301'),
(42,4,'V302'),
(43,4,'V303'),
(44,4,'V304'),
(45,4,'V305'),
(46,4,'V306'),
(47,4,'V307'),
(48,4,'V308'),
(49,4,'V309'),
(50,4,'V401'),
(51,4,'V402'),
(52,4,'V403'),
(53,4,'V404'),
(54,4,'V405'),
(55,4,'V406'),
(56,4,'V407'),
(57,4,'V408'),
(58,4,'V409'),
(59,4,'Library'),
(60,4,'Open Computer Lab'),
(61,5,'Lab 1'),
(62,5,'Lab 2'),
(63,5,'Faculty Room'),
(64,5,'E101'),
(65,5,'E102'),
(66,6,'FAME Office'),
(67,6,'F101'),
(68,6,'F102'),
(69,6,'F103'),
(70,7,'H101'),
(71,7,'H102'),
(72,7,'H103');

INSERT IGNORE INTO squads (id, name, role_label, coverage, color) VALUES
(1,'Alpha Team','Rapid Response','Main Campus / Quadrangle / Admin','#3b82f6'),
(2,'Bravo Team','Main Patrol','LCA Building & VPA Building','#10b981'),
(3,'Charlie Team','Triage Support','Engineering, FAME, & Herrero','#8b5cf6');

INSERT IGNORE INTO zones (id, name) VALUES
(1,'Main Campus / Admin'),
(2,'LCA & VPA Buildings'),
(3,'Engineering & Herrero');

INSERT IGNORE INTO incident_types (id, name, sort_order) VALUES
(1,'Fever / Flu',1),
(2,'Headache',2),
(3,'Minor Cut',3),
(4,'Sprain',4),
(5,'Fainting',5),
(6,'Nosebleed',6),
(7,'Allergic Reaction',7),
(8,'Burn',8),
(9,'Fracture',9),
(10,'Difficulty Breathing',10),
(11,'Other',11);

-- ---------------------------------------------------------------------
-- DEFAULT CPR ROSTER (replace/extend with your real data: see 03_import_existing_cpr.sql)
-- password_hash is NULL -> each person activates once on the Register tab.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO users (student_id, full_name, course, role) VALUES
('23-1122-334','Juan Miguel Santos','BS Information Technology','officer'),
('23-5566-778','Angela Reyes','BS Information Technology','member'),
('23-9900-112','Kevin Cruz','BS Information Technology','member'),
('23-3344-556','Patricia Ramos','BS Information Technology','member');

INSERT IGNORE INTO cpr_members (student_id, squad_id, zone_id, duty_status) VALUES
('23-1122-334',1,1,'On Duty'),
('23-5566-778',1,1,'On Duty'),
('23-9900-112',2,2,'Responding'),
('23-3344-556',3,3,'Off Duty');

INSERT INTO announcements (title, details, date_label, icon, is_featured)
SELECT * FROM (
  SELECT 'Campus Safety Awareness Week' AS title,
         'Students and faculty are encouraged to participate in the upcoming campus safety awareness activities.' AS details,
         'October 7 - 11, 2026' AS date_label, '📢' AS icon, 1 AS is_featured
  UNION ALL SELECT 'First Aid Training','Learn basic first aid and emergency response procedures.','October 10, 2026','🩹',0
  UNION ALL SELECT 'Health Screening','Free basic health screening for students.','October 12, 2026','❤️',0
  UNION ALL SELECT 'Emergency Exit Reminder','Keep all emergency exits and pathways clear.','Campus Safety Office','⚠️',0
) AS seed_rows
WHERE NOT EXISTS (SELECT 1 FROM announcements);

INSERT INTO training_sessions (title, tag, description, session_date, location, slots)
SELECT * FROM (
  SELECT 'Basic First Aid & CPR' AS title, 'CERTIFICATION' AS tag,
         'Learn CPR, wound care, basic assessment, and emergency response.' AS description,
         DATE('2026-10-10') AS session_date, 'Student Center' AS location, 24 AS slots
  UNION ALL SELECT 'Emergency Response Refresher','REFRESHER','Review emergency procedures and campus response protocols.','2026-10-17','Training Room',20
  UNION ALL SELECT 'Basic Patient Assessment','WORKSHOP','Training on assessing patient condition and providing initial aid.','2026-10-24','Laboratory Building',15
) AS seed_rows
WHERE NOT EXISTS (SELECT 1 FROM training_sessions);
