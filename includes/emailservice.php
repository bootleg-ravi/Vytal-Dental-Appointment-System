<?php
require_once __DIR__ . '/../config/email_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

class EmailService {

    private $mailer;

    public function __construct() {
        $this->mailer = new PHPMailer(true);
        $this->setupSMTP();
    }

    private function setupSMTP() {
        try {
            $this->mailer->isSMTP();
            $this->mailer->Host       = SMTP_HOST;
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = SMTP_USERNAME;
            $this->mailer->Password   = SMTP_PASSWORD;
            $this->mailer->SMTPSecure = SMTP_ENCRYPTION;
            $this->mailer->Port       = SMTP_PORT;
            $this->mailer->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $this->mailer->isHTML(true);
            $this->mailer->CharSet = 'UTF-8';
        } catch (Exception $e) {
            error_log("EmailService Setup Error: " . $e->getMessage());
        }
    }


    private function send(string $to_email, string $to_name, string $subject, string $body): bool {
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to_email, $to_name);
            $this->mailer->Subject = $subject;
            $this->mailer->Body    = $body;
            $this->mailer->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>', '</div>'], "\n", $body));
            return $this->mailer->send();
        } catch (Exception $e) {
            error_log("EmailService Send Error: " . $this->mailer->ErrorInfo);
            return false;
        }
    }


    public function sendAppointmentConfirmation(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '🦷 Appointment Request Received – Vytal Dental Clinic',
            $this->tplBase('Booking Request Received', '#0d9488',
                $this->iconCircle('📅'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>We've received your appointment request. Our clinic staff will review it shortly and send you a confirmation.</p>",
                $this->detailsTable($d),
                $this->alertBox('⏳ Status: Pending', 'Your appointment is currently <strong>pending confirmation</strong>. You will receive another email once it is approved.', '#fef3c7', '#d97706'),
                $this->ctaButton('View Appointment', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendAppointmentApproved(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '✅ Appointment Confirmed – Vytal Dental Clinic',
            $this->tplBase('Appointment Confirmed!', '#0d9488',
                $this->iconCircle('✅'),
                "<p>Great news, <strong>" . e($to_name) . "</strong>!</p>
                <p>Your dental appointment has been <strong>confirmed</strong>. We look forward to seeing you.</p>",
                $this->detailsTable($d),
                $this->alertBox('📋 Before Your Visit', '
                    <ul style="margin:6px 0 0 18px;padding:0;line-height:1.9">
                        <li>Please arrive <strong>10–15 minutes</strong> before your scheduled time.</li>
                        <li>Bring a valid ID and any previous dental records if available.</li>
                        <li>Inform us of any allergies or current medications.</li>
                    </ul>', '#f0fdf4', '#059669'),
                $this->ctaButton('View Appointment Details', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendAppointmentCancelled(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '❌ Appointment Cancelled – Vytal Dental Clinic',
            $this->tplBase('Appointment Cancelled', '#ef4444',
                $this->iconCircle('❌'),
                "<p>Dear <strong>" . e($to_name) . "</strong>,</p>
                <p>We're sorry to inform you that your dental appointment has been <strong>cancelled</strong>.</p>"
                . (isset($d['cancel_reason']) ? "<p><strong>Reason:</strong> " . e($d['cancel_reason']) . "</p>" : ''),
                $this->detailsTable($d),
                $this->alertBox('📞 Need to Rebook?', 'You can book a new appointment anytime through our patient portal or by calling the clinic.', '#fef2f2', '#ef4444'),
                $this->ctaButton('Book a New Appointment', $d['book_url'] ?? '#')
            )
        );
    }

    public function sendAppointmentCompleted(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '🦷 Visit Complete – Thank You! – Vytal Dental Clinic',
            $this->tplBase('Visit Complete – Thank You!', '#0d9488',
                $this->iconCircle('🦷'),
                "<p>Dear <strong>" . e($to_name) . "</strong>,</p>
                <p>Thank you for visiting <strong>Vytal Dental Clinic</strong>! We hope your appointment went smoothly.</p>",
                $this->detailsTable($d),
                $this->alertBox('🔔 Follow-Up Reminder', 'Regular dental check-ups are recommended every <strong>6 months</strong>. Don\'t forget to schedule your next visit!', '#f0fdf4', '#059669'),
                $this->ctaButton('Book Follow-Up', $d['book_url'] ?? '#')
            )
        );
    }

    public function sendAppointmentReminder24h(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '🔔 Reminder: Your Dental Appointment is Tomorrow – Vytal Dental',
            $this->tplBase('Appointment Reminder', '#0d9488',
                $this->iconCircle('🔔'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>This is a friendly reminder that you have a dental appointment <strong>tomorrow</strong>.</p>",
                $this->detailsTable($d),
                $this->alertBox('📋 Quick Reminders', '
                    <ul style="margin:6px 0 0 18px;padding:0;line-height:1.9">
                        <li>Arrive <strong>10–15 minutes early</strong> for paperwork.</li>
                        <li>If you need to cancel, please do so at least <strong>2 hours in advance</strong>.</li>
                        <li>Brush and floss before your visit.</li>
                    </ul>', '#fffbeb', '#d97706'),
                $this->ctaButton('View Appointment', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendAppointmentReminder1h(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '⏰ Your Appointment is in 1 Hour – Vytal Dental',
            $this->tplBase('See You Soon!', '#0d9488',
                $this->iconCircle('⏰'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>Your dental appointment is in approximately <strong>1 hour</strong>. Please start heading to the clinic!</p>",
                $this->detailsTable($d),
                $this->ctaButton('View Appointment', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendFollowUpReminder(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '🦷 Time for Your 6-Month Check-Up! – Vytal Dental',
            $this->tplBase("It's Time for Your Check-Up!", '#0d9488',
                $this->iconCircle('🦷'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>It has been about <strong>6 months</strong> since your last dental visit. Regular check-ups help keep your smile healthy!</p>",
                $this->alertBox('Why Regular Visits Matter', '
                    <ul style="margin:6px 0 0 18px;padding:0;line-height:1.9">
                        <li>Early detection of cavities and gum disease</li>
                        <li>Professional cleaning removes tartar buildup</li>
                        <li>Prevents costly dental procedures later</li>
                    </ul>', '#f0fdf4', '#059669'),
                $this->ctaButton('Book Your Check-Up Now', $d['book_url'] ?? '#')
            )
        );
    }

    public function sendRescheduleRequestToAdmin(string $admin_email, string $patient_name, array $d): bool {
        return $this->send($admin_email, 'Clinic Admin',
            '📅 Reschedule Request from ' . $patient_name . ' – Vytal Dental',
            $this->tplBase('Reschedule Request', '#7c3aed',
                $this->iconCircle('🔄'),
                "<p>A patient has submitted a <strong>reschedule request</strong> that requires your review.</p>
                <p><strong>Patient:</strong> " . e($patient_name) . "</p>",
                $this->twoColTable([
                    ['Current Date',    $d['current_date'] ?? '—'],
                    ['Current Time',    $d['current_time'] ?? '—'],
                    ['Requested Date',  $d['new_date']     ?? '—'],
                    ['Requested Time',  $d['new_time']     ?? '—'],
                    ['Reason',          $d['reason']       ?? 'None provided'],
                ]),
                $this->ctaButton('Review in Admin Panel', $d['admin_url'] ?? '#', '#7c3aed')
            )
        );
    }

    public function sendRescheduleApproved(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '✅ Reschedule Approved – Vytal Dental Clinic',
            $this->tplBase('Reschedule Approved!', '#0d9488',
                $this->iconCircle('✅'),
                "<p>Dear <strong>" . e($to_name) . "</strong>,</p>
                <p>Your reschedule request has been <strong>approved</strong>. Here are your updated appointment details:</p>",
                $this->detailsTable($d),
                $this->ctaButton('View Updated Appointment', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendRescheduleDenied(string $to_email, string $to_name, array $d): bool {
        return $this->send($to_email, $to_name,
            '❌ Reschedule Request Denied – Vytal Dental Clinic',
            $this->tplBase('Reschedule Request Denied', '#ef4444',
                $this->iconCircle('❌'),
                "<p>Dear <strong>" . e($to_name) . "</strong>,</p>
                <p>We regret to inform you that your reschedule request could not be accommodated at this time.</p>
                <p>Your <strong>original appointment</strong> remains scheduled:</p>",
                $this->detailsTable($d),
                $this->alertBox('Need Help?', 'If you need to make other arrangements, please contact the clinic directly or cancel and rebook.', '#fef2f2', '#ef4444'),
                $this->ctaButton('View Appointment', $d['summary_url'] ?? '#')
            )
        );
    }

    public function sendPasswordReset(string $to_email, string $to_name, string $reset_link): bool {
        return $this->send($to_email, $to_name,
            '🔐 Password Reset – Vytal Dental Clinic',
            $this->tplBase('Reset Your Password', '#0d9488',
                $this->iconCircle('🔐'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>We received a request to reset your Vytal Dental account password. Click the button below to create a new password.</p>",
                $this->alertBox('⚠️ Security Notice', 'This link expires in <strong>1 hour</strong>. If you did not request a password reset, please ignore this email — your account is safe.', '#fef3c7', '#d97706'),
                $this->ctaButton('Reset My Password', $reset_link),
                "<p style='margin-top:18px;font-size:.8rem;color:#94a3b8;word-break:break-all'>Or copy this link: <a href='" . e($reset_link) . "' style='color:#0d9488'>" . e($reset_link) . "</a></p>"
            )
        );
    }

    public function sendGuestBookingConfirmation(string $to_email, string $to_name, array $d, string $token, string $appt_id): bool {
        $summary_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . '/patient/appointment_summary.php?id=' . $appt_id . '&token=' . $token;

        $d['summary_url'] = $summary_url;

        return $this->send($to_email, $to_name,
            '🦷 Booking Request Received – Vytal Dental Clinic',
            $this->tplBase('Booking Request Received', '#0d9488',
                $this->iconCircle('📋'),
                "<p>Hi <strong>" . e($to_name) . "</strong>,</p>
                <p>Thank you for booking with Vytal Dental Clinic! Your appointment request has been received and is pending confirmation.</p>
                <p><strong>Your booking reference:</strong> <span style='font-family:monospace;background:#f0fdf4;padding:2px 8px;border-radius:4px;color:#0d9488'>#" . str_pad($appt_id, 5, '0', STR_PAD_LEFT) . "</span></p>",
                $this->detailsTable($d),
                $this->alertBox('💡 Tip', 'Save this email — it contains a link to track your appointment status even without an account.', '#f0fdf4', '#0d9488'),
                $this->ctaButton('Track My Appointment', $summary_url)
            )
        );
    }


    private function tplBase(string $title, string $accent, ...$sections): string {
        $year    = date('Y');
        $content = implode("\n", $sections);
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1eb;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;color:#1a1a2e;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1eb;padding:32px 0">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">

      <tr>
        <td style="background:{$accent};border-radius:16px 16px 0 0;padding:32px 36px;text-align:center">
          <p style="margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.7);font-weight:600">Vytal Dental Clinic</p>
          <h1 style="margin:0;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:-.02em">{$title}</h1>
        </td>
      </tr>

      <tr>
        <td style="background:#ffffff;padding:36px;border-radius:0 0 16px 16px;border:1px solid #e5e0d5;border-top:none">
          {$content}

          <hr style="border:none;border-top:1px solid #e5e0d5;margin:32px 0 20px"/>
          <p style="margin:0;font-size:12px;color:#94a3b8;text-align:center;line-height:1.7">
            © {$year} Vytal Dental Clinic &nbsp;·&nbsp; This email was sent because you have an appointment with us.<br>
            If you believe this was sent in error, please disregard this email.
          </p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
    }

    private function iconCircle(string $emoji): string {
        return "<div style='text-align:center;margin-bottom:24px'>
            <span style='display:inline-block;font-size:40px;line-height:1'>{$emoji}</span>
        </div>";
    }

    private function detailsTable(array $d): string {
        $map = [
            'date'         => ['📅', 'Date'],
            'time'         => ['🕐', 'Time'],
            'doctor'       => ['👨‍⚕️', 'Dentist'],
            'service'      => ['🦷', 'Service'],
            'price'        => ['💰', 'Fee'],
            'duration'     => ['⏱', 'Duration'],
            'new_date'     => ['📅', 'New Date'],
            'new_time'     => ['🕐', 'New Time'],
        ];
        $rows = '';
        foreach ($map as $key => [$icon, $label]) {
            if (empty($d[$key])) continue;
            $rows .= "
            <tr>
                <td style='padding:11px 14px;font-size:13px;color:#6b7280;font-weight:600;white-space:nowrap;border-bottom:1px solid #f0ece4'>
                    {$icon} {$label}
                </td>
                <td style='padding:11px 14px;font-size:13px;color:#1a1a2e;font-weight:700;border-bottom:1px solid #f0ece4'>
                    " . e($d[$key]) . "
                </td>
            </tr>";
        }
        if (!$rows) return '';
        return "<table width='100%' cellpadding='0' cellspacing='0'
                    style='border:1px solid #e5e0d5;border-radius:12px;overflow:hidden;margin:20px 0;background:#fdfaf5'>
                    <tbody>{$rows}</tbody>
                </table>";
    }

    private function twoColTable(array $rows): string {
        $html = "<table width='100%' cellpadding='0' cellspacing='0'
                    style='border:1px solid #e5e0d5;border-radius:12px;overflow:hidden;margin:20px 0;background:#fdfaf5'>
                    <tbody>";
        foreach ($rows as [$label, $value]) {
            $html .= "<tr>
                <td style='padding:11px 14px;font-size:13px;color:#6b7280;font-weight:600;border-bottom:1px solid #f0ece4;white-space:nowrap'>" . e($label) . "</td>
                <td style='padding:11px 14px;font-size:13px;color:#1a1a2e;font-weight:700;border-bottom:1px solid #f0ece4'>" . e($value) . "</td>
            </tr>";
        }
        $html .= "</tbody></table>";
        return $html;
    }

    private function alertBox(string $heading, string $body, string $bg, string $color): string {
        return "<div style='background:{$bg};border-left:4px solid {$color};border-radius:8px;padding:14px 18px;margin:20px 0'>
            <p style='margin:0 0 6px;font-size:13px;font-weight:700;color:{$color}'>{$heading}</p>
            <div style='font-size:13px;color:#374151;line-height:1.6'>{$body}</div>
        </div>";
    }

    private function ctaButton(string $label, string $url, string $color = '#0d9488'): string {
        return "<div style='text-align:center;margin:28px 0 8px'>
            <a href='" . e($url) . "'
               style='display:inline-block;background:{$color};color:#ffffff;text-decoration:none;
                      padding:14px 32px;border-radius:30px;font-size:14px;font-weight:700;
                      letter-spacing:.01em'>
                {$label} →
            </a>
        </div>";
    }
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>