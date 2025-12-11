<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
$config = load_config();

$teamName = sanitize_text($_POST['team_name'] ?? '', 60);
$password = $_POST['team_password'] ?? '';

if ($teamName === '' || $password === '') {
    header('Location: index.php');
    exit;
}

$teamId = random_id(8);
$inviteToken = random_id(8);
$team = [
    'id' => $teamId,
    'name' => $teamName,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'invite_token' => $inviteToken,
    'created_at' => date('c'),
];

save_team($team);
$lobby = load_lobby($teamId);

header('Location: join.php?team=' . urlencode($teamId) . '&token=' . urlencode($inviteToken));
exit;
