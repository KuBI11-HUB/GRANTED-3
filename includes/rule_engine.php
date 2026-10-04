<?php
/**
 * The rule engine — Phase 4 term filtering integration.
 */

function compareValue($actual, string $operator, $threshold): bool
{
    $actual = (float)$actual;
    $threshold = (float)$threshold;

    switch ($operator) {
        case '>=': return $actual >= $threshold;
        case '<=': return $actual <= $threshold;
        case '>':  return $actual > $threshold;
        case '<':  return $actual < $threshold;
        case '==': return $actual == $threshold;
        default:   return false;
    }
}

function evaluateRule(PDO $pdo, array $rule, array $scholar): string
{
    switch ($rule['rule_type']) {
        case 'GPA':
            if ($scholar['current_gpa'] === null) return 'PENDING';
            return compareValue($scholar['current_gpa'], $rule['operator'], $rule['threshold_value'])
                ? 'PASS' : 'FAIL';

        case 'UNIT_LOAD':
            if ($scholar['current_units'] === null) return 'PENDING';
            return compareValue($scholar['current_units'], $rule['operator'], $rule['threshold_value'])
                ? 'PASS' : 'FAIL';

        case 'DOCUMENT':
            // Filter by current school year and semester constants
            $stmt = $pdo->prepare(
                'SELECT status FROM documents WHERE scholar_id = ? AND document_type = ? AND school_year = ? AND semester = ?
                 ORDER BY uploaded_at DESC LIMIT 1'
            );
            $stmt->execute([$scholar['id'], $rule['threshold_value'], CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
            $doc = $stmt->fetch();

            if (!$doc) return 'PENDING';          // not uploaded yet
            if ($doc['status'] === 'Verified') return 'PASS';
            if ($doc['status'] === 'Rejected') return 'FAIL';
            return 'PENDING';                     // uploaded, awaiting admin review

        case 'DEADLINE':
        default:
            return 'PENDING';
    }
}

function evaluateScholar(PDO $pdo, int $scholarId): ?string
{
    $stmt = $pdo->prepare('SELECT * FROM scholars WHERE id = ?');
    $stmt->execute([$scholarId]);
    $scholar = $stmt->fetch();
    if (!$scholar) {
        return null;
    }
    $oldStatus = $scholar['current_status'];

    $stmt = $pdo->prepare('SELECT * FROM rules WHERE scholarship_type_id = ? AND is_active = 1');
    $stmt->execute([$scholar['scholarship_type_id']]);
    $rules = $stmt->fetchAll();

    $allPassed = true;
    $anyPending = false;

    foreach ($rules as $rule) {
        $result = evaluateRule($pdo, $rule, $scholar);

        $stmt = $pdo->prepare(
            'INSERT INTO evaluations (scholar_id, rule_id, result)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE result = VALUES(result), evaluated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$scholarId, $rule['id'], $result]);

        if ($result === 'FAIL') $allPassed = false;
        if ($result === 'PENDING') $anyPending = true;
    }

    if (empty($rules)) {
        $overallStatus = 'PENDING';
    } elseif ($anyPending) {
        $overallStatus = 'PENDING';
    } elseif ($allPassed) {
        $overallStatus = 'PASS';
    } else {
        $overallStatus = 'FAIL';
    }

    $stmt = $pdo->prepare('UPDATE scholars SET current_status = ? WHERE id = ?');
    $stmt->execute([$overallStatus, $scholarId]);
    notifyStatusChange($pdo, (int)$scholar['id'], $oldStatus, $overallStatus);

    return $overallStatus;
}

function recalculateGpaAndUnits(PDO $pdo, int $scholarId): void
{
    // Sum grades scoped to the current school year and semester
    $stmt = $pdo->prepare(
        'SELECT SUM(grade * units) AS wsum, SUM(units) AS tunits FROM grades WHERE scholar_id = ? AND school_year = ? AND semester = ?'
    );
    $stmt->execute([$scholarId, CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
    $row = $stmt->fetch();

    $totalUnits = $row['tunits'] !== null ? (int)$row['tunits'] : null;
    $gpa = ($totalUnits && $totalUnits > 0) ? ($row['wsum'] / $totalUnits) : null;

    $stmt = $pdo->prepare('UPDATE scholars SET current_gpa = ?, current_units = ? WHERE id = ?');
    $stmt->execute([$gpa, $totalUnits, $scholarId]);
}

/**
 * Returns the list of required document_types (from active DOCUMENT rules)
 * for a scholarship type, each with the scholar's latest upload status for the current term.
 */
function getDocumentChecklist(PDO $pdo, int $scholarId, int $scholarshipTypeId): array
{
    $stmt = $pdo->prepare(
        "SELECT threshold_value AS document_type FROM rules
         WHERE scholarship_type_id = ? AND rule_type = 'DOCUMENT' AND is_active = 1"
    );
    $stmt->execute([$scholarshipTypeId]);
    $required = $stmt->fetchAll();

    $checklist = [];
    foreach ($required as $r) {
        $stmt = $pdo->prepare(
            'SELECT status, remarks, uploaded_at FROM documents
             WHERE scholar_id = ? AND document_type = ? AND school_year = ? AND semester = ? ORDER BY uploaded_at DESC LIMIT 1'
        );
        $stmt->execute([$scholarId, $r['document_type'], CURRENT_SCHOOL_YEAR, CURRENT_SEMESTER]);
        $doc = $stmt->fetch();

        $checklist[] = [
            'document_type' => $r['document_type'],
            'status' => $doc['status'] ?? 'Not uploaded',
            'remarks' => $doc['remarks'] ?? null,
        ];
    }
    return $checklist;
}