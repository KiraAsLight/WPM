<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

// ADMIN CHECK - hanya admin (role_id = 1) yang bisa akses
$isAdmin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;

if (!$isAdmin) {
    // Jika bukan admin, redirect ke dashboard dengan pesan error
    $_SESSION['error'] = 'Anda tidak memiliki akses ke halaman User Management';
    header('Location: dashboard.php');
    exit;
}

$appName = APP_NAME;
$activeMenu = 'User Management';

// Handle form actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_user':
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $department = trim($_POST['department'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $role_id = (int)($_POST['role_id'] ?? 8);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                // Validasi
                if (empty($username) || empty($password) || empty($full_name)) {
                    $message = 'Username, Password, dan Nama Lengkap wajib diisi';
                    $messageType = 'error';
                } elseif (strlen($password) < 6) {
                    $message = 'Password harus minimal 6 karakter';
                    $messageType = 'error';
                } else {
                    // Check jika username sudah ada
                    $existing = fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
                    if ($existing) {
                        $message = 'Username sudah digunakan';
                        $messageType = 'error';
                    } else {
                        // Create user
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $result = execute(
                            'INSERT INTO users (username, password_hash, full_name, email, department, phone, role_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                            [$username, $hashedPassword, $full_name, $email, $department, $phone, $role_id, $is_active]
                        );

                        if ($result) {
                            $message = 'User berhasil dibuat';
                            $messageType = 'success';
                            
                            // Refresh user list
                            $users = fetchAll('
                                SELECT u.*, r.name as role_name, r.description as role_description 
                                FROM users u 
                                LEFT JOIN roles r ON u.role_id = r.id 
                                ORDER BY u.created_at DESC
                            ');
                        } else {
                            $message = 'Gagal membuat user';
                            $messageType = 'error';
                        }
                    }
                }
                break;

            case 'update_user':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $full_name = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $department = trim($_POST['department'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $role_id = (int)($_POST['role_id'] ?? 8);
                $is_active = isset($_POST['is_active']) ? 1 : 0;

                // Update user
                $result = execute(
                    'UPDATE users SET full_name = ?, email = ?, department = ?, phone = ?, role_id = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [$full_name, $email, $department, $phone, $role_id, $is_active, $user_id]
                );

                if ($result) {
                    $message = 'User berhasil diupdate';
                    $messageType = 'success';
                    
                    // Refresh user list
                    $users = fetchAll('
                        SELECT u.*, r.name as role_name, r.description as role_description 
                        FROM users u 
                        LEFT JOIN roles r ON u.role_id = r.id 
                        ORDER BY u.created_at DESC
                    ');
                } else {
                    $message = 'Gagal mengupdate user';
                    $messageType = 'error';
                }
                break;

            case 'delete_user':
                $user_id = (int)($_POST['user_id'] ?? 0);
                
                // Prevent self-deletion
                if ($user_id === $_SESSION['user_id']) {
                    $message = 'Tidak dapat menghapus akun sendiri';
                    $messageType = 'error';
                } else {
                    $result = execute('DELETE FROM users WHERE id = ?', [$user_id]);
                    
                    if ($result) {
                        $message = 'User berhasil dihapus';
                        $messageType = 'success';
                        
                        // Refresh user list
                        $users = fetchAll('
                            SELECT u.*, r.name as role_name, r.description as role_description 
                            FROM users u 
                            LEFT JOIN roles r ON u.role_id = r.id 
                            ORDER BY u.created_at DESC
                        ');
                    } else {
                        $message = 'Gagal menghapus user';
                        $messageType = 'error';
                    }
                }
                break;

            case 'reset_password':
                $user_id = (int)($_POST['user_id'] ?? 0);
                $new_password = 'password123'; // Default reset password
                
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $result = execute(
                    'UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
                    [$hashedPassword, $user_id]
                );

                if ($result) {
                    $message = 'Password berhasil direset ke: password123';
                    $messageType = 'success';
                } else {
                    $message = 'Gagal reset password';
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Get all users dengan role info
$users = fetchAll('
    SELECT u.*, r.name as role_name, r.description as role_description 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.created_at DESC
');

// Get all roles untuk dropdown
$roles = fetchAll('SELECT * FROM roles ORDER BY id');

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($activeMenu) ?> - <?= h($appName) ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        .admin-header {
            background: linear-gradient(135deg, #1e40af, #3730a3);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }

        .admin-header h1 {
            margin: 0;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-management {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
        }

        @media (max-width: 1024px) {
            .user-management {
                grid-template-columns: 1fr;
            }
        }

        .user-form {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 25px;
            height: fit-content;
            position: sticky;
            top: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--text);
            font-weight: 500;
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: rgba(255, 255, 255, 0.08);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: rgba(34, 197, 94, 0.1);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.1);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .user-table th,
        .user-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        .user-table th {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .user-table tr:hover td {
            background: rgba(255, 255, 255, 0.02);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-active {
            background: rgba(34, 197, 94, 0.1);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .status-inactive {
            background: rgba(156, 163, 175, 0.1);
            color: #9ca3af;
            border: 1px solid rgba(156, 163, 175, 0.2);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.2); }
        .role-engineering { background: rgba(59, 130, 246, 0.1); color: #93c5fd; border: 1px solid rgba(59, 130, 246, 0.2); }
        .role-purchasing { background: rgba(168, 85, 247, 0.1); color: #c4b5fd; border: 1px solid rgba(168, 85, 247, 0.2); }
        .role-qc { background: rgba(34, 197, 94, 0.1); color: #86efac; border: 1px solid rgba(34, 197, 94, 0.2); }
        .role-pabrikasi { background: rgba(245, 158, 11, 0.1); color: #fde68a; border: 1px solid rgba(245, 158, 11, 0.2); }
        .role-sipil { background: rgba(14, 165, 233, 0.1); color: #7dd3fc; border: 1px solid rgba(14, 165, 233, 0.2); }
        .role-logistik { background: rgba(236, 72, 153, 0.1); color: #f9a8d4; border: 1px solid rgba(236, 72, 153, 0.2); }
        .role-viewer { background: rgba(156, 163, 175, 0.1); color: #d1d5db; border: 1px solid rgba(156, 163, 175, 0.2); }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #3b82f6;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .current-user-indicator {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.2);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #86efac;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="brand">
                <div class="logo" aria-hidden="true"></div>
            </div>
            <nav class="nav">
                <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php">
                    <span class="icon bi-house"></span> Dashboard
                </a>
                <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php">
                    <span class="icon bi-journal-text"></span> PON
                </a>
                <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php">
                    <span class="icon bi-list-check"></span> Task List
                </a>
                <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php">
                    <span class="icon bi-bar-chart"></span> Progres Divisi
                </a>
                <!-- Hanya tampilkan menu User Management untuk Admin -->
                <?php if ($isAdmin): ?>
                <a class="<?= $activeMenu === 'User Management' ? 'active' : '' ?>" href="users.php">
                    <span class="icon bi-people"></span> User Management
                </a>
                <?php endif; ?>
                <a href="logout.php">
                    <span class="icon bi-box-arrow-right"></span> Logout
                </a>
            </nav>
        </aside>

        <!-- Header -->
        <header class="header">
            <div class="title"><?= h($activeMenu) ?></div>
            <div class="meta">
                <div>Role: <strong>Administrator</strong></div>
                <div>User: <?= h($_SESSION['full_name'] ?? 'Guest') ?></div>
                <div class="badge b-ok">Full Access</div>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <!-- Admin Header -->
            <div class="admin-header">
                <h1>
                    <i class="bi bi-shield-lock"></i>
                    User Management - Administrator Panel
                </h1>
                <p style="margin: 5px 0 0 0; opacity: 0.9;">Kelola semua user dan permissions sistem</p>
            </div>

            <?php if ($message): ?>
                <div class="message <?= $messageType ?>">
                    <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= h($message) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-number"><?= count($users) ?></div>
                    <div class="stat-label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($users, fn($u) => $u['is_active'])) ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count(array_filter($users, fn($u) => !$u['is_active'])) ?></div>
                    <div class="stat-label">Inactive Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= count($roles) ?></div>
                    <div class="stat-label">Available Roles</div>
                </div>
            </div>

            <div class="user-management">
                <!-- User List -->
                <div>
                    <div class="section">
                        <div class="hd">
                            <i class="bi bi-list-ul"></i>
                            Daftar Semua Pengguna
                            <span style="float: right; font-size: 12px; color: var(--muted); font-weight: normal;">
                                Last Updated: <?= date('d/m/Y H:i:s') ?>
                            </span>
                        </div>
                        <div class="bd">
                            <table class="user-table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Department</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="6" style="text-align: center; color: var(--muted); padding: 40px;">
                                                <i class="bi bi-people" style="font-size: 32px; opacity: 0.5; display: block; margin-bottom: 10px;"></i>
                                                Belum ada user terdaftar
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <strong><?= h($user['username']) ?></strong>
                                                        <?php if ($user['id'] === $_SESSION['user_id']): ?>
                                                            <span class="current-user-indicator">Anda</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                                                        <?= h($user['full_name']) ?>
                                                        <?php if ($user['email']): ?>
                                                            <br><?= h($user['email']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="role-badge role-<?= h($user['role_name']) ?>">
                                                        <?= h($user['role_name']) ?>
                                                    </span>
                                                </td>
                                                <td><?= h($user['department'] ?? '-') ?></td>
                                                <td>
                                                    <span class="status-badge <?= $user['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                                        <i class="bi bi-<?= $user['is_active'] ? 'check-circle' : 'x-circle' ?>"></i>
                                                        <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($user['last_login']): ?>
                                                        <?= date('d/m/Y H:i', strtotime($user['last_login'])) ?>
                                                    <?php else: ?>
                                                        <span style="color: var(--muted);">Belum login</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-primary btn-sm" 
                                                                onclick="editUser(<?= $user['id'] ?>, '<?= h($user['username']) ?>', '<?= h($user['full_name']) ?>', '<?= h($user['email'] ?? '') ?>', '<?= h($user['department'] ?? '') ?>', '<?= h($user['phone'] ?? '') ?>', <?= $user['role_id'] ?>, <?= $user['is_active'] ?>)">
                                                            <i class="bi bi-pencil"></i> Edit
                                                        </button>
                                                        
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                            <input type="hidden" name="action" value="reset_password">
                                                            <button type="submit" class="btn btn-warning btn-sm" 
                                                                    onclick="return confirm('Reset password user <?= h($user['username']) ?> ke default?')">
                                                                <i class="bi bi-key"></i> Reset PW
                                                            </button>
                                                        </form>

                                                        <?php if ($user['id'] !== $_SESSION['user_id']): ?>
                                                            <form method="POST" style="display: inline;">
                                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                                <input type="hidden" name="action" value="delete_user">
                                                                <button type="submit" class="btn btn-danger btn-sm" 
                                                                        onclick="return confirm('Hapus user <?= h($user['username']) ?>? Tindakan ini tidak dapat dibatalkan!')">
                                                                    <i class="bi bi-trash"></i> Hapus
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- User Form -->
                <div class="user-form">
                    <h3 style="margin-top: 0; margin-bottom: 20px; color: var(--text); display: flex; align-items: center; gap: 10px;" id="form-title">
                        <i class="bi bi-person-plus"></i>
                        <span>Tambah User Baru</span>
                    </h3>
                    
                    <form method="POST" id="user-form">
                        <input type="hidden" name="user_id" id="user_id" value="">
                        <input type="hidden" name="action" id="form-action" value="create_user">
                        
                        <div class="form-group">
                            <label for="username"><i class="bi bi-person"></i> Username</label>
                            <input type="text" id="username" name="username" class="form-control" required 
                                   placeholder="contoh: eng01, pur01">
                        </div>

                        <div class="form-group" id="password-group">
                            <label for="password"><i class="bi bi-lock"></i> Password</label>
                            <input type="password" id="password" name="password" class="form-control" required
                                   placeholder="Minimal 6 karakter">
                        </div>

                        <div class="form-group">
                            <label for="full_name"><i class="bi bi-card-text"></i> Nama Lengkap</label>
                            <input type="text" id="full_name" name="full_name" class="form-control" required
                                   placeholder="Nama lengkap user">
                        </div>

                        <div class="form-group">
                            <label for="email"><i class="bi bi-envelope"></i> Email</label>
                            <input type="email" id="email" name="email" class="form-control"
                                   placeholder="email@perusahaan.com">
                        </div>

                        <div class="form-group">
                            <label for="department"><i class="bi bi-building"></i> Department</label>
                            <input type="text" id="department" name="department" class="form-control"
                                   placeholder="Department user">
                        </div>

                        <div class="form-group">
                            <label for="phone"><i class="bi bi-telephone"></i> No. Telepon</label>
                            <input type="text" id="phone" name="phone" class="form-control"
                                   placeholder="Nomor telepon">
                        </div>

                        <div class="form-group">
                            <label for="role_id"><i class="bi bi-shield-check"></i> Role</label>
                            <select id="role_id" name="role_id" class="form-control" required>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= $role['id'] ?>"><?= h($role['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="is_active" name="is_active" value="1" checked>
                                <label for="is_active"><i class="bi bi-toggle-on"></i> User Aktif</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary" id="submit-btn">
                                <i class="bi bi-person-plus"></i> Tambah User
                            </button>
                            <button type="button" class="btn" onclick="resetForm()" style="background: var(--border); color: var(--text);">
                                <i class="bi bi-arrow-clockwise"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • User Management System</footer>
    </div>

    <script>
        function editUser(id, username, fullName, email, department, phone, roleId, isActive) {
            document.getElementById('form-title').innerHTML = '<i class="bi bi-pencil"></i> Edit User: ' + username;
            document.getElementById('user_id').value = id;
            document.getElementById('username').value = username;
            document.getElementById('username').readOnly = true;
            document.getElementById('full_name').value = fullName;
            document.getElementById('email').value = email;
            document.getElementById('department').value = department;
            document.getElementById('phone').value = phone;
            document.getElementById('role_id').value = roleId;
            document.getElementById('is_active').checked = !!isActive;
            document.getElementById('form-action').value = 'update_user';
            document.getElementById('password-group').style.display = 'none';
            document.getElementById('submit-btn').innerHTML = '<i class="bi bi-check-circle"></i> Update User';
            
            // Scroll to form
            document.querySelector('.user-form').scrollIntoView({ behavior: 'smooth' });
        }

        function resetForm() {
            document.getElementById('form-title').innerHTML = '<i class="bi bi-person-plus"></i> Tambah User Baru';
            document.getElementById('user-form').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('username').readOnly = false;
            document.getElementById('form-action').value = 'create_user';
            document.getElementById('password-group').style.display = 'block';
            document.getElementById('submit-btn').innerHTML = '<i class="bi bi-person-plus"></i> Tambah User';
            document.getElementById('is_active').checked = true;
        }

        // Reset form ketika halaman dimuat
        document.addEventListener('DOMContentLoaded', resetForm);

        // Form validation
        document.getElementById('user-form').addEventListener('submit', function(e) {
            const password = document.getElementById('password');
            if (password && password.style.display !== 'none' && password.value.length < 6) {
                e.preventDefault();
                alert('Password harus minimal 6 karakter');
                password.focus();
            }
        });
    </script>
</body>
</html>