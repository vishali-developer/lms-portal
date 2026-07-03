-- ============================================================
-- Lead Management System (LMS) - Full Database Schema
-- Version: 1.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS lms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lms_db;

-- ============================================================
-- TABLE: users
-- Stores admin, manager, and employee accounts
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) NOT NULL UNIQUE,
    phone       VARCHAR(20),
    password    VARCHAR(255) NOT NULL,          -- bcrypt hash
    role        ENUM('admin','manager','employee') NOT NULL DEFAULT 'employee',
    profile_image VARCHAR(255) DEFAULT NULL,
    status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    reset_token VARCHAR(255) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    last_login  DATETIME DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: lead_sources
-- Configurable list of where leads come from
-- ============================================================

CREATE TABLE IF NOT EXISTS lead_sources (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO lead_sources (name) VALUES
('Website'),('Facebook'),('Instagram'),('Google Ads'),
('Referral'),('Cold Call'),('Email Campaign'),('Walk-in'),('Other');

-- ============================================================
-- TABLE: leads
-- Core lead records
-- ============================================================

CREATE TABLE IF NOT EXISTS leads (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    phone       VARCHAR(20),
    email       VARCHAR(150),
    company     VARCHAR(150),
    service     VARCHAR(150),
    message     TEXT,
    source_id   INT DEFAULT NULL,
    status      ENUM('New','Contacted','Follow-up','Interested','Converted','Closed','Rejected')
                    NOT NULL DEFAULT 'New',
    priority    ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
    assigned_to INT DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (source_id)   REFERENCES lead_sources(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: followups
-- Follow-up notes and scheduling per lead
-- ============================================================
CREATE TABLE IF NOT EXISTS followups (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    lead_id          INT NOT NULL,
    employee_id      INT NOT NULL,
    note             TEXT NOT NULL,
    next_followup_date DATE DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id)     REFERENCES leads(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: notifications
-- System notifications for users
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    title       VARCHAR(255) NOT NULL,
    message     TEXT NOT NULL,
    type        ENUM('info','success','warning','danger') DEFAULT 'info',
    is_read     TINYINT(1) DEFAULT 0,
    link        VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: activity_logs
-- Tracks every significant action in the system
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT DEFAULT NULL,
    action      VARCHAR(255) NOT NULL,
    description TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: settings
-- Key-value store for system settings
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_val TEXT,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO settings (setting_key, setting_val) VALUES
('company_name',    'My Company Ltd'),
('company_email',   'admin@company.com'),
('company_phone',   '+91-9999999999'),
('company_address', '123 Business Street, City'),
('smtp_host',       ''),
('smtp_port',       '587'),
('smtp_user',       ''),
('smtp_pass',       ''),
('smtp_from',       ''),
('app_version',     '1.0.0');

-- ============================================================
-- Default Admin User
-- Password: Admin@123  (bcrypt)
-- ============================================================
INSERT INTO users (name, email, phone, password, role, status) VALUES
('Super Admin', 'admin@lms.com', '9000000001',
 '$2y$12$Y3xHi5TUGfz/K2VjKv7LteVYvfBUlsxZqMD0sFGl5r7FjFTORnzqO',
 'admin', 'active');

-- Sample employee
INSERT INTO users (name, email, phone, password, role, status) VALUES
('John Employee', 'john@lms.com', '9000000002',
 '$2y$12$Y3xHi5TUGfz/K2VjKv7LteVYvfBUlsxZqMD0sFGl5r7FjFTORnzqO',
 'employee', 'active');

-- Sample leads
INSERT INTO leads (name, phone, email, company, service, message, source_id, status, priority, assigned_to, created_by) VALUES
('Ravi Sharma',   '9111111111', 'ravi@email.com',   'Sharma Pvt Ltd',  'Web Design',  'Need a corporate website.',      1, 'New',       'High',   2, 1),
('Priya Singh',   '9222222222', 'priya@email.com',  'Singh Enterprises','SEO Service', 'Want to rank on Google.',         2, 'Contacted', 'Medium', 2, 1),
('Anil Kumar',    '9333333333', 'anil@email.com',   'Kumar Co',        'App Dev',     'Mobile app for e-commerce.',     3, 'Follow-up', 'High',   2, 1),
('Sunita Mehta',  '9444444444', 'sunita@email.com', 'Mehta Group',     'Digital Ads', 'FB & Instagram campaign needed.', 4, 'Interested','Low',    NULL, 1),
('Deepak Gupta',  '9555555555', 'deepak@email.com', 'Gupta Retail',    'CRM',         'Need CRM for our sales team.',   5, 'Converted', 'High',   2, 1);
