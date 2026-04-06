CREATE DATABASE hackdesk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hackdesk;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(180) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','admin','jury','staff') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE hackathons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_by INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  tagline VARCHAR(300) NULL,
  description TEXT NULL,
  venue VARCHAR(300) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  registration_deadline DATETIME NULL,
  ps_selection_deadline DATETIME NULL,
  min_team_size TINYINT UNSIGNED NOT NULL DEFAULT 2,
  max_team_size TINYINT UNSIGNED NOT NULL DEFAULT 4,
  leaderboard_visible TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','registration_open','ongoing','judging','completed','cancelled') NOT NULL DEFAULT 'draft',
  banner_path VARCHAR(300) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE rounds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hackathon_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  round_number TINYINT UNSIGNED NOT NULL,
  description TEXT NULL,
  submission_opens_at DATETIME NULL,
  submission_deadline DATETIME NOT NULL,
  judging_deadline DATETIME NULL,
  ppt_required TINYINT(1) NOT NULL DEFAULT 1,
  github_required TINYINT(1) NOT NULL DEFAULT 1,
  custom_link_allowed TINYINT(1) NOT NULL DEFAULT 0,
  abstract_required TINYINT(1) NOT NULL DEFAULT 0,
  judging_criteria JSON NULL,
  status ENUM('upcoming','open','closed','judging_done') NOT NULL DEFAULT 'upcoming',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
  UNIQUE KEY unique_round_per_hackathon (hackathon_id, round_number)
) ENGINE=InnoDB;

CREATE TABLE problem_statements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hackathon_id INT UNSIGNED NOT NULL,
  title VARCHAR(300) NOT NULL,
  description TEXT NOT NULL,
  domain VARCHAR(100) NULL,
  difficulty ENUM('beginner','intermediate','advanced') NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hackathon_id INT UNSIGNED NOT NULL,
  participant_type ENUM('internal','external') NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(180) NOT NULL,
  phone VARCHAR(20) NULL,
  vit_reg_no VARCHAR(20) NULL,
  college VARCHAR(200) NULL,
  department VARCHAR(150) NULL,
  year_of_study TINYINT UNSIGNED NULL,
  barcode_uid VARCHAR(64) NOT NULL UNIQUE,
  qr_token VARCHAR(128) NOT NULL UNIQUE,
  id_card_path VARCHAR(300) NULL,
  id_card_sent_at DATETIME NULL,
  check_in_status ENUM('not_checked_in','checked_in','left') NOT NULL DEFAULT 'not_checked_in',
  checked_in_at DATETIME NULL,
  checked_in_by INT UNSIGNED NULL,
  registration_confirmed TINYINT(1) NOT NULL DEFAULT 0,
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
  FOREIGN KEY (checked_in_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY unique_participant_per_hackathon (hackathon_id, email)
) ENGINE=InnoDB;

CREATE TABLE teams (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hackathon_id INT UNSIGNED NOT NULL,
  leader_participant_id INT UNSIGNED NOT NULL,
  problem_statement_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  join_code CHAR(8) NOT NULL UNIQUE,
  status ENUM('forming','complete','disqualified') NOT NULL DEFAULT 'forming',
  ps_locked TINYINT(1) NOT NULL DEFAULT 0,
  ps_locked_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
  FOREIGN KEY (leader_participant_id) REFERENCES participants(id) ON DELETE RESTRICT,
  FOREIGN KEY (problem_statement_id) REFERENCES problem_statements(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE team_members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  participant_id INT UNSIGNED NOT NULL,
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  UNIQUE KEY unique_participant_per_team (participant_id)
) ENGINE=InnoDB;

CREATE TABLE submissions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  team_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  ppt_file_path VARCHAR(300) NULL,
  ppt_original_name VARCHAR(200) NULL,
  github_url VARCHAR(500) NULL,
  custom_link VARCHAR(500) NULL,
  abstract TEXT NULL,
  submitted_at DATETIME NULL,
  last_updated_at DATETIME NULL,
  status ENUM('draft','submitted','late','disqualified') NOT NULL DEFAULT 'draft',
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
  UNIQUE KEY unique_submission_per_round (team_id, round_id)
) ENGINE=InnoDB;

CREATE TABLE jury_assignments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jury_user_id INT UNSIGNED NOT NULL,
  hackathon_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (jury_user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE,
  FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE RESTRICT,
  UNIQUE KEY unique_jury_assignment (jury_user_id, round_id, team_id)
) ENGINE=InnoDB;

CREATE TABLE scores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jury_assignment_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  round_id INT UNSIGNED NOT NULL,
  criteria_scores JSON NOT NULL,
  total_score DECIMAL(6,2) NOT NULL DEFAULT 0,
  remarks TEXT NULL,
  scored_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (jury_assignment_id) REFERENCES jury_assignments(id) ON DELETE CASCADE,
  FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
  FOREIGN KEY (round_id) REFERENCES rounds(id) ON DELETE CASCADE,
  UNIQUE KEY unique_score_per_assignment (jury_assignment_id)
) ENGINE=InnoDB;

CREATE TABLE certificates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id INT UNSIGNED NOT NULL,
  hackathon_id INT UNSIGNED NOT NULL,
  cert_type ENUM('participation','winner','runner_up','second_runner_up','special') NOT NULL DEFAULT 'participation',
  position INT UNSIGNED NULL,
  hmac_token VARCHAR(128) NOT NULL UNIQUE,
  file_path VARCHAR(300) NULL,
  is_revoked TINYINT(1) NOT NULL DEFAULT 0,
  revoke_reason VARCHAR(300) NULL,
  special_title VARCHAR(150) NULL,
  issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
  FOREIGN KEY (hackathon_id) REFERENCES hackathons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE login_audit (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  email_attempted VARCHAR(180) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(300) NULL,
  success TINYINT(1) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  hackathon_id INT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id INT UNSIGNED NULL,
  metadata JSON NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE participant_sessions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id INT UNSIGNED NOT NULL,
  token VARCHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE participant_otps (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  participant_id INT UNSIGNED NOT NULL,
  email VARCHAR(180) NOT NULL,
  code_hash VARCHAR(255) NOT NULL,
  expires_at DATETIME NOT NULL,
  verified_at DATETIME NULL,
  consumed_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_participants_hackathon ON participants(hackathon_id);
CREATE INDEX idx_participants_barcode ON participants(barcode_uid);
CREATE INDEX idx_participants_qr ON participants(qr_token);
CREATE INDEX idx_teams_hackathon ON teams(hackathon_id);
CREATE INDEX idx_teams_join_code ON teams(join_code);
CREATE INDEX idx_submissions_team_round ON submissions(team_id, round_id);
CREATE INDEX idx_scores_round ON scores(round_id);
CREATE INDEX idx_activity_log_hackathon ON activity_log(hackathon_id);
CREATE INDEX idx_participant_otps_email ON participant_otps(email);
