<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_login();
$pdo = db();

$buildings = [];
foreach ($pdo->query('SELECT id, name FROM buildings ORDER BY sort_order, id') as $b) {
    $buildings[(int)$b['id']] = ['id' => (int)$b['id'], 'name' => $b['name'], 'rooms' => []];
}
foreach ($pdo->query('SELECT id, building_id, name FROM rooms ORDER BY id') as $r) {
    $bid = (int)$r['building_id'];
    if (isset($buildings[$bid])) {
        $buildings[$bid]['rooms'][] = ['id' => (int)$r['id'], 'name' => $r['name']];
    }
}

$squads = array_map(fn($s) => [
    'id' => (int)$s['id'], 'name' => $s['name'], 'label' => $s['role_label'],
    'coverage' => $s['coverage'], 'color' => $s['color'],
], $pdo->query('SELECT id, name, role_label, coverage, color FROM squads ORDER BY id')->fetchAll());

$zones = array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['name']],
    $pdo->query('SELECT id, name FROM zones ORDER BY id')->fetchAll());

$types = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => $t['name']],
    $pdo->query('SELECT id, name FROM incident_types ORDER BY sort_order, id')->fetchAll());

out([
    'buildings' => array_values($buildings),
    'squads'    => $squads,
    'zones'     => $zones,
    'types'     => $types,
]);
