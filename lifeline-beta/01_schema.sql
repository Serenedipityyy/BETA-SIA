-- =====================================================================
-- Lifeline - Dispatch & Emergency Response System (Universidad de Dagupan)
-- MySQL 8.0.16+ (or MariaDB 10.5+)   |   Run order: 01 -> 02 -> (03) -> (04)
-- Safe to re-run: uses CREATE ... IF NOT EXISTS and never drops data.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS lifeline
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lifeline;

-- ---------------------------------------------------------------------
-- LOOKUP TABLES (replace the hard-coded lists in script.js)
-- ---------------------------------------------------------------------

-- Campus buildings / zones shown in the "Building / Zone" dropdown
CREATE TABLE IF NOT EXISTS buildings (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_buildings_name (name)
) ENGINE=InnoDB;

-- Rooms / specific areas inside a building
CREATE TABLE IF NOT EXISTS rooms (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  building_id  TINYINT UNSIGNED NOT NULL,
  name         VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rooms_building_name (building_id, name),
  CONSTRAINT fk_rooms_building FOREIGN KEY (building_id)
    REFERENCES buildings (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- CPR squads: Alpha / Bravo / Charlie
CREATE TABLE IF NOT EXISTS squads (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(40) NOT NULL,          -- "Alpha Team"
  role_label  VARCHAR(60) NOT NULL,          -- "Rapid Response"
  coverage    VARCHAR(150) NOT NULL,         -- "Main Campus / Quadrangle / Admin"
  color       CHAR(7) NOT NULL DEFAULT '#3b82f6',
  PRIMARY KEY (id),
  UNIQUE KEY uq_squads_name (name)
) ENGINE=InnoDB;

-- Assigned patrol zones for responders
CREATE TABLE IF NOT EXISTS zones (
  id    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name  VARCHAR(60) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_zones_name (name)
) ENGINE=InnoDB;

-- Medical condition / complaint (powers the analytics page)
CREATE TABLE IF NOT EXISTS incident_types (
  id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(60) NOT NULL,
  sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_incident_types_name (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- USERS (students + CPR members + CPR officers)
-- CPR members/officers are PRE-LOADED here (from your existing database)
-- with password_hash = NULL. They "activate" once by choosing a password.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  student_id            CHAR(11) NOT NULL,                       -- 23-1234-567
  full_name             VARCHAR(60) NOT NULL,
  course                VARCHAR(80) NOT NULL DEFAULT 'BS Information Technology',
  role                  ENUM('user','member','officer','admin') NOT NULL DEFAULT 'user',
  password_hash         VARCHAR(255) NULL,                       -- NULL = pre-loaded, not yet activated
  activation_code_sha256 CHAR(64) NULL,                          -- optional one-time code (see README)
  birthdate             DATE NULL,
  address               VARCHAR(150) NULL,
  emergency_contact     VARCHAR(80) NULL,
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME NULL,
  last_login_at         DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  KEY idx_users_role (role),
  CONSTRAINT chk_users_student_id CHECK (student_id REGEXP '^2[0-9]-[0-9]{4}-[0-9]{3}$')
) ENGINE=InnoDB;

-- Extra details only CPR members/officers have (roster)
CREATE TABLE IF NOT EXISTS cpr_members (
  student_id   CHAR(11) NOT NULL,
  squad_id     TINYINT UNSIGNED NOT NULL,
  zone_id      TINYINT UNSIGNED NOT NULL,
  duty_status  ENUM('On Duty','Responding','On-Scene','Off Duty') NOT NULL DEFAULT 'On Duty',
  joined_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id),
  KEY idx_cpr_duty (duty_status),
  CONSTRAINT fk_cpr_user  FOREIGN KEY (student_id) REFERENCES users (student_id) ON DELETE CASCADE,
  CONSTRAINT fk_cpr_squad FOREIGN KEY (squad_id)   REFERENCES squads (id),
  CONSTRAINT fk_cpr_zone  FOREIGN KEY (zone_id)    REFERENCES zones (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- EMERGENCY INCIDENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS incidents (
  id                VARCHAR(14) NOT NULL,                        -- UDD-482913
  priority          ENUM('RED','YELLOW','GREEN') NOT NULL,
  incident_type_id  TINYINT UNSIGNED NULL,
  building_id       TINYINT UNSIGNED NOT NULL,
  room_id           SMALLINT UNSIGNED NOT NULL,
  patients          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  details           VARCHAR(500) NOT NULL DEFAULT 'No additional remarks',
  reporter_id       CHAR(11) NOT NULL,
  status            ENUM('DISPATCHED','RESPONDING','ON_SCENE','RESOLVED') NOT NULL DEFAULT 'DISPATCHED',
  responder_id      CHAR(11) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at      DATETIME NULL,
  on_scene_at       DATETIME NULL,
  resolved_at       DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_inc_status_priority (status, priority, created_at),
  KEY idx_inc_reporter (reporter_id, status),
  KEY idx_inc_created (created_at),
  KEY idx_inc_room (reporter_id, room_id, status),
  CONSTRAINT chk_inc_patients CHECK (patients BETWEEN 1 AND 50),
  CONSTRAINT fk_inc_type      FOREIGN KEY (incident_type_id) REFERENCES incident_types (id) ON DELETE SET NULL,
  CONSTRAINT fk_inc_building  FOREIGN KEY (building_id) REFERENCES buildings (id),
  CONSTRAINT fk_inc_room      FOREIGN KEY (room_id)     REFERENCES rooms (id),
  CONSTRAINT fk_inc_reporter  FOREIGN KEY (reporter_id)  REFERENCES users (student_id),
  CONSTRAINT fk_inc_responder FOREIGN KEY (responder_id) REFERENCES users (student_id)
) ENGINE=InnoDB;

-- Audit trail of every status change (Reported -> Responding -> On-scene -> Resolved)
CREATE TABLE IF NOT EXISTS incident_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  incident_id  VARCHAR(14) NOT NULL,
  status       ENUM('DISPATCHED','RESPONDING','ON_SCENE','RESOLVED') NOT NULL,
  changed_by   CHAR(11) NOT NULL,
  changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_incident (incident_id, changed_at),
  CONSTRAINT fk_log_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE,
  CONSTRAINT fk_log_user     FOREIGN KEY (changed_by)  REFERENCES users (student_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ANNOUNCEMENTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(120) NOT NULL,
  details     VARCHAR(1000) NOT NULL,
  date_label  VARCHAR(120) NOT NULL,                              -- "October 10, 2026" or "Campus Safety Office"
  icon        VARCHAR(8) NOT NULL DEFAULT '📢',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  created_by  CHAR(11) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ann_order (is_featured, created_at),
  CONSTRAINT fk_ann_user FOREIGN KEY (created_by) REFERENCES users (student_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- TRAINING & SEMINARS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS training_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title        VARCHAR(120) NOT NULL,
  tag          ENUM('CERTIFICATION','REFRESHER','WORKSHOP','SEMINAR') NOT NULL DEFAULT 'WORKSHOP',
  description  VARCHAR(300) NOT NULL,
  session_date DATE NOT NULL,
  location     VARCHAR(100) NOT NULL,
  slots        SMALLINT UNSIGNED NOT NULL,
  created_by   CHAR(11) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_training_date (session_date),
  CONSTRAINT fk_training_user FOREIGN KEY (created_by) REFERENCES users (student_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS training_registrations (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id     INT UNSIGNED NOT NULL,
  student_id     CHAR(11) NOT NULL,
  status         ENUM('Registered','Attended','Absent','Cancelled') NOT NULL DEFAULT 'Registered',
  registered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_training_once (session_id, student_id),
  KEY idx_training_student (student_id),
  CONSTRAINT fk_reg_session FOREIGN KEY (session_id) REFERENCES training_sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_user    FOREIGN KEY (student_id) REFERENCES users (student_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- LOGIN AUDIT
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id  CHAR(11) NOT NULL,
  success     TINYINT(1) NOT NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_student (student_id, created_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- LEAST-PRIVILEGE DATABASE USER FOR THE PHP API
-- CHANGE THE PASSWORD, then put the same one in api/config.php
-- ---------------------------------------------------------------------
CREATE USER IF NOT EXISTS 'lifeline_app'@'localhost' IDENTIFIED BY 'ChangeThisPassword!';
CREATE USER IF NOT EXISTS 'lifeline_app'@'127.0.0.1' IDENTIFIED BY 'ChangeThisPassword!';
GRANT SELECT, INSERT, UPDATE, DELETE ON lifeline.* TO 'lifeline_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON lifeline.* TO 'lifeline_app'@'127.0.0.1';
FLUSH PRIVILEGES;


-- ---------------------------------------------------------------------
-- OTP CODES FOR REGISTRATION VERIFICATION
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS otp_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id VARCHAR(20) NOT NULL,
  otp_code VARCHAR(6) NOT NULL,
  expires_at DATETIME NOT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_student (student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
