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
$activeTab = isset($_GET['tab']) ? trim($_GET['tab']) : '';

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

// Define task categories based on division
$taskCategories = [
    'Engineering' => [
        'List Material' => 'List Material',
        'Shop Drawing' => 'Shop Drawing',
        'Erection' => 'Erection',
        'Manual Book' => 'Manual Book',
        'QC Dossier' => 'QC Dossier'
    ],
    'Logistik' => [
        'Pengiriman Material' => 'Pengiriman Material',
        'Koordinasi Transportasi' => 'Koordinasi Transportasi',
        'Dokumen Pengiriman' => 'Dokumen Pengiriman',
        'Tracking' => 'Tracking',
        'Delivery' => 'Delivery'
    ],
    'Pabrikasi' => [
        'Cutting' => 'Cutting',
        'Welding' => 'Welding',
        'Assembly' => 'Assembly',
        'Painting' => 'Painting',
        'QC Check' => 'QC Check'
    ],
    'Purchasing' => [
        'RFQ' => 'RFQ',
        'PO Processing' => 'PO Processing',
        'Vendor Management' => 'Vendor Management',
        'Material Receipt' => 'Material Receipt',
        'Invoice' => 'Invoice'
    ]
];

$categories = $taskCategories[$division] ?? [];
$defaultTab = !empty($categories) ? array_keys($categories)[0] : '';
if (!$activeTab) {
    $activeTab = $defaultTab;
}

// Get tasks for selected category
$tasksForCategory = [];
if ($activeTab) {
    $tasksForCategory = fetchAll(
        'SELECT * FROM tasks WHERE pon = ? AND division = ? AND title = ? ORDER BY start_date ASC',
        [$ponCode, $division, $activeTab]
    );
}

