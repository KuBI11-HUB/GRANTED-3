<?php
/**
 * Phase 4 notification helpers. Mail delivery is best effort and never blocks
 * a grade save, document review, or status recalculation.
 */
function notificationTextLimit(string $value, int $limit): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function buildStatusChangeEmail(string $fullName, string $oldStatus, string $newStatus): array
{
    return [
        'GRANTED scholarship status update',
        "Hello {$fullName},\n\nYour scholarship status has changed from {$oldStatus} to {$newStatus}.\n\nPlease sign in to GRANTED to view your current requirements.\n\nGRANTED Scholarship Office",
    ];
}

function buildDeadlineReminderEmail(string $fullName, string $documentType, string $dueDate): array
{
    $formattedDate = date('F j, Y', strtotime($dueDate));
    return [
        'GRANTED document deadline reminder',
        "Hello {$fullName},\n\nThis is a reminder that {$documentType} is due by {$formattedDate} ({$dueDate}). Please upload it in GRANTED before the deadline.\n\nGRANTED Scholarship Office",
    ];
}

/** Store a queued notification and return its database ID. */
function logNotification(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (scholar_id, type, channel, subject, message, old_status, new_status, rule_id, send_status)
         VALUES (:scholar_id, :type, 'EMAIL', :subject, :message, :old_status, :new_status, :rule_id, 'QUEUED')"
    );
    $stmt->execute([
        ':scholar_id' => (int)$data['scholar_id'],
        ':type' => $data['type'],
        ':subject' => notificationTextLimit((string)$data['subject'], 200),
        ':message' => (string)$data['message'],
        ':old_status' => $data['old_status'] ?? null,
        ':new_status' => $data['new_status'] ?? null,
        ':rule_id' => isset($data['rule_id']) ? (int)$data['rule_id'] : null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Send one queued notification. Disabled mail stays QUEUED; any delivery or
 * setup error becomes FAILED and is swallowed so the caller can continue.
 */
function sendNotificationEmail(PDO $pdo, int $notificationId): bool
{
    if (!defined('MAIL_ENABLED') || MAIL_ENABLED !== true) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT n.subject, n.message, n.send_status, s.full_name, u.email
             FROM notifications n
             JOIN scholars s ON s.id = n.scholar_id
             JOIN users u ON u.id = s.user_id
             WHERE n.id = ?'
        );
        $stmt->execute([$notificationId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Notification or scholar email was not found.');
        }
        if ($row['send_status'] === 'SENT') {
            return true;
        }
        if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Scholar email address is invalid.');
        }
        if (!class_exists('Google\Client') || !class_exists('Google\Service\Gmail')) {
            throw new RuntimeException('Google API Client Library is not installed.');
        }
        if (!is_file(GMAIL_CREDENTIALS_PATH) || !is_file(GMAIL_TOKEN_PATH)) {
            throw new RuntimeException('Gmail credentials or authorization token file is missing.');
        }

        $client = new Google\Client();
        $client->setApplicationName('GRANTED Notifications');
        $client->setAuthConfig(GMAIL_CREDENTIALS_PATH);
        $client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
        $client->setAccessType('offline');
        $token = json_decode((string)file_get_contents(GMAIL_TOKEN_PATH), true, 512, JSON_THROW_ON_ERROR);
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();
            if (!$refreshToken) {
                throw new RuntimeException('Gmail refresh token is missing; authorize the sender account again.');
            }
            $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
            if (isset($newToken['error'])) {
                throw new RuntimeException('Gmail token refresh failed: ' . (string)$newToken['error']);
            }
            $newToken = array_merge($token, $newToken);
            $written = file_put_contents(
                GMAIL_TOKEN_PATH,
                json_encode($newToken, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
            if ($written === false) {
                throw new RuntimeException('Could not save the refreshed Gmail token.');
            }
            $client->setAccessToken($newToken);
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode((string)$row['subject']) . '?=';
        $fromName = str_replace(["\r", "\n", '"'], '', MAIL_FROM_NAME);
        $rawMessage = 'To: ' . $row['email'] . "\r\n"
            . 'From: "' . $fromName . '" <' . MAIL_FROM_EMAIL . ">\r\n"
            . 'Subject: ' . $encodedSubject . "\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n"
            . (string)$row['message'];

        $gmailMessage = new Google\Service\Gmail\Message();
        $gmailMessage->setRaw(rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '='));
        $gmail = new Google\Service\Gmail($client);
        $gmail->users_messages->send('me', $gmailMessage);

        $stmt = $pdo->prepare("UPDATE notifications SET send_status = 'SENT', error_message = NULL, sent_at = NOW() WHERE id = ?");
        $stmt->execute([$notificationId]);
        return true;
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("UPDATE notifications SET send_status = 'FAILED', error_message = ?, sent_at = NULL WHERE id = ?");
            $stmt->execute([notificationTextLimit($e->getMessage(), 255), $notificationId]);
        } catch (Throwable $recordingError) {
            error_log('GRANTED notification failure could not be recorded: ' . $recordingError->getMessage());
        }
        error_log('GRANTED email notification failed: ' . $e->getMessage());
        return false;
    }
}

function notifyStatusChange(PDO $pdo, int $scholarId, ?string $oldStatus, string $newStatus): void
{
    if ($oldStatus === $newStatus) {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT full_name FROM scholars WHERE id = ?');
        $stmt->execute([$scholarId]);
        $fullName = $stmt->fetchColumn();
        if ($fullName === false) {
            return;
        }
        [$subject, $body] = buildStatusChangeEmail((string)$fullName, (string)$oldStatus, $newStatus);
        $id = logNotification($pdo, [
            'scholar_id' => $scholarId,
            'type' => 'STATUS_CHANGE',
            'subject' => $subject,
            'message' => $body,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);
        sendNotificationEmail($pdo, $id);
    } catch (Throwable $e) {
        error_log('GRANTED status notification could not be created: ' . $e->getMessage());
    }
}
