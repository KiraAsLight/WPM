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

// Get tasks for this PON and division
$tasks = fetchAll('SELECT * FROM tasks WHERE pon = ? AND division = ? ORDER BY start_date ASC', [$ponCode, $division]);

// Handle task update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
    $status = trim($_POST['status'] ?? '');

    $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done'];
    if (in_array($status, $validStatuses)) {
        try {
            update('tasks', ['progress' => $progress, 'status' => $status, 'updated_at' => date('Y-m-d H:i')], 'id = :id', ['id' => $taskId]);

            // Update PON progress
            if (function_exists('updatePonProgress')) {
                updatePonProgress($ponCode);
            }
        } catch (Exception $e) {
            error_log('Task update error: ' . $e->getMessage());
        }
    }

    // Redirect to refresh page
    header('Location: task_detail.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tasks <?= h($division) ?> - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet"
        href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
    <style>
        .breadcrumb {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 20px;
            color: var(--muted);
            font-size: 14px;
        }

        .breadcrumb a {
            color: #93c5fd;
            text-decoration: none;
        }

        .breadcrumb .sep {
            opacity: 0.5;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            margin-bottom: 20px;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .add-task-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #059669;
            border: 1px solid #10b981;
            color: #fff;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .add-task-btn:hover {
            background: #047857;
        }

        .tasks-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .tasks-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tasks-table th,
        .tasks-table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }

        .tasks-table th {
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tasks-table td {
            color: var(--text);
        }

        .task-title {
            font-weight: 600;
            color: var(--text);
        }

        .inline-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .input-sm,
        .select-sm {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 6px 8px;
            border-radius: 4px;
            font-size: 12px;
        }

        .input-num {
            width: 70px;
        }

        .btn-sm {
            background: #3b82f6;
            border: 1px solid #3b82f6;
            color: #fff;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-sm:hover {
            background: #2563eb;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-todo {
            background: rgba(156, 163, 175, 0.2);
            color: #d1d5db;
        }

        .status-on-proses {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }

        .status-hold {
            background: rgba(245, 101, 101, 0.2);
            color: #f87171;
        }

        .status-done {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .progress-container {
            width: 80px;
            height: 6px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #06b6d4);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .action-buttons {
            display: flex;
            gap: 6px;
        }

        .btn-edit {
            background: #3b82f6;
            border: 1px solid #3b82f6;
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 60px 20px;
            font-size: 14px;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
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
            <div class="title">Tasks <?= h($division) ?></div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <a href="tasklist.php">Task List</a>
                <span class="sep">›</span>
                <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>"><?= strtoupper(h($ponCode)) ?></a>
                <span class="sep">›</span>
                <strong><?= h($division) ?></strong>
            </div>

            <!-- Back Button -->
            <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>" class="back-button">
                <i class="bi bi-arrow-left"></i>
                Kembali ke Pilih Divisi
            </a>

            <!-- Section Header with Add Task Button -->
            <div class="section-header">
                <div>
                    <h2 style="color: var(--text); margin: 0;">Tasks Divisi <?= h($division) ?></h2>
                    <p style="color: var(--muted); margin: 5px 0 0 0; font-size: 14px;">
                        PON: <?= strtoupper(h($ponCode)) ?> | <?= h($ponRecord['client'] ?? '-') ?>
                    </p>
                </div>
                <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn">
                    <i class="bi bi-plus-lg"></i>
                    Tambah Task
                </a>
            </div>

            <!-- Tasks Table -->
            <div class="tasks-container">
                <?php if (empty($tasks)): ?>
                    <div class="empty-state">
                        <i class="bi bi-list-task"></i>
                        <div>Belum ada task untuk divisi <?= h($division) ?></div>
                        <div style="margin-top: 8px;">
                            <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn">
                                <i class="bi bi-plus-lg"></i>
                                Tambah Task Pertama
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="tasks-table">
                        <thead>
                            <tr>
                                <th>Task</th>
                                <th>PIC</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Start Date</th>
                                <th>Due Date</th>
                                <?php if ($division === 'Purchasing'): ?>
                                    <th>Vendor</th>
                                    <th>No. PO</th>
                                <?php endif; ?>
                                <th>Update</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $progress = (int)($task['progress'] ?? 0);
                                $status = strtolower(str_replace(' ', '-', $task['status'] ?? 'todo'));
                                $statusClass = 'status-' . $status;
                            ?>
                                <tr>
                                    <td>
                                        <div class="task-title"><?= h($task['title'] ?? '') ?></div>
                                        <?php if (!empty($task['keterangan'])): ?>
                                            <div style="font-size: 12px; color: var(--muted); margin-top: 4px;">
                                                <?= h(substr($task['keterangan'], 0, 60)) ?><?= strlen($task['keterangan']) > 60 ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($task['pic'] ?? '-') ?></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <div class="progress-container">
                                                <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                                            </div>
                                            <span style="font-size: 12px; color: var(--muted);"><?= $progress ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>">
                                            <?= h($task['status'] ?? 'ToDo') ?>
                                        </span>
                                    </td>
                                    <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                    <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                    <?php if ($division === 'Purchasing'): ?>
                                        <td><?= h($task['vendor'] ?? '-') ?></td>
                                        <td><?= h($task['no_po'] ?? '-') ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                            <input class="input-sm input-num" name="progress" type="number" min="0" max="100" value="<?= $progress ?>">
                                            <select class="select-sm" name="status">
                                                <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done'] as $st): ?>
                                                    <option value="<?= h($st) ?>" <?= ($task['status'] ?? '') === $st ? 'selected' : '' ?>>
                                                        <?= h($st) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn-sm" type="submit">Simpan</button>
                                        </form>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>"
                                                class="btn-edit" title="Edit Task">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Task List</footer>
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