<?php
require_once 'config.php';
require_once 'send_email.php';

echo "<h2>🔧 Testing Gmail SMTP Configuration</h2>";

// Tampilkan config untuk verifikasi
echo "<h3>Current Configuration:</h3>";
echo "<pre>";
echo "SMTP_HOST: " . SMTP_HOST . "\n";
echo "SMTP_PORT: " . SMTP_PORT . "\n";
echo "SMTP_USERNAME: " . SMTP_USERNAME . "\n";
echo "SMTP_PASSWORD: " . (SMTP_PASSWORD ? "***SET***" : "***NOT SET***") . "\n";
echo "SMTP_SECURE: " . SMTP_SECURE . "\n";
echo "EMAIL_FROM: " . EMAIL_FROM . "\n";
echo "EMAIL_ADMIN: " . EMAIL_ADMIN . "\n";
echo "</pre>";

$testData = [
    'job_no' => 'W-TEST-001',
    'pon' => 'PON-TEST-001',
    'nama_proyek' => 'Project Test Gmail SMTP',
    'client' => 'PT. Client Test',
    'project_manager' => 'John Doe',
    'material_type' => 'Baja Ringan',
    'qty' => 5,
    'status' => 'Progress',
    'date_pon' => date('Y-m-d'),
    'project_start' => date('Y-m-d', strtotime('+1 week')),
    'subject' => 'Testing Gmail SMTP Configuration'
];

echo "<h3>📧 Sending Test Email...</h3>";

$result = sendPONNotification($testData, 'created');

if ($result) {
    echo "<p style='color: green; font-size: 18px;'>✅ Email sent successfully!</p>";
    echo "<p>Check your email at <strong>" . EMAIL_ADMIN . "</strong></p>";
} else {
    echo "<p style='color: red; font-size: 18px;'>❌ Failed to send email.</p>";
    echo "<p>Check error_log for detailed debugging information.</p>";
}

echo "<hr>";
echo "<p><a href='pon_new.php'>➕ Create New PON</a> | <a href='pon.php'>📋 PON List</a></p>";
