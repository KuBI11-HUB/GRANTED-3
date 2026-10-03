<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/../includes/notifications.php';

$pdo = getDbConnection();
$days = max(1, (int)DEADLINE_REMINDER_DAYS);
$startDate = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$endDate = (new DateTimeImmutable('tomorrow'))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');

$sql = "SELECT s.id AS scholar_id, s.full_name, r.id AS rule_id,
               r.threshold_value AS document_type, r.due_date
        FROM rules r
        JOIN scholars s ON s.scholarship_type_id = r.scholarship_type_id
        LEFT JOIN documents d ON d.id = (
            SELECT d2.id FROM documents d2
            WHERE d2.scholar_id = s.id
              AND d2.document_type = r.threshold_value
              AND d2.school_year = r.school_year
              AND d2.semester = r.semester
            ORDER BY d2.uploaded_at DESC, d2.id DESC LIMIT 1
        )
        WHERE r.is_active = 1
          AND r.rule_type IN ('DOCUMENT', 'DEADLINE')
          AND r.due_date BETWEEN :start_date AND :end_date
          AND (d.id IS NULL OR d.status <> 'Verified')
          AND NOT EXISTS (
              SELECT 1 FROM notifications n
              WHERE n.scholar_id = s.id
                AND n.rule_id = r.id
                AND n.type = 'DEADLINE_APPROACHING'
                AND n.message LIKE CONCAT('%', DATE_FORMAT(r.due_date, '%Y-%m-%d'), '%')
          )
        ORDER BY r.due_date, s.id";
$stmt = $pdo->prepare($sql);
$stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
$rows = $stmt->fetchAll();
$queued = 0;
$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    [$subject, $body] = buildDeadlineReminderEmail($row['full_name'], $row['document_type'], $row['due_date']);
    try {
        $id = logNotification($pdo, [
            'scholar_id' => $row['scholar_id'],
            'type' => 'DEADLINE_APPROACHING',
            'subject' => $subject,
            'message' => $body,
            'rule_id' => $row['rule_id'],
        ]);
        if (!MAIL_ENABLED) {
            $queued++;
        } elseif (sendNotificationEmail($pdo, $id)) {
            $sent++;
        } else {
            $failed++;
        }
    } catch (Throwable $e) {
        $failed++;
        error_log('GRANTED deadline reminder could not be created: ' . $e->getMessage());
    }
}

echo "Deadline reminders: {$sent} sent, {$queued} queued, {$failed} failed.\n";
