-- Database Migration Script for PT. Wiratama Globalindo Jaya Project Management System
-- This script creates tables for PON (Project Order Number) and Tasks, and provides sample queries for application functionality.

-- Create database (if not exists)
CREATE DATABASE IF NOT EXISTS wiratama_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE wiratama_db;

-- Table for PON (Project Order Number)
CREATE TABLE pon (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pon VARCHAR(50) NOT NULL UNIQUE,
    type VARCHAR(100) NOT NULL,
    client VARCHAR(255),
    nama_proyek VARCHAR(255),
    job_type VARCHAR(100),
    berat DECIMAL(10,2) DEFAULT 0,
    qty INT DEFAULT 0,
    progress INT DEFAULT 0 CHECK (progress >= 0 AND progress <= 100),
    date_pon DATE,
    date_finish DATE,
    status ENUM('Selesai', 'Progres', 'Pending', 'Delayed') DEFAULT 'Progres',
    alamat_kontrak TEXT,
    no_contract VARCHAR(100),
    pic VARCHAR(255),
    owner VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pon (pon),
    INDEX idx_status (status),
    INDEX idx_client (client)
);

-- Table for Tasks
CREATE TABLE tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pon VARCHAR(50) NOT NULL,
    division ENUM('Engineering', 'Logistik', 'Pabrikasi', 'Purchasing') NOT NULL,
    title VARCHAR(255) NOT NULL,
    assignee VARCHAR(255) DEFAULT '',
    priority ENUM('Low', 'Medium', 'High') DEFAULT 'Medium',
    progress INT DEFAULT 0 CHECK (progress >= 0 AND progress <= 100),
    status ENUM('To Do', 'In Progress', 'Review', 'Blocked', 'Done') DEFAULT 'To Do',
    start_date DATE,
    due_date DATE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pon) REFERENCES pon(pon) ON DELETE CASCADE,
    INDEX idx_pon_division (pon, division),
    INDEX idx_status (status)
);

-- Sample Queries for Application Functionality

-- 1. Dashboard Statistics
-- Total PON count
SELECT COUNT(*) as total_pon FROM pon;

-- Total berat aggregate
SELECT SUM(berat) as total_berat FROM pon;

-- Average progress across all PON
SELECT ROUND(AVG(progress), 0) as avg_progress FROM pon;

-- PON completed count
SELECT COUNT(*) as completed_pon FROM pon WHERE status = 'Selesai';

-- 2. Division Progress Aggregation (for dashboard and progres_divisi.php)
SELECT
    division,
    ROUND(AVG(progress), 0) as avg_progress,
    COUNT(*) as task_count
FROM tasks
GROUP BY division;

-- 3. PON List with filtering (for pon.php)
SELECT
    id, pon, client, type, qty, progress, pic, owner, status, date_pon, date_finish
FROM pon
ORDER BY date_pon DESC;

-- Filter by PON/Type/Status (use in WHERE clause)
-- WHERE pon LIKE '%search%' OR type LIKE '%search%' OR status LIKE '%search%'

-- 4. PON Details for specific PON
SELECT * FROM pon WHERE pon = 'PON001';

-- 5. Tasks for specific PON
SELECT * FROM tasks WHERE pon = 'PON001' ORDER BY division, start_date;

-- 6. Tasks for specific PON and Division (for tasklist.php)
SELECT * FROM tasks WHERE pon = 'PON001' AND division = 'Engineering' ORDER BY start_date;

-- 7. Update task progress and status
UPDATE tasks
SET progress = 75, status = 'In Progress', updated_at = NOW()
WHERE id = 1;

-- 8. Insert new PON
INSERT INTO pon (pon, type, client, nama_proyek, job_type, berat, qty, date_pon, status, pic, owner)
VALUES ('PON001', 'Rangka', 'Client A', 'Proyek A', 'Pengadaan', 1000.50, 5, '2025-01-01', 'Progres', 'John Doe', 'Jane Smith');

-- 9. Insert default tasks for new PON (4 tasks per division)
INSERT INTO tasks (pon, division, title, start_date, due_date) VALUES
('PON001', 'Engineering', 'Design Engineering', '2025-01-01', '2025-02-01'),
('PON001', 'Logistik', 'Material Procurement', '2025-01-01', '2025-02-15'),
('PON001', 'Pabrikasi', 'Manufacturing', '2025-02-01', '2025-03-01'),
('PON001', 'Purchasing', 'Vendor Management', '2025-01-01', '2025-02-28');

-- 10. Update PON progress (calculated from tasks average)
UPDATE pon
SET progress = (
    SELECT ROUND(AVG(progress), 0)
    FROM tasks
    WHERE tasks.pon = pon.pon
)
WHERE pon = 'PON001';

-- 11. Delete PON (cascade deletes tasks)
DELETE FROM pon WHERE pon = 'PON001';

-- 12. Top 6 heaviest PON for bar chart (dashboard.php)
SELECT pon, berat, type
FROM pon
ORDER BY berat DESC
LIMIT 6;

-- 13. PON list for tasklist.php (first stage)
SELECT DISTINCT pon, client, nama_proyek
FROM pon
ORDER BY date_pon DESC;

-- 14. Divisions for specific PON (tasklist.php second stage)
SELECT
    division,
    ROUND(AVG(progress), 0) as avg_progress,
    COUNT(*) as task_count
FROM tasks
WHERE pon = 'PON001'
GROUP BY division;

-- 15. PON list for progres_divisi.php dropdown
SELECT DISTINCT pon, client
FROM pon
ORDER BY pon;

-- 16. Check for duplicate PON on insert
SELECT COUNT(*) as count FROM pon WHERE pon = 'PON001';

-- 17. Get PON data for edit form
SELECT * FROM pon WHERE pon = 'PON001';

-- 18. Update PON
UPDATE pon
SET type = 'Updated Type', client = 'Updated Client', updated_at = NOW()
WHERE pon = 'PON001';

-- 19. Get all tasks for progres_divisi.php
SELECT * FROM tasks WHERE pon = 'PON001' ORDER BY division, title;

-- 20. Bulk update task (if needed)
UPDATE tasks
SET progress = GREATEST(0, LEAST(100, progress + 10)), updated_at = NOW()
WHERE pon = 'PON001' AND division = 'Engineering';

-- Indexes for performance (already included in CREATE TABLE, but additional if needed)
-- CREATE INDEX idx_pon_date ON pon(date_pon);
-- CREATE INDEX idx_tasks_updated ON tasks(updated_at);

-- Sample data insertion (from existing JSON structure)
-- Note: Run this after creating tables to migrate existing JSON data
-- INSERT INTO pon (pon, type, client, nama_proyek, job_type, berat, qty, progress, date_pon, date_finish, status, alamat_kontrak, no_contract, pic, owner)
-- SELECT * FROM JSON data (use PHP script to import)

-- End of migration script
