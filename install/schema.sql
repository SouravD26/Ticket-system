-- ticket_db schema
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(160) NOT NULL UNIQUE,
  password      VARCHAR(255) NOT NULL,
  role          ENUM('superadmin','admin','it','employee','hr') NOT NULL DEFAULT 'employee',
  phone         VARCHAR(40)  DEFAULT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_u_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- HR keeps a record for every joiner and every leaver.
CREATE TABLE IF NOT EXISTS onboarding (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_name VARCHAR(120) NOT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  join_date     DATE NOT NULL,
  email         VARCHAR(160) DEFAULT NULL,
  system_spec   TEXT DEFAULT NULL,
  assets        TEXT DEFAULT NULL,
  status        ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  created_by    INT UNSIGNED DEFAULT NULL,
  admin_done_at DATETIME DEFAULT NULL,
  admin_done_by INT UNSIGNED DEFAULT NULL,
  admin_note    VARCHAR(255) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ob_dept (department_id),
  KEY idx_ob_date (join_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offboarding (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_name  VARCHAR(120) NOT NULL,
  department_id  INT UNSIGNED DEFAULT NULL,
  last_working_day DATE NOT NULL,
  email          VARCHAR(160) DEFAULT NULL,
  assets_returned TEXT DEFAULT NULL,
  exit_notes     TEXT DEFAULT NULL,
  status         ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  created_by     INT UNSIGNED DEFAULT NULL,
  admin_done_at  DATETIME DEFAULT NULL,
  admin_done_by  INT UNSIGNED DEFAULT NULL,
  admin_note     VARCHAR(255) DEFAULT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_off_dept (department_id),
  KEY idx_off_date (last_working_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tickets (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL UNIQUE,
  subject       VARCHAR(200) NOT NULL,
  body          TEXT         NOT NULL,
  status        ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  priority      ENUM('low','medium','high','urgent')       NOT NULL DEFAULT 'medium',
  user_id       INT UNSIGNED NOT NULL,
  assigned_to   INT UNSIGNED DEFAULT NULL,
  department_id INT UNSIGNED DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  closed_at     DATETIME DEFAULT NULL,
  assigned_at      DATETIME DEFAULT NULL,
  completed_at     DATETIME DEFAULT NULL,
  acknowledged_at  DATETIME DEFAULT NULL,
  reopen_count     INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (status),
  KEY idx_user (user_id),
  KEY idx_agent (assigned_to),
  CONSTRAINT fk_t_user FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE,
  CONSTRAINT fk_t_agent FOREIGN KEY (assigned_to)  REFERENCES users(id)       ON DELETE SET NULL,
  CONSTRAINT fk_t_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_replies (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  message    TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id),
  CONSTRAINT fk_r_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_r_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attachments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id     INT UNSIGNED NOT NULL,
  reply_id      INT UNSIGNED DEFAULT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(255) NOT NULL,
  mime          VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id),
  CONSTRAINT fk_a_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_activity (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,
  detail     VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id),
  CONSTRAINT fk_ac_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_work_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  work_date   DATE NOT NULL,
  summary     VARCHAR(200) NOT NULL,
  details     TEXT DEFAULT NULL,
  hours       DECIMAL(4,2) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id),
  KEY idx_user (user_id),
  CONSTRAINT fk_wl_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_wl_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS daily_tasks (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  task_date   DATE NOT NULL,
  title       VARCHAR(200) NOT NULL,
  description TEXT DEFAULT NULL,
  hours       DECIMAL(4,2) NOT NULL DEFAULT 0,
  status      ENUM('in_progress','completed','blocked','pending') NOT NULL DEFAULT 'completed',
  category    VARCHAR(60) DEFAULT NULL,
  ticket_id   INT UNSIGNED DEFAULT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user_date (user_id, task_date),
  KEY idx_dt_ticket (ticket_id),
  CONSTRAINT fk_dt_user   FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_dt_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users.department_id points at departments, which is declared after users above.
ALTER TABLE users ADD CONSTRAINT fk_u_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
