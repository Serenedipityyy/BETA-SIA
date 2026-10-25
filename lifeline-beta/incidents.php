<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$u      = require_login();
$pdo    = db();
$action = $_GET['action'] ?? '';

const NEXT_STATUS = ['DISPATCHED' => 'RESPONDING', 'RESPONDING' => 'ON_SCENE', 'ON_SCENE' => 'RESOLVED'];
const DUTY_FOR    = ['RESPONDING' => 'Responding', 'ON_SCENE' => 'On-Scene', 'RESOLVED' => 'On Duty'];

const INCIDENT_SELECT = "
    SELECT i.id, i.priority, i.status, i.patients, i.details, i.reporter_id, ru.full_name AS reporter_name,
           b.name AS building, r.name AS room, t.name AS type_name,
           i.responder_id, su.full_name AS responder_name,
           UNIX_TIMESTAMP(i.created_at)   AS created_ts,
           UNIX_TIMESTAMP(i.responded_at) AS responded_ts,
           UNIX_TIMESTAMP(i.on_scene_at)  AS on_scene_ts,
           UNIX_TIMESTAMP(i.resolved_at)  AS resolved_ts
    FROM incidents i
    JOIN users ru ON ru.student_id = i.reporter_id
    JOIN buildings b ON b.id = i.building_id
    JOIN rooms r ON r.id = i.room_id
    LEFT JOIN incident_types t ON t.id = i.incident_type_id
    LEFT JOIN users su ON su.student_id = i.responder_id";

function fmt_incident(array $r, bool $withReporter): array
{
    $o = [
        'id'            => $r['id'],
        'priority'      => $r['priority'],
        'status'        => $r['status'],
        'patients'      => (int)$r['patients'],
        'details'       => $r['details'],
        'building'      => $r['building'],
        'room'          => $r['room'],
        'typeName'      => $r['type_name'],
        'responderId'   => $r['responder_id'],
        'responderName' => $r['responder_name'],
        'createdAt'     => ts_ms($r['created_ts']),
        'respondedAt'   => ts_ms($r['responded_ts']),
        'onSceneAt'     => ts_ms($r['on_scene_ts']),
        'resolvedAt'    => ts_ms($r['resolved_ts']),
    ];
    if ($withReporter) {
        $o['reporterId']   = $r['reporter_id'];
        $o['reporterName'] = $r['reporter_name'];
    }
    return $o;
}

function can_advance(array $u, array $inc): bool
{
    if (!is_responder($u) || $inc['status'] === 'RESOLVED') {
        return false;
    }
    if ($inc['status'] === 'DISPATCHED') {
        return true;
    }
    return is_officer($u) || $inc['responder_id'] === $u['student_id'];
}

