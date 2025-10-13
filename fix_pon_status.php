<?php
// File: fix_pon_status.php
// Script untuk memperbaiki data PON yang statusnya kosong

declare(strict_types=1);

session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

echo "<h2>Memperbaiki Status PON yang Kosong</h2>";

// 1. Check data yang statusnya kosong
$emptyStatusPons = fetchAll("SELECT id, job_no, pon, status FROM pon WHERE status = '' OR status IS NULL");
echo "<p>Found " . count($emptyStatusPons) . " PON with empty status</p>";

if (!empty($emptyStatusPons)) {
    echo "<ul>";
    foreach ($emptyStatusPons as $pon) {
        echo "<li>Job No: " . h($pon['job_no']) . " | PON: " . h($pon['pon']) . " | Status: '" . h($pon['status']) . "'</li>";
    }
    echo "</ul>";

    // 2. Update status yang kosong menjadi 'Progress'
    $updated = update('pon', ['status' => 'Progress'], "status = '' OR status IS NULL");

    if ($updated) {
        echo "<div style='color: green; font-weight: bold;'>✅ Berhasil memperbaiki " . $updated . " data PON</div>";
    } else {
        echo "<div style='color: red;'>❌ Gagal memperbaiki data PON</div>";
    }
} else {
    echo "<div style='color: green;'>✅ Tidak ada data PON dengan status kosong</div>";
}

// 3. Verify fix
$remainingEmpty = fetchOne("SELECT COUNT(*) as count FROM pon WHERE status = '' OR status IS NULL");
echo "<p>Data dengan status kosong setelah perbaikan: " . $remainingEmpty['count'] . "</p>";

echo "<br><a href='pon.php'>Kembali ke Daftar PON</a>";
?>