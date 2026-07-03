<?php
// includes/mailer.php — Email sender using PHPMailer
// Install PHPMailer via: composer require phpmailer/phpmailer
// OR download from: https://github.com/PHPMailer/PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer — try Composer autoload first, then manual
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../phpmailer/src/PHPMailer.php')) {
    require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../phpmailer/src/Exception.php';
} else {
    // PHPMailer not installed — log and return false silently
    error_log('PHPMailer not found. Install it to enable email sending.');
    return false;
}

/**
 * Send an email using SMTP settings from the database.
 *
 * Usage:
 *   sendMail('to@email.com', 'Subject', '<p>HTML body</p>');
 *
 * @param string $toEmail    Recipient email
 * @param string $toName     Recipient name (optional)
 * @param string $subject    Email subject
 * @param string $htmlBody   HTML body content
 * @param string $plainText  Plain text fallback (auto-generated if empty)
 * @return bool|string       true on success, error message string on failure
 */
function sendMail(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $toName   = '',
    string $plainText = ''
): bool|string {

    // Load SMTP settings from DB
    $smtpRows = DB::fetchAll("SELECT setting_key, setting_val FROM settings
                              WHERE setting_key IN (
                                'smtp_host','smtp_user','smtp_pass','smtp_port',
                                'smtp_encryption','smtp_from_name','company_name'
                              )");
    $cfg = [];
    foreach ($smtpRows as $r) {
        $cfg[$r['setting_key']] = $r['setting_val'];
    }

    // Validate required settings
    if (empty($cfg['smtp_host']) || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])) {
        error_log('SendMail: SMTP not configured. Host/user/pass missing.');
        return 'SMTP not configured. Please update Settings.';
    }


    $mail = new PHPMailer(true);

    try {
        // ── Server settings ─────────────────────────────────────
        $mail->isSMTP();
        $mail->Host        = $cfg['smtp_host'];
        $mail->SMTPAuth    = true;
        $mail->Username    = $cfg['smtp_user'];
        $mail->Password    = $cfg['smtp_pass'];

        // smtp_encryption in DB: 'tls' (port 587, STARTTLS) or 'ssl' (port 465)
        $enc = strtolower($cfg['smtp_encryption'] ?? 'tls');
        if ($enc === 'ssl') {
            $mail->SMTPSecure = 'ssl';
            $defaultPort = 465;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $defaultPort = 587;
        }
        $mail->Port    = (int)($cfg['smtp_port'] ?? $defaultPort);
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30; // seconds

        // ── Log SMTP traffic to PHP error_log (helps diagnose failures) ─
        $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = 'error_log';

        // ── SSL certificate tolerance ────────────────────────────
        // Required on many localhost (XAMPP/WAMP) and shared-hosting setups
        // where the server can't verify Gmail's SSL cert chain.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // ── From ────────────────────────────────────────────────
        $fromName  = $cfg['smtp_from_name'] ?? ($cfg['company_name'] ?? 'LeadPro LMS');
        $fromEmail = $cfg['smtp_user'];    // from = logged-in SMTP user
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);

        // ── Recipient ────────────────────────────────────────────
        $mail->addAddress($toEmail, $toName ?: $toEmail);

        // ── Content ──────────────────────────────────────────────
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = wrapEmailTemplate($htmlBody, $subject, $fromName);
        $mail->AltBody = $plainText ?: strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (Exception $e) {
        $errDetail = $mail->ErrorInfo;
        error_log('SendMail FAILED — Subject: "' . $subject . '" To: ' . $toEmail . ' — Error: ' . $errDetail);
        return 'Mail error: ' . $errDetail;
    }
}

/**
 * Wrap email body in a clean HTML template
 */
function wrapEmailTemplate(string $body, string $subject, string $companyName): string
{
    return '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family:Arial,sans-serif; }
  .wrap { max-width:600px; margin:30px auto; background:#fff;
          border-radius:12px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.08); }
  .header { background:#2563eb; color:#fff; padding:28px 32px; }
  .header h1 { margin:0; font-size:1.2rem; font-weight:700; }
  .body { padding:28px 32px; color:#1e293b; font-size:.92rem; line-height:1.6; }
  .footer { background:#f8fafc; padding:18px 32px; text-align:center;
            color:#94a3b8; font-size:.78rem; border-top:1px solid #e2e8f0; }
  a { color:#2563eb; }
  .btn { display:inline-block; background:#2563eb; color:#fff !important;
         padding:10px 22px; border-radius:8px; text-decoration:none;
         font-weight:600; margin:12px 0; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <h1>&#9679; ' . htmlspecialchars($companyName) . '</h1>
      <div style="opacity:.8;font-size:.85rem;margin-top:4px;">' . htmlspecialchars($subject) . '</div>
    </div>
    <div class="body">' . $body . '</div>
    <div class="footer">
      &copy; ' . date('Y') . ' ' . htmlspecialchars($companyName) . ' &bull; This is an automated message.
    </div>
  </div>
</body>
</html>';
}

/**
 * Send a "New Lead Assigned" email notification to an employee
 */
function notifyLeadAssigned(array $employee, array $lead): void
{
    $body = '
    <p>Hi <strong>' . htmlspecialchars($employee['name']) . '</strong>,</p>
    <p>A new lead has been assigned to you:</p>
    <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
      <tr><td style="padding:6px 0;color:#64748b;width:120px;">Name</td>
          <td style="padding:6px 0;font-weight:600;">' . htmlspecialchars($lead['name']) . '</td></tr>
      <tr><td style="padding:6px 0;color:#64748b;">Phone</td>
          <td style="padding:6px 0;">' . htmlspecialchars($lead['phone']) . '</td></tr>
      <tr><td style="padding:6px 0;color:#64748b;">Service</td>
          <td style="padding:6px 0;">' . htmlspecialchars($lead['service']) . '</td></tr>
      <tr><td style="padding:6px 0;color:#64748b;">Priority</td>
          <td style="padding:6px 0;font-weight:600;color:' . ($lead['priority']==='High'?'#dc2626':($lead['priority']==='Medium'?'#d97706':'#059669')) . ';">'
          . htmlspecialchars($lead['priority']) . '</td></tr>
    </table>
    <a href="' . APP_URL . '/employee/leads.php" class="btn">View My Leads</a>
    <p style="color:#94a3b8;font-size:.83rem;">Please follow up as soon as possible.</p>';

    sendMail($employee['email'], 'New Lead Assigned: ' . $lead['name'], $body, $employee['name']);
}

/**
 * Send a follow-up reminder email
 */
function sendFollowupReminder(array $employee, array $lead, string $followupDate): void
{
    $body = '
    <p>Hi <strong>' . htmlspecialchars($employee['name']) . '</strong>,</p>
    <p>This is a reminder that you have a follow-up scheduled for:</p>
    <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
      <tr><td style="padding:6px 0;color:#64748b;width:120px;">Lead</td>
          <td style="padding:6px 0;font-weight:600;">' . htmlspecialchars($lead['name']) . '</td></tr>
      <tr><td style="padding:6px 0;color:#64748b;">Phone</td>
          <td style="padding:6px 0;">' . htmlspecialchars($lead['phone']) . '</td></tr>
      <tr><td style="padding:6px 0;color:#64748b;">Follow-up Date</td>
          <td style="padding:6px 0;font-weight:600;color:#2563eb;">' . htmlspecialchars($followupDate) . '</td></tr>
    </table>
    <a href="' . APP_URL . '/employee/leads.php" class="btn">View Lead</a>';

    sendMail(
        $employee['email'],
        'Follow-up Reminder: ' . $lead['name'],
        $body,
        $employee['name']
    );
}