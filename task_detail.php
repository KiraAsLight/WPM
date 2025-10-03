<?php

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$appName = APP_NAME;
$activeMenu = 'Task List';
$server = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache';
$nowEpoch = time();

// Get parameters
$ponCode = isset($_GET['pon']) ? trim($_GET['pon']) : '';
$division = isset($_GET['div']) ? trim($_GET['div']) : '';

if (!$ponCode || !$division) {
    header('Location: tasklist.php');
    exit;
}

// Verify PON exists
$ponRecord = fetchOne('SELECT * FROM pon WHERE pon = ?', [$ponCode]);
if (!$ponRecord) {
    header('Location: tasklist.php?error=pon_not_found');
    exit;
}

$validDivisions = ['Engineering', 'Logistik', 'Pabrikasi', 'Purchasing'];
if (!in_array($division, $validDivisions)) {
    header('Location: tasklist.php?error=invalid_division');
    exit;
}

// Get all tasks for this PON and division
$tasks = fetchAll('SELECT * FROM tasks WHERE pon = ? AND division = ? ORDER BY created_at DESC', [$ponCode, $division]);

// Handle task deletion
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $taskId = (int)$_GET['id'];
    delete('tasks', 'id = :id', ['id' => $taskId]);
    header('Location: task_detail.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division) . '&deleted=1');
    exit;
}

// Get success messages
$successMsg = '';
if (isset($_GET['added'])) {
    $successMsg = 'Task berhasil ditambahkan!';
} elseif (isset($_GET['updated'])) {
    $successMsg = 'Task berhasil diupdate!';
} elseif (isset($_GET['deleted'])) {
    $successMsg = 'Task berhasil dihapus!';
}

