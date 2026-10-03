<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/app.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../includes/notifications.php';

$recipient = $argv[1] ?? '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php tools/gmail_test.php recipient@example.com\n");
    exit(2);
}
if (!MAIL_ENABLED) {
    fwrite(STDERR, "Mail is disabled. Set MAIL_ENABLED=true in config/app.local.php.\n");
    exit(1);
}
if (!class_exists('Google\Client') || !is_file(GMAIL_TOKEN_PATH) || !is_file(GMAIL_CREDENTIALS_PATH)) {
    fwrite(STDERR, "Google client, credentials, or token is missing. Complete Composer install and Gmail authorization first.\n");
    exit(1);
}

try {
    $client = new Google\Client();
    $client->setApplicationName('GRANTED Notifications');
    $client->setAuthConfig(GMAIL_CREDENTIALS_PATH);
    $client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
    $client->setAccessType('offline');
    $token = json_decode((string)file_get_contents(GMAIL_TOKEN_PATH), true, 512, JSON_THROW_ON_ERROR);
    $client->setAccessToken($token);
    if ($client->isAccessTokenExpired()) {
        $refresh = $client->getRefreshToken();
        if (!$refresh) {
            throw new RuntimeException('Gmail refresh token is missing; authorize again.');
        }
        $newToken = $client->fetchAccessTokenWithRefreshToken($refresh);
        if (isset($newToken['error'])) {
            throw new RuntimeException((string)$newToken['error']);
        }
        $newToken = array_merge($token, $newToken);
        if (file_put_contents(GMAIL_TOKEN_PATH, json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Could not save refreshed token.');
        }
        $client->setAccessToken($newToken);
    }

    $raw = 'To: ' . $recipient . "\r\n"
        . 'From: "' . str_replace(["\r", "\n", '"'], '', MAIL_FROM_NAME) . '" <' . MAIL_FROM_EMAIL . ">\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode('GRANTED Gmail test') . "?=\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
        . "This is a test email from GRANTED Notifications.\n";
    $message = new Google\Service\Gmail\Message();
    $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));
    $service = new Google\Service\Gmail($client);
    $sent = $service->users_messages->send('me', $message);
    echo 'Sent. Gmail message id: ' . $sent->getId() . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Gmail test failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
