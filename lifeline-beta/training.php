<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$u      = require_login();
$pdo    = db();
$action = $_GET['action'] ?? '';

function pretty_date(string $ymd): string
{
    return (new DateTime($ymd))->format('F j, Y');
}

switch ($action) {

    case 'list': {
        // Upcoming sessions (cards) ...
        $st = $pdo->prepare(
            "SELECT s.id, s.title, s.tag, s.description, s.session_date, s.location, s.slots,
                    (SELECT COUNT(*) FROM training_registrations r
                      WHERE r.session_id = s.id AND r.status IN ('Registered','Attended')) AS taken,
                    (SELECT r.status FROM training_registrations r
                      WHERE r.session_id = s.id AND r.student_id = ?) AS my_status
             FROM training_sessions s
             WHERE s.session_date >= CURDATE()
             ORDER BY s.session_date, s.id"
        );
        $st->execute([$u['student_id']]);
        $sessions = array_map(fn($r) => [
            'id'          => (int)$r['id'],
            'title'       => $r['title'],
            'tag'         => $r['tag'],
            'description' => $r['description'],
            'date'        => pretty_date($r['session_date']),
            'location'    => $r['location'],
            'slots'       => (int)$r['slots'],
            'taken'       => (int)$r['taken'],
            'myStatus'    => $r['my_status'],
        ], $st->fetchAll());

        // ... and this person's own attendance history
        $st = $pdo->prepare(
            "SELECT s.title, s.session_date, r.status
             FROM training_registrations r
             JOIN training_sessions s ON s.id = r.session_id
             WHERE r.student_id = ? AND r.status <> 'Cancelled'
             ORDER BY s.session_date DESC LIMIT 10"
        );
        $st->execute([$u['student_id']]);
        $mine = array_map(fn($r) => [
            'title'  => $r['title'],
            'date'   => pretty_date($r['session_date']),
            'status' => $r['status'],
        ], $st->fetchAll());

        out(['sessions' => $sessions, 'mine' => $mine]);
    }

    case 'register': {
        require_method('POST');
        $sid = (int)(body()['session_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, slots, session_date FROM training_sessions WHERE id = ? FOR UPDATE');
            $st->execute([$sid]);
            $s = $st->fetch();
            if (!$s) {
                $pdo->rollBack();
                fail('Training session not found.', 404);
            }
            if ($s['session_date'] < date('Y-m-d')) {
                $pdo->rollBack();
                fail('This session has already taken place.', 409);
            }

            $st = $pdo->prepare('SELECT id, status FROM training_registrations WHERE session_id = ? AND student_id = ?');
            $st->execute([$sid, $u['student_id']]);
            $reg = $st->fetch();
            if ($reg && $reg['status'] !== 'Cancelled') {
                $pdo->rollBack();
                fail('You are already registered for this session.', 409);
            }

            $st = $pdo->prepare("SELECT COUNT(*) FROM training_registrations WHERE session_id = ? AND status IN ('Registered','Attended')");
            $st->execute([$sid]);
            if ((int)$st->fetchColumn() >= (int)$s['slots']) {
                $pdo->rollBack();
                fail('Sorry, this session is already full.', 409, 'full');
            }

            if ($reg) {
                $pdo->prepare("UPDATE training_registrations SET status = 'Registered', registered_at = NOW() WHERE id = ?")
                    ->execute([$reg['id']]);
            } else {
                $pdo->prepare('INSERT INTO training_registrations (session_id, student_id) VALUES (?, ?)')
                    ->execute([$sid, $u['student_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        out(['ok' => true]);
    }

    case 'cancel': {
        require_method('POST');
        $sid = (int)(body()['session_id'] ?? 0);
        $pdo->prepare("UPDATE training_registrations SET status = 'Cancelled' WHERE session_id = ? AND student_id = ? AND status = 'Registered'")
            ->execute([$sid, $u['student_id']]);
        out(['ok' => true]);
    }

    // ---- Officer tools (API only; no screen for these yet) -------------
    case 'create_session': {
        require_method('POST');
        require_officer($u);
        $b     = body();
        $title = clean_text($b['title'] ?? '', 120);
        $tag   = (string)($b['tag'] ?? 'WORKSHOP');
        $desc  = clean_text($b['description'] ?? '', 300);
        $date  = (string)($b['session_date'] ?? '');
        $loc   = clean_text($b['location'] ?? '', 100);
        $slots = (int)($b['slots'] ?? 0);

        $d = DateTime::createFromFormat('!Y-m-d', $date);
        if ($title === '' || $desc === '' || $loc === '' || $slots < 1 || !$d || $d->format('Y-m-d') !== $date
            || !in_array($tag, ['CERTIFICATION', 'REFRESHER', 'WORKSHOP', 'SEMINAR'], true)) {
            fail('Please complete all fields correctly.', 422);
        }
        $pdo->prepare('INSERT INTO training_sessions (title, tag, description, session_date, location, slots, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $tag, $desc, $date, $loc, $slots, $u['student_id']]);
        out(['id' => (int)$pdo->lastInsertId()], 201);
    }

    case 'mark_attendance': {
        require_method('POST');
        require_officer($u);
        $b      = body();
        $status = (string)($b['status'] ?? '');
        if (!in_array($status, ['Attended', 'Absent'], true)) {
            fail('Invalid status.', 422);
        }
        $pdo->prepare("UPDATE training_registrations SET status = ? WHERE session_id = ? AND student_id = ? AND status <> 'Cancelled'")
            ->execute([$status, (int)($b['session_id'] ?? 0), (string)($b['student_id'] ?? '')]);
        out(['ok' => true]);
    }

    default:
        fail('Unknown action.', 404);
}