// Handle task update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
        $status = trim($_POST['status'] ?? '');

        $validStatuses = ['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'];
        if (in_array($status, $validStatuses)) {
            try {
                update(
                    'tasks',
                    ['progress' => $progress, 'status' => $status, 'updated_at' => date('Y-m-d H:i')],
                    'id = :id',
                    ['id' => $taskId]
                );

                // Update PON progress
                if (function_exists('updatePonProgress')) {
                    updatePonProgress($ponCode);
                }
            } catch (Exception $e) {
                error_log('Task update error: ' . $e->getMessage());
            }
        }
    } elseif ($_POST['action'] === 'delete') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        try {
            delete('tasks', 'id = :id', ['id' => $taskId]);

            // Update PON progress
            if (function_exists('updatePonProgress')) {
                updatePonProgress($ponCode);
            }
        } catch (Exception $e) {
            error_log('Task delete error: ' . $e->getMessage());
        }
    }

    // Redirect to refresh page
    header('Location: task_detail.php?pon=' . urlencode($ponCode) . '&div=' . urlencode($division) . '&tab=' . urlencode($activeTab));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($division) ?> - Detail Task - <?= h($appName) ?></title>
    <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet"
        href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/sidebar.css?v=<?= file_exists('assets/css/sidebar.css') ? filemtime('assets/css/sidebar.css') : time() ?>">
    <link rel="stylesheet"
        href="assets/css/layout.css?v=<?= file_exists('assets/css/layout.css') ? filemtime('assets/css/layout.css') : time() ?>">
    <style>
        /* Task Detail Page Styles */
        .page-header {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }

        .page-breadcrumb {
            display: flex;
            gap: 8px;
            align-items: center;
            color: var(--muted);
            font-size: 14px;
        }

        .page-breadcrumb a {
            color: #93c5fd;
            text-decoration: none;
        }

        .page-breadcrumb .sep {
            opacity: 0.5;
        }

        .task-container {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }

        .task-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
        }

        .add-task-btn {
            background: #059669;
            border: 1px solid #10b981;
            color: #fff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .add-task-btn:hover {
            background: #047857;
        }

        /* Tab Navigation */
        .tab-nav {
            display: flex;
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border);
            overflow-x: auto;
        }

        .tab-nav::-webkit-scrollbar {
            height: 3px;
        }

        .tab-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.1);
        }

        .tab-nav::-webkit-scrollbar-thumb {
            background: rgba(147, 197, 253, 0.5);
            border-radius: 2px;
        }

        .tab-item {
            display: inline-block;
            padding: 15px 20px;
            color: var(--muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }

        .tab-item:hover {
            color: var(--text);
            background: rgba(255, 255, 255, 0.05);
        }

        .tab-item.active {
            color: #93c5fd;
            border-bottom-color: #3b82f6;
            background: rgba(59, 130, 246, 0.1);
        }

        /* Task Table */
        .task-content {
            padding: 20px;
            min-height: 300px;
        }

        .tasks-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tasks-table th,
        .tasks-table td {
            padding: 12px;
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
        }

        .tasks-table td {
            color: var(--text);
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

        .status-waiting-approve {
            background: rgba(245, 158, 11, 0.2);
            color: #fbbf24;
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

        .inline-form {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .input-sm,
        .select-sm {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 4px 6px;
            border-radius: 4px;
            font-size: 11px;
        }

        .input-num {
            width: 60px;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        .btn-sm {
            background: #3b82f6;
            border: 1px solid #3b82f6;
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-sm:hover {
            background: #2563eb;
        }

        .action-buttons {
            display: flex;
            gap: 4px;
        }

        .btn-edit {
            background: #3b82f6;
            border: 1px solid #3b82f6;
            color: #fff;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 10px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .btn-delete {
            background: #dc2626;
            border: 1px solid #dc2626;
            color: #fff;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }

        .btn-delete:hover {
            background: #b91c1c;
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

        .file-link {
            color: #93c5fd;
            text-decoration: none;
            font-size: 11px;
        }

        .file-link:hover {
            text-decoration: underline;
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
            <div class="title"><?= h($division) ?> - Detail Task</div>
            <div class="meta">
                <div>Server: <?= h($server) ?></div>
                <div>PHP <?= PHP_VERSION ?></div>
                <div><span id="clock" data-epoch="<?= $nowEpoch ?>">—</span> WIB</div>
            </div>
        </header>

        <main class="content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title"><?= h($division) ?> - Detail Task</div>
                <div class="page-breadcrumb">
                    <a href="tasklist.php">Task List</a>
                    <span class="sep">›</span>
                    <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>"><?= strtoupper(h($ponCode)) ?></a>
                    <span class="sep">›</span>
                    <strong><?= h($division) ?></strong>
                </div>
            </div>

            <!-- Task Container -->
            <div class="task-container">
                <!-- Header with Add Button -->
                <div class="task-header">
                    <div>
                        <div class="task-title"><?= h($division) ?> > <?= h($activeTab ?: 'Pilih Kategori') ?></div>
                    </div>
                    <?php if ($activeTab): ?>
                        <div style="display: flex; gap: 8px;">
                            <a href="task_divisions.php?pon=<?= urlencode($ponCode) ?>" class="back-btn">
                                <i class="bi bi-arrow-left"></i>
                                Kembali
                            </a>
                            <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn">
                                <i class="bi bi-plus"></i>
                                Tambah Task
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Tab Navigation -->
                <div class="tab-nav">
                    <?php foreach ($categories as $key => $label): ?>
                        <a href="?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>&tab=<?= urlencode($key) ?>"
                            class="tab-item <?= $activeTab === $key ? 'active' : '' ?>">
                            <?= h($label) ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Task Content -->
                <div class="task-content">
                    <?php if (!$activeTab): ?>
                        <div class="empty-state">
                            <i class="bi bi-list-task"></i>
                            <div>Pilih kategori task dari tab di atas</div>
                        </div>
                    <?php elseif (empty($tasksForCategory)): ?>
                        <div class="empty-state">
                            <i class="bi bi-list-task"></i>
                            <div>Belum ada task untuk kategori <?= h($activeTab) ?></div>
                            <div style="margin-top: 16px;">
                                <a href="task_new.php?pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>" class="add-task-btn">
                                    <i class="bi bi-plus"></i>
                                    Tambah Task Pertama
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <table class="tasks-table">
                            <thead>
                                <tr>
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
                                <?php foreach ($tasksForCategory as $task):
                                    $progress = (int)($task['progress'] ?? 0);
                                    $status = strtolower(str_replace(' ', '-', $task['status'] ?? 'todo'));
                                    $statusClass = 'status-' . $status;
                                ?>
                                    <tr>
                                        <td><?= h(dmy($task['start_date'] ?? null)) ?></td>
                                        <td><?= h(dmy($task['due_date'] ?? null)) ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 8px;">
                                                <div class="progress-container">
                                                    <div class="progress-bar" style="width: <?= $progress ?>%"></div>
                                                </div>
                                                <span style="font-size: 11px; color: var(--muted);"><?= $progress ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= h($task['status'] ?? 'ToDo') ?>
                                            </span>
                                        </td>
                                        <td><?= h($task['pic'] ?? '-') ?></td>
                                        <td>
                                            <?php if (!empty($task['files'])): ?>
                                                <a href="<?= h($task['files']) ?>" class="file-link" target="_blank">
                                                    <i class="bi bi-file-earmark"></i> File
                                                </a>
                                            <?php elseif (!empty($task['foto'])): ?>
                                                <a href="<?= h($task['foto']) ?>" class="file-link" target="_blank">
                                                    <i class="bi bi-image"></i> Foto
                                                </a>
                                            <?php else: ?>
                                                <span style="color: var(--muted); font-size: 11px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($task['keterangan'])): ?>
                                                <span title="<?= h($task['keterangan']) ?>" style="font-size: 11px;">
                                                    <?= h(substr($task['keterangan'], 0, 30)) ?><?= strlen($task['keterangan']) > 30 ? '...' : '' ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--muted); font-size: 11px;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                <!-- Update Form -->
                                                <form method="post" class="inline-form">
                                                    <input type="hidden" name="action" value="update">
                                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                    <input class="input-sm input-num" name="progress" type="number" min="0" max="100" value="<?= $progress ?>">
                                                    <select class="select-sm" name="status">
                                                        <?php foreach (['ToDo', 'On Proses', 'Hold', 'Done', 'Waiting Approve'] as $st): ?>
                                                            <option value="<?= h($st) ?>" <?= ($task['status'] ?? '') === $st ? 'selected' : '' ?>>
                                                                <?= h($st) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn-sm" type="submit">Simpan</button>
                                                </form>

                                                <!-- Action Buttons -->
                                                <div class="action-buttons">
                                                    <a href="task_edit.php?id=<?= $task['id'] ?>&pon=<?= urlencode($ponCode) ?>&div=<?= urlencode($division) ?>"
                                                        class="btn-edit" title="Edit Task">
                                                        <i class="bi bi-pencil"></i> Edit
                                                    </a>
                                                    <form method="post" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus task ini?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                                        <button class="btn-delete" type="submit" title="Hapus Task">
                                                            <i class="bi bi-trash"></i> Hapus
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <footer class="footer">© <?= date('Y') ?> <?= h($appName) ?> • Task Detail</footer>
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