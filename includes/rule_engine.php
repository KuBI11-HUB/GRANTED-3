<?php
/**
 * The rule engine — Phase 3, now complete for GPA, UNIT_LOAD, and DOCUMENT.
 * DEADLINE still returns PENDING — that one needs a "today vs due date"
 * concept we haven't scoped yet.
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
            // For a DOCUMENT rule, threshold_value holds the *required
            // document_type string* (e.g. "Certificate of Registration"),
            // not a number — this matches how it's documented in the schema.
            $stmt = $pdo->prepare(
                'SELECT status FROM documents WHERE scholar_id = ? AND document_type = ?
                 ORDER BY uploaded_at DESC LIMIT 1'
            );
            $stmt->execute([$scholar['id'], $rule['threshold_value']]);
            $doc = $stmt->fetch();

            if (!$doc) return 'PENDING';           // not uploaded yet
            if ($doc['status'] === 'Verified') return 'PASS';
            if ($doc['status'] === 'Rejected') return 'FAIL';
            return 'PENDING';                       // uploaded, awaiting admin review

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

    return $overallStatus;
}

function recalculateGpaAndUnits(PDO $pdo, int $scholarId): void
{
    $stmt = $pdo->prepare(
        'SELECT SUM(grade * units) AS wsum, SUM(units) AS tunits FROM grades WHERE scholar_id = ?'
    );
    $stmt->execute([$scholarId]);
    $row = $stmt->fetch();

    $totalUnits = $row['tunits'] !== null ? (int)$row['tunits'] : null;
    $gpa = ($totalUnits && $totalUnits > 0) ? ($row['wsum'] / $totalUnits) : null;

    $stmt = $pdo->prepare('UPDATE scholars SET current_gpa = ?, current_units = ? WHERE id = ?');
    $stmt->execute([$gpa, $totalUnits, $scholarId]);
}

/**
 * Returns the list of required document_types (from active DOCUMENT rules)
 * for a scholarship type, each with the scholar's latest upload status —
 * this is both the scholar's checklist AND the upload form's dropdown.
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
             WHERE scholar_id = ? AND document_type = ? ORDER BY uploaded_at DESC LIMIT 1'
        );
        $stmt->execute([$scholarId, $r['document_type']]);
        $doc = $stmt->fetch();

        $checklist[] = [
            'document_type' => $r['document_type'],
            'status' => $doc['status'] ?? 'Not uploaded',
            'remarks' => $doc['remarks'] ?? null,
        ];
    }
    return $checklist;
}
