<?php
// Server configuration comes exclusively from the PHP process environment.
// See WHATSAPP-SETUP.md. Never return credentials or API responses to a browser.
function notify_whatsapp(array $values): bool
{
    try {
        $token = trim((string) getenv('WHATSAPP_ACCESS_TOKEN'));
        $phoneId = trim((string) getenv('WHATSAPP_PHONE_NUMBER_ID'));
        $version = trim((string) getenv('WHATSAPP_GRAPH_VERSION'));
        $recipient = trim((string) getenv('WHATSAPP_RECIPIENT'));
        $recipient = preg_replace('/\D/', '', $recipient);
        $recipient = preg_replace('/^00/', '', $recipient);
        $template = getenv('WHATSAPP_TEMPLATE_NAME') ?: 'neue_dj_anfrage';
        $language = getenv('WHATSAPP_TEMPLATE_LANGUAGE') ?: 'de';

        if ($token === '' || !preg_match('/^\d+$/', $phoneId)
            || !preg_match('/^v\d+\.\d+$/', $version)
            || !preg_match('/^[1-9]\d{6,14}$/', $recipient)) {
            error_log('[whatsapp] configuration_missing_or_invalid');
            return false;
        }
        if (!function_exists('curl_init')) {
            error_log('[whatsapp] curl_extension_missing');
            return false;
        }

        $parameters = [];
        foreach ($values as $value) {
            // Template parameters must be single-line; retain Unicode characters.
            $text = preg_replace('/\s+/u', ' ', trim((string) $value));
            if ($text === null) {
                error_log('[whatsapp] invalid_utf8');
                return false;
            }
            $parameters[] = ['type' => 'text', 'text' => $text !== '' ? $text : 'Keine Angabe'];
        }
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => [['type' => 'body', 'parameters' => $parameters]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $curl = curl_init('https://graph.facebook.com/' . $version . '/' . $phoneId . '/messages');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        $result = is_string($response) ? json_decode($response, true) : null;
        if ($response === false || $status < 200 || $status >= 300 || empty($result['messages'][0]['id'])) {
            // Numeric diagnostics only: no token, customer details or raw API body.
            $code = (int) ($result['error']['code'] ?? 0);
            $subcode = (int) ($result['error']['error_subcode'] ?? 0);
            error_log(sprintf('[whatsapp] failed http=%d curl=%d meta=%d subcode=%d', $status, $errno, $code, $subcode));
            return false;
        }
        error_log('[whatsapp] accepted_by_meta');
        return true;
    } catch (Throwable $error) {
        error_log('[whatsapp] unexpected_send_failure');
        return false;
    }
}