switch ($action) {

    // -----------------------------------------------------------------
    case 'list': {
        $scope = $_GET['scope'] ?? 'recent';

        if ($scope === 'active') {
            require_responder($u);
            $rows = $pdo->query(INCIDENT_SELECT . " WHERE i.status <> 'RESOLVED'
                ORDER BY FIELD(i.priority,'RED','YELLOW','GREEN'), i.created_at DESC LIMIT 100")->fetchAll();
            out(['incidents' => array_map(fn($r) => fmt_incident($r, true), $rows)]);
        }

        if ($scope === 'mine') {
            $st = $pdo->prepare(INCIDENT_SELECT . " WHERE i.reporter_id = ?
                AND (i.status <> 'RESOLVED' OR i.resolved_at > NOW() - INTERVAL 1 HOUR)
                ORDER BY i.created_at DESC LIMIT 3");
            $st->execute([$u['student_id']]);
            out(['incidents' => array_map(fn($r) => fmt_incident($r, true), $st->fetchAll())]);
        }

        // 'recent': anonymised feed for the dashboard (no reporter identity)
        $rows = $pdo->query(INCIDENT_SELECT . ' ORDER BY i.created_at DESC LIMIT 5')->fetchAll();
        out(['incidents' => array_map(fn($r) => fmt_incident($r, false), $rows)]);
    }

    // -----------------------------------------------------------------
    case 'stats': {
        $row = $pdo->query("
            SELECT SUM(created_at >= CURDATE()) AS today,
                   SUM(created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < CURDATE()) AS yesterday,
                   SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')) AS month_total,
                   SUM(created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND status = 'RESOLVED') AS month_resolved
            FROM incidents")->fetch();

        $avg = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, responded_at)) / 60 AS avg_min, COUNT(*) AS n
            FROM incidents
            WHERE responded_at IS NOT NULL AND created_at >= NOW() - INTERVAL 30 DAY")->fetch();

        $active = (int)$pdo->query("SELECT COUNT(*) FROM cpr_members WHERE duty_status <> 'Off Duty'")->fetchColumn();

        out(['stats' => [
            'today'            => (int)$row['today'],
            'yesterday'        => (int)$row['yesterday'],
            'monthTotal'       => (int)$row['month_total'],
            'monthResolved'    => (int)$row['month_resolved'],
            'avgResponseMin'   => $avg['avg_min'] === null ? null : round((float)$avg['avg_min'], 1),
            'responseSamples'  => (int)$avg['n'],
            'activeResponders' => $active,
        ]]);
    }

    // -----------------------------------------------------------------
    case 'create': {
        require_method('POST');
        $b = body();

        $priority   = strtoupper((string)($b['priority'] ?? ''));
        $buildingId = (int)($b['building_id'] ?? 0);
        $roomId     = (int)($b['room_id'] ?? 0);
        $patients   = max(1, min(50, (int)($b['patients'] ?? 1)));
        $typeId     = ($b['incident_type_id'] ?? '') === '' || $b['incident_type_id'] === null ? null : (int)$b['incident_type_id'];
        $details    = clean_text($b['details'] ?? '', 500);
        if ($details === '') {
            $details = 'No additional remarks';
        }

        if (!in_array($priority, ['RED', 'YELLOW', 'GREEN'], true)) {
            fail('Please select the emergency priority.', 422);
        }
        $chk = $pdo->prepare('SELECT 1 FROM rooms WHERE id = ? AND building_id = ?');
        $chk->execute([$roomId, $buildingId]);
        if (!$chk->fetchColumn()) {
            fail('Please select a valid building and room.', 422);
        }
        if ($typeId !== null) {
            $chk = $pdo->prepare('SELECT 1 FROM incident_types WHERE id = ?');
            $chk->execute([$typeId]);
            if (!$chk->fetchColumn()) {
                $typeId = null;
            }
        }

        // Duplicate guard: same person, same room, still active, inside the cool-down window
        $dup = $pdo->prepare("SELECT id FROM incidents WHERE reporter_id = ? AND room_id = ? AND status <> 'RESOLVED'
                              AND created_at > NOW() - INTERVAL ? SECOND LIMIT 1");
        $dup->execute([$u['student_id'], $roomId, cfg('alert_cooldown_sec')]);
        if ($existing = $dup->fetchColumn()) {
            fail("You already sent an alert for this location ($existing). Responders have been notified.", 409, 'duplicate');
        }

        // Abuse guard: cap simultaneous unresolved alerts per reporter
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE reporter_id = ? AND status <> 'RESOLVED'");
        $cnt->execute([$u['student_id']]);
        if ((int)$cnt->fetchColumn() >= cfg('max_active_per_reporter')) {
            fail('You already have several active alerts. Please wait for responders to resolve them.', 429, 'too_many');
        }

        $id = null;
        $pdo->beginTransaction();
        try {
            for ($try = 0; $try < 5 && $id === null; $try++) {
                $candidate = 'UDD-' . random_int(100000, 999999);
                try {
                    $pdo->prepare('INSERT INTO incidents (id, priority, incident_type_id, building_id, room_id, patients, details, reporter_id)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$candidate, $priority, $typeId, $buildingId, $roomId, $patients, $details, $u['student_id']]);
                    $id = $candidate;
                } catch (PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1062) {
                        throw $e;
                    }
                }
            }
            if ($id === null) {
                throw new RuntimeException('Could not allocate incident id');
            }
            $pdo->prepare("INSERT INTO incident_logs (incident_id, status, changed_by) VALUES (?, 'DISPATCHED', ?)")
                ->execute([$id, $u['student_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $st = $pdo->prepare(INCIDENT_SELECT . ' WHERE i.id = ?');
        $st->execute([$id]);
        out(['incident' => fmt_incident($st->fetch(), true)], 201);
    }

    // -----------------------------------------------------------------
    case 'advance': {
        require_method('POST');
        require_responder($u);
        $b   = body();
        $id  = (string)($b['id'] ?? '');
        $new = strtoupper((string)($b['status'] ?? ''));

        $pdo->beginTransaction();
        try {
            // Row lock: if two medics tap "Accept" at the same moment, only the first wins.
            $st = $pdo->prepare('SELECT id, status, responder_id FROM incidents WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $inc = $st->fetch();
            if (!$inc) {
                $pdo->rollBack();
                fail('Incident not found.', 404);
            }
            if ((NEXT_STATUS[$inc['status']] ?? null) !== $new || !can_advance($u, $inc)) {
                $pdo->rollBack();
                fail("You can't update this incident. It may already be handled by another responder.", 409, 'conflict');
            }

            if ($new === 'RESPONDING') {
                $pdo->prepare('UPDATE incidents SET status = ?, responder_id = ?, responded_at = NOW() WHERE id = ?')
                    ->execute([$new, $u['student_id'], $id]);
                $dutyId = $u['student_id'];
            } elseif ($new === 'ON_SCENE') {
                $pdo->prepare('UPDATE incidents SET status = ?, on_scene_at = NOW() WHERE id = ?')->execute([$new, $id]);
                $dutyId = $inc['responder_id'];
            } else {
                $pdo->prepare('UPDATE incidents SET status = ?, resolved_at = NOW() WHERE id = ?')->execute([$new, $id]);
                $dutyId = $inc['responder_id'];
            }

            if ($dutyId) {
                $pdo->prepare('UPDATE cpr_members SET duty_status = ? WHERE student_id = ?')
                    ->execute([DUTY_FOR[$new], $dutyId]);
            }
            $pdo->prepare('INSERT INTO incident_logs (incident_id, status, changed_by) VALUES (?, ?, ?)')
                ->execute([$id, $new, $u['student_id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $st = $pdo->prepare(INCIDENT_SELECT . ' WHERE i.id = ?');
        $st->execute([$id]);
        out(['incident' => fmt_incident($st->fetch(), true)]);
    }

    default:
        fail('Unknown action.', 404);
}
