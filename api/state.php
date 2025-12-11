<?php
require __DIR__ . '/../includes/utils.php';
ensure_directories();
header('Content-Type: application/json');

$teamId = $_GET['team_id'] ?? '';
if ($teamId === '') {
    echo json_encode(['error' => 'team_id missing']);
    exit;
}
ensure_session();
require_player($teamId);
$lobby = load_lobby($teamId);
apply_round_timeout($lobby);
echo json_encode([
    'lobby' => $lobby,
    'now' => time(),
]);
