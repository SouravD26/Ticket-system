-- Role restructure: superadmin / admin / it / employee + daily task module
ALTER TABLE users
  MODIFY role ENUM('superadmin','admin','it','employee') NOT NULL DEFAULT 'employee';

ALTER TABLE users
  ADD COLUMN username VARCHAR(60) NULL AFTER name;

UPDATE users SET username = SUBSTRING_INDEX(email,'@',1) WHERE username IS NULL OR username = '';

ALTER TABLE users
  MODIFY username VARCHAR(60) NOT NULL,
  ADD UNIQUE KEY uq_username (username);

CREATE TABLE IF NOT EXISTS daily_tasks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  task_date   DATE NOT NULL,
  title       VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  hours       DECIMAL(4,2) NOT NULL DEFAULT 0,
  status      ENUM('in_progress','completed','blocked') NOT NULL DEFAULT 'completed',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, task_date),
  CONSTRAINT fk_dt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
