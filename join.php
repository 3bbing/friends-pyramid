<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
ensure_session();

$teamId = $_GET['team'] ?? '';
$token = $_GET['token'] ?? '';
$team = $teamId ? load_team($teamId) : null;

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teamId = $_POST['team_id'] ?? '';
    $token = $_POST['token'] ?? '';
    $team = $teamId ? load_team($teamId) : null;
    $name = sanitize_text($_POST['player_name'] ?? '', 40);
    $password = $_POST['team_password'] ?? '';
    if (!$team) {
        $error = 'Team nicht gefunden';
    } elseif (!password_verify($password, $team['password_hash'])) {
        $error = 'Passwort falsch';
    } elseif (!empty($team['invite_token']) && $token !== $team['invite_token']) {
        $error = 'Invite-Link ungültig';
    } else {
        $lobby = load_lobby($teamId);
        $playerId = random_id(6);
        $isHost = empty($lobby['players']);
        $player = [
            'player_id' => $playerId,
            'name' => $name ?: 'Spieler ' . (count($lobby['players']) + 1),
            'joined_at' => date('c'),
            'is_host' => $isHost,
        ];
        $lobby['players'][] = $player;
        save_lobby($lobby);
        $_SESSION['player_id'] = $playerId;
        $_SESSION['team_id'] = $teamId;
        header('Location: lobby.php?team=' . urlencode($teamId));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team beitreten</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="page">
    <header>
        <h1>Team beitreten</h1>
        <p>Gib deinen Namen und das Team-Passwort ein.</p>
    </header>
    <main class="card">
        <?php if ($team): ?>
            <p><strong>Team:</strong> <?= htmlspecialchars($team['name']); ?> (ID: <?= htmlspecialchars($team['id']); ?>)</p>
        <?php elseif ($teamId): ?>
            <p class="error">Team nicht gefunden.</p>
        <?php endif; ?>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <form method="post" class="stack">
            <input type="hidden" name="team_id" value="<?= htmlspecialchars($teamId); ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
            <label>Dein Name<input type="text" name="player_name" required maxlength="40"></label>
            <label>Team-Passwort<input type="password" name="team_password" required></label>
            <button type="submit" class="primary">Beitreten</button>
        </form>
    </main>
    <footer><a href="index.php">Zurück</a></footer>
</div>
</body>
</html>
