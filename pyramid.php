<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
ensure_session();
$teamId = $_GET['team'] ?? ($_SESSION['team_id'] ?? '');
if (!$teamId) {
    header('Location: index.php');
    exit;
}
$lobby = load_lobby($teamId);
$player = find_player($lobby, $_SESSION['player_id'] ?? '');
if (!$player) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gesamtpyramide</title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="page pyramid-page" data-team="<?= htmlspecialchars($teamId); ?>" data-player="<?= htmlspecialchars($player['player_id']); ?>">
    <header>
        <h1>Gesamtpyramide</h1>
        <p>Team: <?= htmlspecialchars($lobby['team_id']); ?> · Spieler: <?= htmlspecialchars($player['name']); ?></p>
        <p><a class="pill secondary" href="lobby.php?team=<?= urlencode($teamId); ?>">Zurück zur Lobby</a></p>
    </header>
    <main class="card">
        <div id="round-picker" class="stack"></div>
        <div id="pyramid-overview" class="stack"></div>
        <div id="path-editor" class="stack"></div>
    </main>
</div>
<script src="assets/js/pyramid_view.js"></script>
</body>
</html>
