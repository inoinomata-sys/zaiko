CREATE TABLE IF NOT EXISTS staff_line_settings (
 stream VARCHAR(10) PRIMARY KEY,
 enabled TINYINT NOT NULL DEFAULT 0,
 mode VARCHAR(10) NOT NULL DEFAULT 'approval',
 group_id VARCHAR(64) NOT NULL DEFAULT '',
 include_monday TINYINT NOT NULL DEFAULT 0,
 notify_changes TINYINT NOT NULL DEFAULT 1,
 revision INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO staff_line_settings (stream) VALUES ('store'), ('shift');
CREATE TABLE IF NOT EXISTS staff_line_groups (
 group_id VARCHAR(64) PRIMARY KEY,
 active TINYINT NOT NULL DEFAULT 1,
 event_timestamp BIGINT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_line_visibility (
 kind VARCHAR(10) NOT NULL,
 entity_id INT NOT NULL,
 is_public TINYINT NOT NULL DEFAULT 0,
 PRIMARY KEY (kind, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_line_jobs (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 slot VARCHAR(100) NOT NULL UNIQUE,
 kind VARCHAR(10) NOT NULL,
 stream VARCHAR(10) NOT NULL,
 group_id VARCHAR(64) NOT NULL,
 snapshot MEDIUMTEXT NOT NULL,
 before_snapshot MEDIUMTEXT NULL,
 body MEDIUMTEXT NOT NULL,
 content_hash CHAR(64) NOT NULL,
 retry_key CHAR(36) NOT NULL UNIQUE,
 state VARCHAR(12) NOT NULL,
 attempts INT NOT NULL DEFAULT 0,
 first_attempt_at DATETIME NULL,
 next_attempt_at DATETIME NULL,
 expires_at DATETIME NOT NULL,
 scheduled_at DATETIME NOT NULL,
 approved_by VARCHAR(80) NULL,
 http_status INT NULL,
 accepted_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 KEY idx_staff_line_queue (state, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_line_baselines (
 stream VARCHAR(10) NOT NULL,
 group_id VARCHAR(64) NOT NULL,
 work_date DATE NOT NULL,
 snapshot MEDIUMTEXT NOT NULL,
 job_id BIGINT NOT NULL,
 PRIMARY KEY (stream, group_id, work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_line_attempts (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 job_id BIGINT NOT NULL,
 attempted_at DATETIME NOT NULL,
 http_status INT NOT NULL,
 result VARCHAR(12) NOT NULL,
 KEY idx_staff_line_attempt_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS staff_line_audit (
 id BIGINT AUTO_INCREMENT PRIMARY KEY,
 at DATETIME NOT NULL,
 actor VARCHAR(80) NOT NULL,
 stream VARCHAR(10) NOT NULL,
 action VARCHAR(30) NOT NULL,
 job_id BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
