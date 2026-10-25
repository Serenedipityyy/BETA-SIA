<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$u      = require_login();
$pdo    = db();
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'list': {
        $rows = $pdo->query(
            "SELECT u.student_id, u.full_name, u.role, m.squad_id, m.zone_id, m.duty_status,
                    s.name AS squad_name, s.role_label AS squad_label, z.name AS zone_name,
                    (u.password_hash IS NOT NULL) AS activated
             FROM cpr_members m
             JOIN users u ON u.student_id = m.student_id
             JOIN squads s ON s.id = m.squad_id
             JOIN zones z ON z.id = m.zone_id
             WHERE u.is_active = 1
             ORDER BY u.role = 'officer' DESC, u.full_name"
        )->fetchAll();

        $officer = is_officer($u);
        $list = array_map(function ($r) use ($officer) {
            $o = [
                'name'       => $r['full_name'],
                'role'       => $r['role'],
                'squadId'    => (int)$r['squad_id'],
                'squadName'  => $r['squad_name'],
                'squadLabel' => $r['squad_label'],
                'zone'       => $r['zone_name'],
                'status'     => $r['duty_status'],
            ];
            // Student IDs are only shown to officers; students and members only see names.
            if ($officer) {
                $o['studentId'] = $r['student_id'];
                $o['activated'] = (bool)$r['activated'];
            }
            // The page still needs to know which row is "you"
            $o['isSelf'] = $r['student_id'] === $u['student_id'];
            return $o;
        }, $rows);

        out(['roster' => $list]);
    }

    case 'add': {
        require_method('POST');
        require_officer($u);
        $b      = body();
        $id     = trim((string)($b['student_id'] ?? ''));
        $name   = clean_text($b['full_name'] ?? '', 60);
        $role   = (string)($b['role'] ?? 'member');
        $squad  = (int)($b['squad_id'] ?? 0);
        $zone   = (int)($b['zone_id'] ?? 0);

        if (!preg_match(ID_REGEX, $id)) {
            fail('Invalid Student ID format.', 422);
        }
        if (!in_array($role, ['member', 'officer'], true)) {
            fail('Invalid role.', 422);
        }
        $ok = $pdo->prepare('SELECT (SELECT COUNT(*) FROM squads WHERE id = ?) + (SELECT COUNT(*) FROM zones WHERE id = ?)');
        $ok->execute([$squad, $zone]);
        if ((int)$ok->fetchColumn() !== 2) {
            fail('Please choose a valid squad and zone.', 422);
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT role, password_hash FROM users WHERE student_id = ? FOR UPDATE');
            $st->execute([$id]);
            $existing = $st->fetch();
            $code = null;

            if ($existing) {
                if ($existing['role'] === 'admin') {
                    $pdo->rollBack();
                    fail('That account cannot be changed here.', 403);
                }
                $dup = $pdo->prepare('SELECT 1 FROM cpr_members WHERE student_id = ?');
                $dup->execute([$id]);
                if ($dup->fetchColumn()) {
                    $pdo->rollBack();
                    fail('This Student ID is already on the roster.', 409);
                }
                $pdo->prepare('UPDATE users SET role = ? WHERE student_id = ?')->execute([$role, $id]);
            } else {
                if (mb_strlen($name) < 2) {
                    $pdo->rollBack();
                    fail('Please enter the full name (2-60 characters).', 422);
                }
                // New account with no password: the person activates it themselves, using this one-time code.
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $code = '';
                for ($i = 0; $i < 8; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }
                $pdo->prepare('INSERT INTO users (student_id, full_name, role, activation_code_sha256) VALUES (?, ?, ?, ?)')
                    ->execute([$id, $name, $role, hash('sha256', $code)]);
            }

            $pdo->prepare("INSERT INTO cpr_members (student_id, squad_id, zone_id, duty_status) VALUES (?, ?, ?, 'On Duty')")
                ->execute([$id, $squad, $zone]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        out(['ok' => true, 'activationCode' => $code], 201);
    }

    case 'set_squad': {
        require_method('POST');
        require_officer($u);
        $b  = body();
        $id = (string)($b['student_id'] ?? '');
        $sq = (int)($b['squad_id'] ?? 0);

        $chk = $pdo->prepare('SELECT 1 FROM squads WHERE id = ?');
        $chk->execute([$sq]);
        if (!$chk->fetchColumn()) {
            fail('Invalid squad.', 422);
        }
        $st = $pdo->prepare('UPDATE cpr_members SET squad_id = ? WHERE student_id = ?');
        $st->execute([$sq, $id]);
        out(['ok' => true]);
    }

    case 'toggle_duty': {
        require_method('POST');
        require_officer($u);
        $id = (string)(body()['student_id'] ?? '');
        $pdo->prepare("UPDATE cpr_members SET duty_status = IF(duty_status = 'Off Duty', 'On Duty', 'Off Duty') WHERE student_id = ?")
            ->execute([$id]);
        out(['ok' => true]);
    }

    default:
        fail('Unknown action.', 404);
}
