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
    return trim(filter_var($value, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
}

$firstname = sanitize(get_value($data, 'firstname'));
$lastname = sanitize(get_value($data, 'lastname'));
$email = filter_var(get_value($data, 'email'), FILTER_SANITIZE_EMAIL);
$phone = sanitize(get_value($data, 'phone'));
$eventType = sanitize(get_value($data, 'event_type'));
$eventDate = sanitize(get_value($data, 'event_date'));
$startTime = sanitize(get_value($data, 'start_time'));
$location = sanitize(get_value($data, 'location'));
$guests = sanitize(get_value($data, 'guests'));
$musicStyles = get_value($data, 'music[]');
$excludeMusic = sanitize(get_value($data, 'no_music'));
$services = get_value($data, 'services[]');
$message = sanitize(get_value($data, 'message'));
$source = sanitize(get_value($data, 'source'));

if (!$firstname || !$lastname || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$eventType || !$eventDate || !$startTime || !$location || !$guests) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Bitte fülle alle Pflichtfelder korrekt aus.']);
    exit;
}

$fullName = trim($firstname . ' ' . $lastname);
$subject = sprintf('Event-Anfrage von %s%s', $fullName, $eventType ? ' – ' . $eventType : '');

$body = [];
$body[] = 'Name: ' . $fullName;
$body[] = 'E-Mail: ' . $email;
$body[] = 'Telefon: ' . ($phone ?: 'Nicht angegeben');
$body[] = 'Event: ' . ($eventType ?: 'Nicht angegeben');
$body[] = 'Datum: ' . ($eventDate ?: 'Nicht angegeben');
$body[] = 'Beginn: ' . ($startTime ?: 'Nicht angegeben');
$body[] = 'Ort: ' . ($location ?: 'Nicht angegeben');
$body[] = 'Gäste: ' . ($guests ?: 'Nicht angegeben');
$body[] = '';
$body[] = 'Musik: ' . ($musicStyles ?: 'Keine Angabe');
$body[] = 'Musik nicht gewünscht: ' . ($excludeMusic ?: 'Keine Angabe');
$body[] = 'Leistungen: ' . ($services ?: 'Keine Angabe');
$body[] = 'Wie gefunden: ' . ($source ?: 'Keine Angabe');
$body[] = '';
$body[] = 'Weitere Informationen:';
$body[] = $message ?: 'Keine weiteren Informationen.';

$recipient = 'info@marrydj.com';
$headers = [];
$headers[] = 'From: DJ KEGGY Website <no-reply@dj-keggy.com>';
$headers[] = 'Reply-To: ' . $email;
$headers[] = 'Content-Type: text/plain; charset=UTF-8';

if (!mail($recipient, $subject, implode("\n", $body), implode("\r\n", $headers))) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Die Anfrage konnte nicht gesendet werden. Bitte versuche es später erneut.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Deine Anfrage wurde erfolgreich gesendet. Ich melde mich bald bei dir.']);
