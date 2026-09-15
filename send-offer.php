<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen sind erlaubt.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!is_array($data)) {
    $data = $_POST;
}

function get_value(array $data, string $key, string $default = ''): string
{
    if (!isset($data[$key])) {
        return $default;
    }

    if (is_array($data[$key])) {
        return implode(', ', array_map('trim', $data[$key]));
    }

    return trim((string) $data[$key]);
}

function sanitize(string $value): string
{
    return trim(strip_tags($value));
}

function send_mail(string $to, string $subject, string $body, array $headers): bool
{
    return mail($to, $subject, $body, implode("\r\n", $headers));
}

function build_customer_confirmation_html(): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hvala na vašem upitu | Thank you for your inquiry</title>
</head>
<body style="margin:0;padding:0;background-color:#f7f1e6;font-family:Arial,Helvetica,sans-serif;color:#111111;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f7f1e6;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;background-color:#ffffff;border:1px solid #d8b15b;border-radius:18px;overflow:hidden;">
                    <tr>
                        <td style="padding:26px 28px 12px;background:linear-gradient(135deg,#f6e7bd,#ffffff 60%);border-bottom:1px solid #e4c77b;">
                            <div style="font-size:12px;letter-spacing:2px;color:#8c6a1f;font-weight:bold;text-transform:uppercase;">MarryDJ</div>
                            <div style="font-size:26px;line-height:1.3;color:#1f1f1f;font-weight:bold;margin-top:8px;">Hvala na vašem upitu | Thank you for your inquiry</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 28px 8px;">
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Poštovani,<br>Dear Sir or Madam,</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Hvala vam što ste kontaktirali MarryDJ.<br>Thank you for contacting MarryDJ.</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Vaš upit je uspješno zaprimljen i iskreno vam zahvaljujemo na ukazanom povjerenju.<br>Your inquiry has been successfully received, and we sincerely appreciate your trust.</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Svako vjenčanje i svaki događaj za nas su jedinstveni. Posvećeni smo stvaranju elegantne atmosfere i nezaboravnih trenutaka koji će ostati u trajnom sjećanju.<br>Every wedding and every event is unique to us. We are dedicated to creating an elegant atmosphere and unforgettable moments that will be remembered for a lifetime.</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Vaš ćemo upit pažljivo pregledati i javiti vam se u najkraćem mogućem roku, najčešće unutar 24 sata.<br>We will carefully review your inquiry and respond as soon as possible, usually within 24 hours.</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Za hitne upite ili dodatne informacije slobodno nas kontaktirajte.<br>For urgent inquiries or additional information, please feel free to contact us.</p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;"><strong>info@marrydj.com</strong><br><strong>www.marrydj.com</strong></p>
                            <p style="margin:0 0 12px;color:#1f1f1f;font-size:16px;line-height:1.7;">Radujemo se prilici da budemo dio vašeg posebnog dana i zajedno stvorimo uspomene koje traju cijeli život.<br>We look forward to being part of your special day and creating memories that will last a lifetime.</p>
                            <p style="margin:0 0 2px;color:#1f1f1f;font-size:16px;line-height:1.7;">Srdačan pozdrav,<br>Kind regards,</p>
                            <p style="margin:0 0 2px;color:#1f1f1f;font-size:16px;line-height:1.7;"><strong>Manuel Kegelj</strong></p>
                            <p style="margin:0 0 8px;color:#1f1f1f;font-size:16px;line-height:1.7;"><strong>MarryDJ</strong><br>Premium Wedding &amp; Event DJ</p>
                            <p style="margin:0;color:#1f1f1f;font-size:16px;line-height:1.7;"><strong>info@marrydj.com</strong><br><strong>www.marrydj.com</strong></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}

$firstname = sanitize(get_value($data, 'firstname'));
$lastname = sanitize(get_value($data, 'lastname'));
$email = filter_var(get_value($data, 'email'), FILTER_SANITIZE_EMAIL);
$phone = sanitize(get_value($data, 'phone'));
$eventType = sanitize(get_value($data, 'event_type'));
$eventDate = sanitize(get_value($data, 'event_date'));
$startTime = sanitize(get_value($data, 'start_time'));
$endTime = sanitize(get_value($data, 'end_time'));
$location = sanitize(get_value($data, 'location'));
$country = sanitize(get_value($data, 'country'));
$guests = sanitize(get_value($data, 'guests'));
$musicStyles = get_value($data, 'music[]');
$excludeMusic = sanitize(get_value($data, 'no_music'));
$services = get_value($data, 'services[]');
$message = sanitize(get_value($data, 'message'));
$source = sanitize(get_value($data, 'source'));

