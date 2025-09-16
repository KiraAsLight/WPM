-- SQL script to create a user table for authentication

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(100),
  email VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert user adminwgj with password wgj@2025#
-- Password hash generated using PHP password_hash('wgj@2025#', PASSWORD_DEFAULT)
INSERT INTO users (username, password_hash, full_name, email) VALUES
('adminwgj', '$2y$10$i2Z.mAMDMyo6xmB5Lbj/lOdbs3i081MymZ..LXWSqNROGfcBnx2Pi', 'Administrator WGJ', 'adminwgj@example.com');
