<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_login();
$pdo = db();

$WINDOW = "created_at >= NOW() - INTERVAL 30 DAY";

// ---- Incidents by type (rolling 30 days): top 4 + "Other" ------------
$rows = $pdo->query("SELECT COALESCE(t.name, 'Unspecified') AS label, COUNT(*) AS c
                     FROM incidents i LEFT JOIN incident_types t ON t.id = i.incident_type_id
                     WHERE i.$WINDOW GROUP BY label ORDER BY c DESC, label")->fetchAll();
$total  = array_sum(array_map(fn($r) => (int)$r['c'], $rows));
$top    = array_slice($rows, 0, 4);
$rest   = array_sum(array_map(fn($r) => (int)$r['c'], array_slice($rows, 4)));
$byType = array_map(fn($r) => ['name' => $r['label'], 'count' => (int)$r['c']], $top);
if ($rest > 0) {
    $byType[] = ['name' => 'Other', 'count' => $rest];
}

// ---- Incidents by building (rolling 30 days): top 4 + "Others" --------
$rows = $pdo->query("SELECT b.name, COUNT(*) AS c
                     FROM incidents i JOIN buildings b ON b.id = i.building_id
                     WHERE i.$WINDOW GROUP BY b.name ORDER BY c DESC, b.name")->fetchAll();
$byLoc = array_map(fn($r) => ['name' => $r['name'], 'count' => (int)$r['c']], array_slice($rows, 0, 4));
$restL = array_sum(array_map(fn($r) => (int)$r['c'], array_slice($rows, 4)));
if ($restL > 0) {
    $byLoc[] = ['name' => 'Others', 'count' => $restL];
}

// ---- Last 6 calendar months: counts + average response minutes ---------
$rows = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c,
                            AVG(TIMESTAMPDIFF(SECOND, created_at, responded_at)) / 60 AS avg_min
                     FROM incidents
                     WHERE created_at >= DATE_FORMAT(NOW() - INTERVAL 5 MONTH, '%Y-%m-01')
                     GROUP BY ym")->fetchAll();
$map = [];
foreach ($rows as $r) {
    $map[$r['ym']] = $r;
}
$months = [];
$first  = new DateTime('first day of this month');
$first->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $key = $first->format('Y-m');
    $months[] = [
        'label'  => $first->format('M'),
        'count'  => isset($map[$key]) ? (int)$map[$key]['c'] : 0,
        'avgMin' => isset($map[$key]) && $map[$key]['avg_min'] !== null ? round((float)$map[$key]['avg_min'], 1) : null,
    ];
    $first->modify('+1 month');
}

// ---- Headline numbers ----------------------------------------------------
$avg = $pdo->query("SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, responded_at)) / 60
                    FROM incidents WHERE responded_at IS NOT NULL AND $WINDOW")->fetchColumn();
$active = (int)$pdo->query("SELECT COUNT(*) FROM cpr_members WHERE duty_status <> 'Off Duty'")->fetchColumn();

out([
    'total'            => $total,
    'avgResponseMin'   => $avg === null || $avg === false ? null : round((float)$avg, 1),
    'activeResponders' => $active,
    'mostCommon'       => isset($top[0]) ? ['name' => $top[0]['label'], 'count' => (int)$top[0]['c']] : null,
    'byType'           => $byType,
    'byLocation'       => $byLoc,
    'months'           => $months,
    'topList'          => array_map(fn($r) => ['name' => $r['label'], 'count' => (int)$r['c']], $top),
]);
