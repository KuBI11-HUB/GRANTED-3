<?php
$requiredRole = 'scholar';
require_once __DIR__ . '/../includes/auth_check.php';

$pdo = getDbConnection();

$stmt = $pdo->prepare(
    'SELECT s.*, st.name AS type_name FROM scholars s
     JOIN scholarship_types st ON st.id = s.scholarship_type_id
     WHERE s.user_id = ?'
);
$stmt->execute([$_SESSION['user_id']]);
$scholar = $stmt->fetch();

$evaluations = [];
$checklist = [];
$outstanding = [];

if ($scholar) {
    $stmt = $pdo->prepare(
        'SELECT e.result, r.rule_type, r.operator, r.threshold_value FROM evaluations e
         JOIN rules r ON r.id = e.rule_id
         WHERE e.scholar_id = ?
         ORDER BY r.rule_type'
    );
    $stmt->execute([$scholar['id']]);
    $evaluations = $stmt->fetchAll();

    $checklist = getDocumentChecklist($pdo, $scholar['id'], $scholar['scholarship_type_id']);
    foreach ($checklist as $item) {
        if ($item['status'] !== 'Verified') {
            $outstanding[] = $item;
        }
    }
}

$pageTitle = 'My Status';
require_once __DIR__ . '/../includes/header.php';
?>

<h1>My status</h1>
<p>Logged in as <?php echo sanitize($_SESSION['email']); ?>.</p>

<?php if (!$scholar): ?>
    <div class="card"><p>No scholar record is linked to this account yet.</p></div>
<?php else: ?>

<div class="card">
    <h2><?php echo sanitize($scholar['type_name']); ?></h2>
    <p>Overall status:
        <span class="status-badge <?php echo statusBadgeClass($scholar['current_status']); ?>">
            <?php echo sanitize($scholar['current_status']); ?>
        </span>
    </p>
    <p>Current GPA: <?php echo $scholar['current_gpa'] !== null ? sanitize((string)$scholar['current_gpa']) : 'Not yet recorded'; ?>
       &nbsp;|&nbsp; Current units: <?php echo $scholar['current_units'] !== null ? sanitize((string)$scholar['current_units']) : 'Not yet recorded'; ?></p>
</div>

<div class="card">
    <h2>Rule-by-rule breakdown</h2>
    <ul class="rule-list">
        <?php foreach ($evaluations as $e): ?>
            <li>
                <span><?php echo sanitize($e['rule_type']); ?> (<?php echo sanitize($e['operator']); ?> <?php echo sanitize($e['threshold_value']); ?>)</span>
                <span class="status-badge <?php echo statusBadgeClass($e['result']); ?>"><?php echo sanitize($e['result']); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($evaluations)): ?>
            <li>No rules have been set for your scholarship type yet.</li>
        <?php endif; ?>
    </ul>
</div>

<div class="card">
    <h2>Pending checklist</h2>
    <?php if (empty($checklist)): ?>
        <p>No documents are required for your scholarship type.</p>
    <?php elseif (empty($outstanding)): ?>
        <p>Nothing outstanding — every required document has been verified.</p>
    <?php else: ?>
        <ul class="rule-list">
            <?php foreach ($outstanding as $item): ?>
                <li>
                    <span><?php echo sanitize($item['document_type']); ?></span>
                    <span class="status-badge <?php
                        echo $item['status'] === 'Rejected' ? 'status-badge--fail' : 'status-badge--pending';
                    ?>"><?php echo sanitize($item['status']); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p style="margin-top:1rem;"><a class="btn" href="/GRANTED/scholar/upload_document.php">Upload documents</a></p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
