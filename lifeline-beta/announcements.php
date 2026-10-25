<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$u      = require_login();
$pdo    = db();
$action = $_GET['action'] ?? '';

const ALLOWED_ICONS = ['📢', '🩹', '❤️', '⚠️', '💉'];

switch ($action) {

    case 'list': {
        $rows = $pdo->query('SELECT id, title, details, date_label, icon, is_featured
                             FROM announcements ORDER BY created_at DESC, id DESC LIMIT 100')->fetchAll();
        out(['announcements' => array_map(fn($r) => [
            'id'        => (int)$r['id'],
            'title'     => $r['title'],
            'details'   => $r['details'],
            'dateLabel' => $r['date_label'],
            'icon'      => $r['icon'],
            'featured'  => (bool)$r['is_featured'],
        ], $rows)]);
    }

    case 'create': {
        require_method('POST');
        require_officer($u);
        $b = body();

        $title    = clean_text($b['title'] ?? '', 120);
        $details  = clean_text($b['details'] ?? '', 1000);
        $date     = clean_text($b['date_label'] ?? '', 120);
        $icon     = (string)($b['icon'] ?? '📢');
        $featured = !empty($b['featured']) ? 1 : 0;

        if ($title === '' || $details === '' || $date === '') {
            fail('Title, schedule and details are required.', 422);
        }
        if (!in_array($icon, ALLOWED_ICONS, true)) {
            $icon = '📢';
        }
        $pdo->prepare('INSERT INTO announcements (title, details, date_label, icon, is_featured, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$title, $details, $date, $icon, $featured, $u['student_id']]);
        out(['id' => (int)$pdo->lastInsertId()], 201);
    }

    case 'delete': {
        require_method('POST');
        require_officer($u);
        $id = (int)(body()['id'] ?? 0);
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
        out(['ok' => true]);
    }

    default:
        fail('Unknown action.', 404);
}
