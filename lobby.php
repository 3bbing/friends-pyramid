<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
ensure_session();
$teamId = $_GET['team'] ?? ($_SESSION['team_id'] ?? '');
if (!$teamId) {
    header('Location: index.php');
    exit;
}
$team = load_team($teamId);
$lobby = load_lobby($teamId);
$player = find_player($lobby, $_SESSION['player_id'] ?? '');
if (!$team || !$player) {
    header('Location: index.php');
    exit;
}
$poolConfig = available_question_pools();
$defaultPools = array_keys(array_filter($poolConfig, fn($p) => !empty($p['default'])));
$allPools = array_keys($poolConfig);
$cards = load_questions_pool($allPools, $teamId);
$maxDepth = max_depth_for_cards($cards);
$config = load_config();
$defaultDepth = min(max(2, $config['default_depth']), $maxDepth);
$timerOptions = $config['round_timer_options'] ?? [0, 60, 120, 180];
$defaultTimer = $config['default_timer_seconds'] ?? 0;
$poolInfo = [];
foreach ($poolConfig as $key => $pool) {
    $count = count(read_json_file($pool['path']) ?? []);
    $poolInfo[] = [
        'key' => $key,
        'label' => $pool['label'] ?? $key,
        'count' => $count,
        'default' => !empty($pool['default']),
    ];
}
$inviteLink = sprintf('%s/join.php?team=%s&token=%s', dirname($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']), urlencode($teamId), urlencode($team['invite_token']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lobby - <?= htmlspecialchars($team['name']); ?></title>
    <link rel="stylesheet" href="assets/css/main.css">
</head>
<body>
<div class="page" data-team="<?= htmlspecialchars($teamId); ?>" data-player="<?= htmlspecialchars($player['player_id']); ?>" data-default-depth="<?= $defaultDepth; ?>" data-max-depth="<?= $maxDepth; ?>" data-default-timer="<?= $defaultTimer; ?>" data-timers='<?= htmlspecialchars(json_encode($timerOptions), ENT_QUOTES); ?>' data-pools='<?= htmlspecialchars(json_encode($poolInfo), ENT_QUOTES); ?>'>
    <header>
        <h1><?= htmlspecialchars($team['name']); ?> – Lobby</h1>
        <p>Invite-Link: <code><?= htmlspecialchars($inviteLink); ?></code></p>
        <button id="stay-in-lobby" class="secondary hidden" type="button">In der Lobby bleiben</button>
    </header>
    <main class="card">
        <div id="lobby-view" class="view">
            <h2>Warten auf Start</h2>
            <div id="players"></div>
            <?php if ($player['is_host']): ?>
                <form id="start-form" class="stack inline">
                    <fieldset>
                        <legend>Fragenpools (mindestens einer)</legend>
                        <div class="pool-list">
                            <?php foreach ($poolInfo as $pool): ?>
                                <label><input type="checkbox" name="pools[]" value="<?= htmlspecialchars($pool['key']); ?>" <?= $pool['default'] ? 'checked' : ''; ?>> <?= htmlspecialchars($pool['label']); ?> (<?= $pool['count']; ?>)</label>
                            <?php endforeach; ?>
                        </div>
                        <p class="muted">Eigene und globale Custom-Fragen werden automatisch ergänzt.</p>
                    </fieldset>
                    <label>Tiefe auswählen (max <?= $maxDepth; ?>)
                        <select name="depth" id="depth-select">
                            <?php for ($i = 2; $i <= $maxDepth; $i++): ?>
                                <option value="<?= $i; ?>" <?= $i === $defaultDepth ? 'selected' : ''; ?>><?= $i; ?> Ebenen</option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>Runden-Timer
                        <select name="timer" id="timer-select">
                            <?php foreach ($timerOptions as $seconds): ?>
                                <option value="<?= $seconds; ?>" <?= $seconds === $defaultTimer ? 'selected' : ''; ?>><?= $seconds === 0 ? 'Kein Timer' : ($seconds . ' Sekunden'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button id="start-game" class="primary" type="button">Spiel starten</button>
                </form>
                <p class="muted">Je tiefer die Pyramide, desto mehr Karten werden benötigt (<?= count($cards); ?> vorhanden).</p>
            <?php endif; ?>
            <p class="muted">Aktualisiert automatisch.</p>
        </div>
        <div id="round-view" class="view hidden">
            <div id="round-meta"></div>
            <div id="pyramid-form"></div>
            <div class="status" id="round-status"></div>
            <p><a class="pill secondary" href="pyramid.php?team=<?= urlencode($teamId); ?>" target="_blank" rel="noopener">Gesamtpyramide in neuem Tab</a></p>
            <?php if ($player['is_host']): ?>
                <div class="actions">
                    <button id="force-reveal" class="secondary">Force Reveal</button>
                </div>
            <?php endif; ?>
        </div>
        <div id="reveal-view" class="view hidden">
            <div id="reveal-summary"></div>
            <p><a class="pill secondary" href="pyramid.php?team=<?= urlencode($teamId); ?>" target="_blank" rel="noopener">Gesamtpyramide ansehen</a></p>
            <?php if ($player['is_host']): ?>
                <form id="next-form" class="stack inline">
                    <fieldset>
                        <legend>Fragenpools</legend>
                        <div class="pool-list">
                            <?php foreach ($poolInfo as $pool): ?>
                                <label><input type="checkbox" name="pools[]" value="<?= htmlspecialchars($pool['key']); ?>" <?= $pool['default'] ? 'checked' : ''; ?>> <?= htmlspecialchars($pool['label']); ?> (<?= $pool['count']; ?>)</label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <label>Nächste Tiefe
                        <select name="depth" id="next-depth">
                            <?php for ($i = 2; $i <= $maxDepth; $i++): ?>
                                <option value="<?= $i; ?>" <?= $i === $defaultDepth ? 'selected' : ''; ?>><?= $i; ?> Ebenen</option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <label>Nächster Runden-Timer
                        <select name="timer" id="next-timer">
                            <?php foreach ($timerOptions as $seconds): ?>
                                <option value="<?= $seconds; ?>" <?= $seconds === $defaultTimer ? 'selected' : ''; ?>><?= $seconds === 0 ? 'Kein Timer' : ($seconds . ' Sekunden'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button id="next-round" class="primary" type="button">Nächste Runde</button>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <section class="card">
        <h3>Zwischenstand</h3>
        <div id="scoreboard" class="scoreboard muted">Noch keine Punkte gesammelt.</div>
    </section>
    <section class="card">
        <h3>Rundenübersicht</h3>
        <div id="round-history" class="stack"></div>
    </section>
    <section class="card">
        <h3>Fragen hinzufügen</h3>
        <form id="add-question" class="stack">
            <label>Frage<input type="text" name="question" maxlength="80" required></label>
            <label>Option A<input type="text" name="optionA" maxlength="30" required></label>
            <label>Option B<input type="text" name="optionB" maxlength="30" required></label>
            <label><input type="checkbox" name="global" value="1"> Für alle Teams nutzen</label>
            <button type="submit" class="secondary">Frage speichern</button>
        </form>
    </section>
</div>
<script src="assets/js/app.js"></script>
</body>
</html>
