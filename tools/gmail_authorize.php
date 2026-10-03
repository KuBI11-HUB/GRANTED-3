<?php
$requiredRole = 'admin';
require_once __DIR__ . '/../includes/auth_check.php';

if (!class_exists('Google\Client')) {
    http_response_code(503);
    exit('Google API Client Library is not installed. Run Composer install first.');
}

$client = new Google\Client();
$client->setApplicationName('GRANTED Notifications');
$client->setAuthConfig(GMAIL_CREDENTIALS_PATH);
$client->setRedirectUri(GMAIL_REDIRECT_URI);
$client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
$client->setAccessType('offline');
$client->setPrompt('consent');

if (isset($_GET['code'])) {
    $expectedState = $_SESSION['gmail_oauth_state'] ?? '';
    unset($_SESSION['gmail_oauth_state']);
    if ($expectedState === '' || !hash_equals($expectedState, (string)($_GET['state'] ?? ''))) {
        http_response_code(400);
        exit('Security check failed. Return to localhost, sign in again, and restart authorization.');
    }

    try {
        $token = $client->fetchAccessTokenWithAuthCode((string)$_GET['code']);
        if (isset($token['error'])) {
            throw new RuntimeException((string)$token['error']);
        }
        if (empty($token['refresh_token'])) {
            throw new RuntimeException('Google did not return a refresh token. Remove the existing GRANTED authorization from Google Account permissions and retry.');
        }
        $written = file_put_contents(
            GMAIL_TOKEN_PATH,
            json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        if ($written === false) {
            throw new RuntimeException('Could not save the Gmail token file.');
        }
        @chmod(GMAIL_TOKEN_PATH, 0600);
        $message = 'Gmail is connected. The refresh token is stored locally.';
    } catch (Throwable $e) {
        http_response_code(400);
        $message = 'Authorization failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
} else {
    $state = bin2hex(random_bytes(32));
    $_SESSION['gmail_oauth_state'] = $state;
    $client->setState($state);
    header('Location: ' . $client->createAuthUrl());
    exit;
}
?>
<!doctype html>
<html lang="en"><meta charset="utf-8"><title>GRANTED Gmail authorization</title>
<body><h1><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></h1></body></html>