// Validate submitted values server-side as browser checks can be bypassed.
$phoneDigits = preg_replace('/\D/', '', $phone);
$validPhone = preg_match('/^[+\d\s().\/\-]+$/', $phone)
    && strlen($phoneDigits) >= 7 && strlen($phoneDigits) <= 15;
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
$validDate = $parsedDate && $parsedDate->format('Y-m-d') === $eventDate;
$validTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $startTime);
// Validate the clock value independently: the celebration may end after midnight.
$validEndTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $endTime);
$validGuests = filter_var($guests, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
$privacyAccepted = get_value($data, 'privacy') === 'on';

if (!$firstname || !$lastname || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$eventType || !$validDate || !$validTime || !$validEndTime || !$location || !$country || !$validGuests || !$validPhone || !$privacyAccepted) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Bitte fülle alle Pflichtfelder korrekt aus.']);
    exit;
}

$fullName = trim($firstname . ' ' . $lastname);
$adminSubject = sprintf('Event-Anfrage von %s%s', $fullName, $eventType ? ' – ' . $eventType : '');
$customerSubject = 'Hvala na vašem upitu | Thank you for your inquiry';

$adminBody = [];
$adminBody[] = 'Name: ' . $fullName;
$adminBody[] = 'E-Mail: ' . $email;
$adminBody[] = 'Telefon: ' . ($phone ?: 'Nicht angegeben');
$adminBody[] = 'Event: ' . ($eventType ?: 'Nicht angegeben');
$adminBody[] = 'Datum: ' . ($eventDate ?: 'Nicht angegeben');
$adminBody[] = 'Početak / Start: ' . $startTime;
$adminBody[] = 'Završetak / Ende: ' . $endTime;
$adminBody[] = 'Ort: ' . ($location ?: 'Nicht angegeben');
$adminBody[] = 'Land: ' . $country;
$adminBody[] = 'Gäste: ' . ($guests ?: 'Nicht angegeben');
$adminBody[] = '';
$adminBody[] = 'Musik: ' . ($musicStyles ?: 'Keine Angabe');
$adminBody[] = 'Musik nicht gewünscht: ' . ($excludeMusic ?: 'Keine Angabe');
$adminBody[] = 'Leistungen: ' . ($services ?: 'Keine Angabe');
$adminBody[] = 'Wie gefunden: ' . ($source ?: 'Keine Angabe');
$adminBody[] = '';
$adminBody[] = 'Weitere Informationen:';
$adminBody[] = $message ?: 'Keine weiteren Informationen.';

$recipient = 'info@marrydj.com';
$adminHeaders = [];
$adminHeaders[] = 'From: DJ KEGGY Website <info@marrydj.com>';
$adminHeaders[] = 'Reply-To: ' . $email;
$adminHeaders[] = 'MIME-Version: 1.0';
$adminHeaders[] = 'Content-Type: text/plain; charset=UTF-8';

if (!send_mail($recipient, $adminSubject, implode("\n", $adminBody), $adminHeaders)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Die Anfrage konnte nicht gesendet werden. Bitte versuche es später erneut.']);
    exit;
}

// The inquiry email has been accepted by mail(). WhatsApp is best-effort and
// must never change the email result or expose credentials to the customer.
try {
    require_once __DIR__ . '/whatsapp-notify.php';
    notify_whatsapp([
        $fullName, $eventType, $eventDate, $startTime, $location, $country,
        $guests, $phone, $email, $musicStyles, $services, $message,
    ]);
} catch (Throwable $error) {
    error_log('[whatsapp] notification_unavailable');
}

$confirmationHtml = build_customer_confirmation_html();
$customerHeaders = [];
$customerHeaders[] = 'From: MarryDJ <info@marrydj.com>';
$customerHeaders[] = 'Reply-To: info@marrydj.com';
$customerHeaders[] = 'MIME-Version: 1.0';
$customerHeaders[] = 'Content-Type: text/html; charset=UTF-8';

if (!send_mail($email, $customerSubject, $confirmationHtml, $customerHeaders)) {
    // The inquiry is already received; do not invite duplicate submissions.
    error_log('[offer] customer_confirmation_failed');
}

// mail() confirms acceptance by the mail server, not delivery to the inbox.
echo json_encode(['success' => true, 'email_sent' => true, 'message' => 'Deine Anfrage wurde erfolgreich gesendet. Ich melde mich bald bei dir.']);
