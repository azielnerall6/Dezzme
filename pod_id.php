<?php
/**
 * Gives a pod its permanent PD-XXXXXXXXXX id (only if it has none yet).
 * Safe to call any number of times: it never overwrites an existing id.
 * Uniqueness is enforced by the UNIQUE index; on a collision it just retries.
 */
function pd_assign_id(mysqli $conn, int $group_id): string {
    for ($try = 0; $try < 15; $try++) {
        $digits = '';
        for ($i = 0; $i < 10; $i++) $digits .= random_int(0, 9);
        $id = 'PD-' . $digits;

        try {
            $stmt = $conn->prepare("UPDATE `groups` SET pd_id=? WHERE id=? AND pd_id IS NULL");
            $stmt->bind_param('si', $id, $group_id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            if ((int)$e->getCode() === 1062) continue;   // duplicate -> try another number
            throw $e;
        }

        if ($affected > 0) return $id;   // success
        break;                            // row already had an id
    }

    // return whatever is stored
    $stmt = $conn->prepare("SELECT pd_id FROM `groups` WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $group_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['pd_id'] ?? 'PD-0000000000';
}