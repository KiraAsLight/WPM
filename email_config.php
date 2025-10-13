<?php
// File: email_config.php

// Email Configuration
define('EMAIL_FROM', 'aufamunadil9@gmail.com'); // Email Gmail Anda
define('EMAIL_FROM_NAME', APP_NAME . ' System');
define('EMAIL_ADMIN', 'aufamunadil9@gmail.com'); // Email penerima (sama atau berbeda)

// Gmail SMTP Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'aufamunadil9@gmail.com'); // Email Gmail lengkap
define('SMTP_PASSWORD', 'vwagefjmudesznes'); // App Password 16 digit
define('SMTP_SECURE', 'tls');
define('SMTP_AUTH', true);

// Email Templates (tetap sama)
function getEmailTemplate($template, $data = [])
{
    $templates = [
        'pon_created' => [
            'subject' => 'PON Baru Telah Dibuat - {job_no}',
            'body' => "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #3b82f6; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; }
                    .footer { text-align: center; padding: 20px; color: #64748b; font-size: 12px; }
                    .info-table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                    .info-table td { padding: 8px; border-bottom: 1px solid #e2e8f0; }
                    .info-table .label { font-weight: bold; width: 30%; color: #475569; }
                    .btn { display: inline-block; padding: 10px 20px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px; margin: 10px 0; }
                    .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>" . APP_NAME . "</h1>
                        <h2>PROJECT ORDER NOTIFICATION</h2>
                    </div>
                    
                    <div class='content'>
                        <h3>📋 PON Baru Telah Dibuat</h3>
                        <p>Sebuah Project Order Notification (PON) baru telah berhasil dibuat dalam sistem.</p>
                        
                        <table class='info-table'>
                            <tr><td class='label'>Job Number</td><td><strong>{job_no}</strong></td></tr>
                            <tr><td class='label'>PON Code</td><td>{pon}</td></tr>
                            <tr><td class='label'>Nama Project</td><td>{nama_proyek}</td></tr>
                            <tr><td class='label'>Client</td><td>{client}</td></tr>
                            <tr><td class='label'>Project Manager</td><td>{project_manager}</td></tr>
                            <tr><td class='label'>Material Type</td><td>{material_type}</td></tr>
                            <tr><td class='label'>Quantity</td><td>{qty} units</td></tr>
                            <tr><td class='label'>Status</td><td><span class='status-badge' style='background: {status_color}; color: white;'>{status}</span></td></tr>
                            <tr><td class='label'>Tanggal PON</td><td>{date_pon}</td></tr>
                            <tr><td class='label'>Project Start</td><td>{project_start}</td></tr>
                        </table>
                        
                        <p><strong>Subject:</strong><br>{subject}</p>
                        
                        <p style='margin-top: 20px;'>
                            <a href='{system_url}' class='btn'>📊 Lihat Detail di System</a>
                        </p>
                    </div>
                    
                    <div class='footer'>
                        <p>✉️ Email ini dikirim otomatis dari " . APP_NAME . " System</p>
                        <p>© " . date('Y') . " " . APP_NAME . " - All rights reserved</p>
                    </div>
                </div>
            </body>
            </html>
            "
        ]
    ];

    return $templates[$template] ?? null;
}
?>