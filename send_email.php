<?php
// File: email_sender.php

require_once 'email_config.php';

// Include PHPMailer
require_once 'vendor/autoload.php';

function sendEmailNotification($to, $template, $data = [])
{
    try {
        $templateConfig = getEmailTemplate($template, $data);
        if (!$templateConfig) {
            throw new Exception("Email template not found: " . $template);
        }

        // Replace placeholders
        $subject = replacePlaceholders($templateConfig['subject'], $data);
        $body = replacePlaceholders($templateConfig['body'], $data);

        // Create PHPMailer instance
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = SMTP_AUTH;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;

        // Enable verbose debug output
        $mail->SMTPDebug = 2; // Enable verbose debug output
        $mail->Debugoutput = function ($str, $level) {
            error_log("PHPMailer Debug: $str");
        };

        // Recipients
        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); // Plain text version

        $mail->send();
        error_log("✅ Email sent successfully to: " . $to);
        return true;
    } catch (Exception $e) {
        error_log("❌ Email sending error: " . $e->getMessage());
        error_log("🔧 PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function replacePlaceholders($text, $data)
{
    foreach ($data as $key => $value) {
        $text = str_replace('{' . $key . '}', $value, $text);
    }
    return $text;
}

function getStatusColor($status)
{
    $status = strtolower($status);
    switch ($status) {
        case 'selesai':
            return '#10b981';
        case 'progress':
            return '#3b82f6';
        case 'pending':
            return '#f59e0b';
        case 'delayed':
            return '#ef4444';
        default:
            return '#6b7280';
    }
}

function sendPONNotification($ponData, $action = 'created')
{
    try {
        // Data untuk email template
        $emailData = [
            'job_no' => $ponData['job_no'] ?? '',
            'pon' => $ponData['pon'] ?? '',
            'nama_proyek' => $ponData['nama_proyek'] ?? '',
            'client' => $ponData['client'] ?? '',
            'project_manager' => $ponData['project_manager'] ?? '',
            'material_type' => $ponData['material_type'] ?? '',
            'qty' => $ponData['qty'] ?? 0,
            'status' => $ponData['status'] ?? 'Progress',
            'status_color' => getStatusColor($ponData['status'] ?? 'Progress'),
            'date_pon' => formatDateForEmail($ponData['date_pon'] ?? ''),
            'project_start' => formatDateForEmail($ponData['project_start'] ?? ''),
            'subject' => $ponData['subject'] ?? '',
            'system_url' => getSystemBaseUrl() . 'pon_view.php?job_no=' . urlencode($ponData['job_no'] ?? '')
        ];

        // Kirim email ke admin
        $result = sendEmailNotification(EMAIL_ADMIN, 'pon_created', $emailData);

        return $result;
    } catch (Exception $e) {
        error_log("PON Notification error: " . $e->getMessage());
        return false;
    }
}

function formatDateForEmail($date)
{
    if (!$date || $date == '0000-00-00') return '-';
    return date('d F Y', strtotime($date));
}

function getSystemBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);

    return $protocol . '://' . $host . $path . '/';
}
?>