// Calculate statistics
$totalTasks = count($tasks);
$todoTasks = count(array_filter($tasks, fn($t) => strtolower($t['status'] ?? '') === 'todo'));
$onProgressTasks = count(array_filter($tasks, fn($t) => strtolower($t['status'] ?? '') === 'on proses'));
$doneTasks = count(array_filter($tasks, fn($t) => strtolower($t['status'] ?? '') === 'done'));
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Task List > <?= strtoupper(h($ponCode)) ?> > <?= h($division) ?> - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime('assets/css/app.css') ?>">
    <link rel="stylesheet" href="assets/css/sidebar.css?v=<?= filemtime('assets/css/sidebar.css') ?>">
    <link rel="stylesheet" href="assets/css/layout.css?v=<?= filemtime('assets/css/layout.css') ?>">
    <style>
        /* Page Header */
        .page-header {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text);
        }

        .add-task-btn {
            background: #1d4ed8;
            border: 1px solid #3b82f6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .add-task-btn:hover {
            background: #1e40af;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 500;
        }

        /* Success Message */
        .success-msg {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Table Container */
        .table-section {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .section-header-bar {
            background: #2d3748;
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border);
        }

        .section-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        /* Task table styles */
        .task-table {
            width: 100%;
            border-collapse: collapse;
        }

        .task-table thead {
            background: #374151;
        }

        .task-table th {
            padding: 12px 16px;
            text-align: left;
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .task-table td {
            padding: 12px 16px;
            color: var(--text);
            font-size: 13px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .task-table tbody tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* Progress Bar */
        .progress-bar-container {
            width: 100%;
            max-width: 100px;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        /* Status Badge */
        .status-badge {
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-done {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .status-on-proses {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .status-hold {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }

        .status-todo {
            background: rgba(156, 163, 175, 0.2);
            color: #d1d5db;
        }

        .status-waiting-approve {
            background: rgba(168, 85, 247, 0.2);
            color: #c4b5fd;
        }

        /* Action Buttons */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
        }

        .btn-icon:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-icon.edit:hover {
            border-color: #3b82f6;
            color: #93c5fd;
        }

        .btn-icon.delete:hover {
            border-color: #ef4444;
            color: #fca5a5;
        }

        /* File Link */
        .file-link {
            color: #93c5fd;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
        }

        .file-link:hover {
            text-decoration: underline;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state p {
            margin-bottom: 20px;
        }

        /* Custom Scrollbar */
        .table-container {
            overflow-x: auto;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: rgba(147, 197, 253, 0.3);
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: rgba(147, 197, 253, 0.5);
        }
    </style>
</head>

<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="brand">
                <div class="logo" aria-hidden="true"></div>
            </div>
            <nav class="nav">
                <a class="<?= $activeMenu === 'Dashboard' ? 'active' : '' ?>" href="dashboard.php"><span class="icon bi-house"></span> Dashboard</a>
                <a class="<?= $activeMenu === 'PON' ? 'active' : '' ?>" href="pon.php"><span class="icon bi-journal-text"></span> PON</a>
                <a class="<?= $activeMenu === 'Task List' ? 'active' : '' ?>" href="tasklist.php"><span class="icon bi-list-check"></span> Task List</a>
                <a class="<?= $activeMenu === 'Progres Divisi' ? 'active' : '' ?>" href="progres_divisi.php"><span class="icon bi-bar-chart"></span> Progres Divisi</a>
                <a href="logout.php"><span class="icon bi-box-arrow-right"></span> Logout</a>
            </nav>
        </aside>

        <header class="header">
            <div class="title">Task List > <?= strtoupper(h($ponCode)) ?> > <?= h($division) ?></div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="header-left">
                    <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>" class="back-btn">
                        <i class="bi bi-arrow-left"></i>
                        Kembali
                    </a>
                    <div class="page-title"><?= h($division) ?> - Detail Task</div>
                </div>
                <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn">
                    <i class="bi bi-plus-lg"></i>
                    Tambah Task
                </a>
            </div>

            <?php if ($division === 'Pabrikasi'): ?>
                <!-- Redirect to Fabrikasi List -->
                <script>
                    window.location.href = 'fabrikasi_list.php?pon=<?= urlencode($ponCode) ?>';
                </script>
            <?php elseif ($division === 'Logistik'): ?>
                <!-- Redirect to Logistik List -->
                <script>
                    window.location.href = 'logistik_menu.php?pon=<?= urlencode($ponCode) ?>';
                </script>
            <?php elseif ($successMsg): ?>
                <div class="success-msg">
                    <i class="bi bi-check-circle"></i>
                    <?= h($successMsg) ?>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?= $totalTasks ?></div>
                    <div class="stat-label">Total Task</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $todoTasks ?></div>
                    <div class="stat-label">To Do</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $onProgressTasks ?></div>
                    <div class="stat-label">On Progress</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= $doneTasks ?></div>
                    <div class="stat-label">Done</div>
                </div>
            </div>

            <!-- Task Table -->
            <div class="table-section">
                <div class="section-header-bar">
                    <div class="section-title"><?= h($division) ?> - Detail Task</div>
                    <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn" style="padding: 8px 16px; font-size: 13px;">
                        <i class="bi bi-plus-lg"></i>
                        Tambah Task
                    </a>
                </div>

                <?php if (empty($tasks)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox"></i>
                        <p>Belum ada task untuk divisi <?= h($division) ?></p>
                        <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn" style="display: inline-flex;">
                            <i class="bi bi-plus-lg"></i>
                            Tambah Task Pertama
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-container">

                        <?php if ($division === 'Engineering'): ?>
                            <!-- Engineering Table - Format seperti Fabrikasi -->
                            <table class="task-table">
                                <thead>
                                    <tr>
                                        <th>Engineering Task</th>
                                        <th>Start</th>
                                        <th>Finish</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>PIC</th>
                                        <th>Files</th>
                                        <th>Keterangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= h($task['title'] ?? '-') ?></td>
                                            <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                            <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar-fill" style="width: <?= (int)($task['progress'] ?? 0) ?>%"></div>
                                                    </div>
                                                    <span style="font-size: 11px; color: var(--muted);"><?= (int)($task['progress'] ?? 0) ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $status = strtolower($task['status'] ?? 'todo');
                                                $statusClass = 'status-' . str_replace(' ', '-', $status);
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>"><?= h(ucfirst($task['status'] ?? 'ToDo')) ?></span>
                                            </td>
                                            <td><?= h($task['pic'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($task['files'])): ?>
                                                    <a href="<?= h($task['files']) ?>" target="_blank" class="file-link">
                                                        <i class="bi bi-file-earmark"></i>
                                                        Lihat File
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--muted); font-size: 12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= h($task['keterangan'] ?? '-') ?>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="btn-icon edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&delete=1&id=<?= $task['id'] ?>"
                                                        class="btn-icon delete"
                                                        title="Hapus"
                                                        onclick="return confirm('Yakin ingin menghapus task ini?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($division === 'Purchasing'): ?>
                            <!-- Purchasing Table - Format seperti Fabrikasi -->
                            <table class="task-table">
                                <thead>
                                    <tr>
                                        <th>Purchase Type</th>
                                        <th>Vendor</th>
                                        <th>No. PO</th>
                                        <th>Date PO</th>
                                        <th>Start</th>
                                        <th>Finish</th>
                                        <th>Status</th>
                                        <th>Qty</th>
                                        <th>Satuan</th>
                                        <th>Files</th>
                                        <th>Keterangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= h($task['title'] ?? '-') ?></td>
                                            <td><?= h($task['vendor'] ?? '-') ?></td>
                                            <td><?= h($task['no_po'] ?? '-') ?></td>
                                            <td><?= h(dmy($task['start_date'] ?? $task['created_at'] ?? '')) ?></td>
                                            <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                            <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                            <td>
                                                <?php
                                                $status = strtolower($task['status'] ?? 'todo');
                                                $statusClass = 'status-' . str_replace(' ', '-', $status);
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>"><?= h(ucfirst($task['status'] ?? 'ToDo')) ?></span>
                                            </td>
                                            <td><?= h($task['qty'] ?? '-') ?></td>
                                            <td><?= h($task['satuan'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($task['files'])): ?>
                                                    <a href="<?= h($task['files']) ?>" target="_blank" class="file-link">
                                                        <i class="bi bi-file-earmark"></i>
                                                        Lihat File
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--muted); font-size: 12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= h($task['keterangan'] ?? '-') ?>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="btn-icon edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&delete=1&id=<?= $task['id'] ?>"
                                                        class="btn-icon delete"
                                                        title="Hapus"
                                                        onclick="return confirm('Yakin ingin menghapus task ini?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($division === 'Logistik'): ?>
                            <!-- Logistik Table -->
                            <table class="task-table">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Start</th>
                                        <th>Finish</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>PIC</th>
                                        <th>Files</th>
                                        <th>Keterangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= h($task['title'] ?? '-') ?></td>
                                            <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                            <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar-fill" style="width: <?= (int)($task['progress'] ?? 0) ?>%"></div>
                                                    </div>
                                                    <span style="font-size: 11px; color: var(--muted);"><?= (int)($task['progress'] ?? 0) ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $status = strtolower($task['status'] ?? 'todo');
                                                $statusClass = 'status-' . str_replace(' ', '-', $status);
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>"><?= h(ucfirst($task['status'] ?? 'ToDo')) ?></span>
                                            </td>
                                            <td><?= h($task['pic'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($task['files'])): ?>
                                                    <a href="<?= h($task['files']) ?>" target="_blank" class="file-link">
                                                        <i class="bi bi-file-earmark"></i>
                                                        Lihat File
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--muted); font-size: 12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= h($task['keterangan'] ?? '-') ?>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="btn-icon edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&delete=1&id=<?= $task['id'] ?>"
                                                        class="btn-icon delete"
                                                        title="Hapus"
                                                        onclick="return confirm('Yakin ingin menghapus task ini?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                        <?php elseif ($division === 'Pabrikasi'): ?>
                            <!-- Pabrikasi Table -->
                            <table class="task-table">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Start</th>
                                        <th>Finish</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                        <th>PIC</th>
                                        <th>Foto</th>
                                        <th>Keterangan</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><?= h($task['title'] ?? '-') ?></td>
                                            <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                            <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <div class="progress-bar-container">
                                                        <div class="progress-bar-fill" style="width: <?= (int)($task['progress'] ?? 0) ?>%"></div>
                                                    </div>
                                                    <span style="font-size: 11px; color: var(--muted);"><?= (int)($task['progress'] ?? 0) ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $status = strtolower($task['status'] ?? 'todo');
                                                $statusClass = 'status-' . str_replace(' ', '-', $status);
                                                ?>
                                                <span class="status-badge <?= $statusClass ?>"><?= h(ucfirst($task['status'] ?? 'ToDo')) ?></span>
                                            </td>
                                            <td><?= h($task['pic'] ?? '-') ?></td>
                                            <td>
                                                <?php if (!empty($task['foto'])): ?>
                                                    <a href="<?= h($task['foto']) ?>" target="_blank" class="file-link">
                                                        <i class="bi bi-image"></i>
                                                        Lihat Foto
                                                    </a>
                                                <?php else: ?>
                                                    <span style="color: var(--muted); font-size: 12px;">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?= h($task['keterangan'] ?? '-') ?>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="btn-icon edit" title="Edit">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <a href="task_detail.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&delete=1&id=<?= $task['id'] ?>"
                                                        class="btn-icon delete"
                                                        title="Hapus"
                                                        onclick="return confirm('Yakin ingin menghapus task ini?')">
                                                        <i class="bi bi-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Dibangun cepat dengan PHP</footer>
    </div>

    <script>
        (function() {
            const el = document.getElementById('clock');
            if (!el) return;
            const tz = 'Asia/Jakarta';
            let now = new Date(Number(el.dataset.epoch) * 1000);

            function tick() {
                now = new Date(now.getTime() + 1000);
                el.textContent = now.toLocaleString('id-ID', {
                    timeZone: tz,
                    hour12: false
                });
            }
            tick();
            setInterval(tick, 1000);
        })();
    </script>
</body>

</html>