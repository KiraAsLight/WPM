-- Hapus roles lama dan buat yang baru
DELETE FROM roles;

-- Insert roles sesuai kebutuhan
INSERT INTO roles (id, name, description, permissions) VALUES
(1, 'admin', 'Administrator Full Access', '["*"]'),
(2, 'engineering', 'Engineering Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(3, 'purchasing', 'Purchasing Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(4, 'qc', 'Quality Control Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(5, 'pabrikasi', 'Pabrikasi Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(6, 'sipil', 'Pekerjaan Sipil Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(7, 'logistik', 'Logistik Department', '["project.view", "task.view", "task.create", "task.edit", "task.edit_own", "report.view"]'),
(8, 'viewer', 'View Only - No Edit', '["project.view", "task.view", "report.view"]');

-- Update user admin yang sudah ada
UPDATE users SET role_id = 1 WHERE username = 'adminwgj';