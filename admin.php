<?php
require __DIR__ . '/includes/utils.php';
ensure_directories();
ensure_session();
$config = load_config();
$authorized = $_SESSION['is_admin'] ?? false;
$message = '';
$error = '';
$generated = [];
$selectedPoolKey = $_GET['pool'] ?? ($_POST['pool'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $password = $_POST['admin_password'] ?? '';
    $passOk = $password === ($config['admin_password'] ?? '');

    if (!$authorized && $action === 'login') {
        if ($passOk) {
            $authorized = true;
            $_SESSION['is_admin'] = true;
            $message = 'Admin-Login erfolgreich.';
        } else {
            $error = 'Admin-Passwort falsch';
        }
    }

    if ($authorized && $action && $action !== 'login') {
        if (!$passOk) {
            $error = 'Admin-Passwort falsch';
        } else {
            try {
                switch ($action) {
                    case 'promote_pool':
                        $source = $_POST['source'] ?? '';
                        if ($source && file_exists($source)) {
                            $cards = read_json_file($source) ?? [];
                            append_to_base_pool($cards);
                            $message = 'Fragen übernommen.';
                        }
                        break;
                    case 'promote_single':
                        $source = $_POST['source'] ?? '';
                        $index = (int)($_POST['index'] ?? -1);
                        if ($source && file_exists($source)) {
                            $cards = read_json_file($source) ?? [];
                            if (isset($cards[$index])) {
                                append_to_base_pool([$cards[$index]]);
                                $message = 'Frage übernommen.';
                            }
                        }
                        break;
                    case 'add_pool':
                        $rawKey = $_POST['pool_key'] ?? '';
                        $label = trim($_POST['label'] ?? 'Neuer Katalog');
                        $key = sanitize_pool_key($rawKey ?: $label);
                        $registry = load_pool_registry();
                        $existing = available_question_pools();
                        if (isset($existing[$key])) {
                            throw new RuntimeException('Schlüssel bereits vergeben.');
                        }
                        $dir = $config['data_path'] . '/pools';
                        if (!is_dir($dir)) {
                            mkdir($dir, 0775, true);
                        }
                        $path = $dir . '/' . $key . '.json';
                        save_question_pool($path, []);
                        $registry[$key] = [
                            'label' => $label ?: 'Neuer Katalog',
                            'path' => $path,
                            'default' => false,
                        ];
                        save_pool_registry($registry);
                        $selectedPoolKey = $key;
                        $message = 'Neuer Fragenkatalog angelegt.';
                        break;
                    case 'generate_pool':
                        $label = trim($_POST['label'] ?? '');
                        $prompt = trim($_POST['prompt'] ?? '');
                        $rawKey = $_POST['pool_key'] ?? '';
                        $key = sanitize_pool_key($rawKey ?: ($label ?: $prompt));
                        $registry = load_pool_registry();
                        $existing = available_question_pools();
                        if (isset($existing[$key])) {
                            throw new RuntimeException('Schlüssel bereits vergeben.');
                        }
                        $cards = generate_cards_with_openai($prompt, 15);
                        $dir = $config['data_path'] . '/pools';
                        if (!is_dir($dir)) {
                            mkdir($dir, 0775, true);
                        }
                        $path = $dir . '/' . $key . '.json';
                        save_question_pool($path, $cards);
                        $registry[$key] = [
                            'label' => $label ?: ('KI: ' . $prompt),
                            'path' => $path,
                            'default' => false,
                        ];
                        save_pool_registry($registry);
                        $selectedPoolKey = $key;
                        $generated = $cards;
                        $message = 'Neuer Katalog per KI generiert (' . count($cards) . ' Karten).';
                        break;
                    case 'generate_cards':
                        $poolKey = $_POST['pool'] ?? '';
                        $prompt = trim($_POST['prompt'] ?? '');
                        $pools = available_question_pools();
                        if (!isset($pools[$poolKey])) {
                            throw new RuntimeException('Unbekannter Pool.');
                        }
                        $cards = read_json_file($pools[$poolKey]['path']) ?? [];
                        $newCards = generate_cards_with_openai($prompt);
                        $cards = array_merge($cards, $newCards);
                        save_question_pool($pools[$poolKey]['path'], $cards);
                        $generated = $newCards;
                        $selectedPoolKey = $poolKey;
                        $message = 'KI-Karten hinzugefügt (' . count($newCards) . ').';
                        break;
                    case 'add_card':
                        $poolKey = $_POST['pool'] ?? '';
                        $pools = available_question_pools();
                        if (!isset($pools[$poolKey])) {
                            throw new RuntimeException('Unbekannter Pool.');
                        }
                        $cards = read_json_file($pools[$poolKey]['path']) ?? [];
                        $cards[] = [
                            'question' => sanitize_text($_POST['question'] ?? '', 80),
                            'optionA' => sanitize_text($_POST['optionA'] ?? '', 30),
                            'optionB' => sanitize_text($_POST['optionB'] ?? '', 30),
                        ];
                        save_question_pool($pools[$poolKey]['path'], $cards);
                        $selectedPoolKey = $poolKey;
                        $message = 'Frage gespeichert.';
                        break;
                    case 'update_card':
                        $poolKey = $_POST['pool'] ?? '';
                        $index = (int)($_POST['index'] ?? -1);
                        $pools = available_question_pools();
                        if (!isset($pools[$poolKey])) {
                            throw new RuntimeException('Unbekannter Pool.');
                        }
                        $cards = read_json_file($pools[$poolKey]['path']) ?? [];
                        if (!isset($cards[$index])) {
                            throw new RuntimeException('Index ungültig.');
                        }
                        $cards[$index] = [
                            'question' => sanitize_text($_POST['question'] ?? '', 80),
                            'optionA' => sanitize_text($_POST['optionA'] ?? '', 30),
                            'optionB' => sanitize_text($_POST['optionB'] ?? '', 30),
                        ];
                        save_question_pool($pools[$poolKey]['path'], $cards);
                        $selectedPoolKey = $poolKey;
                        $message = 'Frage aktualisiert.';
                        break;
                    case 'delete_card':
                        $poolKey = $_POST['pool'] ?? '';
                        $index = (int)($_POST['index'] ?? -1);
                        $pools = available_question_pools();
                        if (!isset($pools[$poolKey])) {
                            throw new RuntimeException('Unbekannter Pool.');
                        }
                        $cards = read_json_file($pools[$poolKey]['path']) ?? [];
                        if (!isset($cards[$index])) {
                            throw new RuntimeException('Index ungültig.');
                        }
                        unset($cards[$index]);
                        $cards = array_values($cards);
                        save_question_pool($pools[$poolKey]['path'], $cards);
                        $selectedPoolKey = $poolKey;
                        $message = 'Frage gelöscht.';
                        break;
                }
            } catch (Throwable $t) {
                $error = $t->getMessage();
            }
        }
    }
}

$pools = available_question_pools();
$poolCounts = [];
foreach ($pools as $key => $meta) {
    $poolCounts[$key] = count(read_json_file($meta['path']) ?? []);
}
if ($selectedPoolKey === '' || !isset($pools[$selectedPoolKey])) {
    $selectedPoolKey = array_key_first($pools);
}
$selectedPool = $selectedPoolKey && isset($pools[$selectedPoolKey]) ? $pools[$selectedPoolKey] : null;
$selectedCards = $selectedPool ? (read_json_file($selectedPool['path']) ?? []) : [];
$customFiles = list_custom_question_files();
$statusChecks = system_status();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin – Fragen</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <style>
        .pool-card { border: 1px solid #ddd; padding: 1rem; margin-bottom: 1rem; }
        .pool-card h3 { margin-top: 0; }
        .pool-card form { margin-top: .5rem; }
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: .75rem; }
        .inline-form { display:flex; gap:.5rem; align-items:center; }
    </style>
</head>
<body>
<div class="page">
    <header><h1>Admin: Fragen verwalten</h1></header>
    <?php if ($message): ?><p class="pill success"><?= htmlspecialchars($message); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="pill error"><?= htmlspecialchars($error); ?></p><?php endif; ?>

    <?php if (!$authorized): ?>
        <div class="card">
            <form method="post" class="stack">
                <input type="hidden" name="action" value="login">
                <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                <button type="submit" class="primary">Anmelden</button>
            </form>
        </div>
    <?php else: ?>
        <div class="card">
            <p class="muted">Bearbeite Fragenkataloge, erstelle neue Pools (manuell oder via KI) und verschiebe Custom-Pools in den Basis-Pool.</p>
        </div>

        <section class="card stack">
            <h2>Systemstatus</h2>
            <ul class="stack" style="list-style:none; padding-left:0;">
                <?php foreach ($statusChecks as $check): ?>
                    <li>
                        <span class="pill <?= $check['ok'] ? 'success' : 'error'; ?>"><?= htmlspecialchars($check['label']); ?></span>
                        <span class="muted"><?= htmlspecialchars($check['detail']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <section class="card stack">
            <h2>Katalog auswählen</h2>
            <form method="get" class="inline-form">
                <label for="pool">Pool</label>
                <select name="pool" id="pool">
                    <?php foreach ($pools as $key => $meta): ?>
                        <option value="<?= htmlspecialchars($key); ?>" <?= $key === $selectedPoolKey ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($meta['label'] ?? $key); ?> (<?= $poolCounts[$key] ?? 0; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="secondary">Öffnen</button>
            </form>
            <?php if ($selectedPool): ?>
                <p class="muted">Pfad: <?= htmlspecialchars($selectedPool['path']); ?></p>
            <?php endif; ?>
        </section>

        <?php if ($selectedPool): ?>
            <section class="card stack">
                <h2>Karten bearbeiten (<?= $poolCounts[$selectedPoolKey] ?? 0; ?>)</h2>
                <?php if (empty($selectedCards)): ?>
                    <p class="muted">Noch keine Karten vorhanden.</p>
                <?php else: ?>
                    <div class="cards-grid">
                        <?php foreach ($selectedCards as $idx => $card): ?>
                            <div class="card">
                                <form method="post" class="stack">
                                    <input type="hidden" name="pool" value="<?= htmlspecialchars($selectedPoolKey); ?>">
                                    <input type="hidden" name="index" value="<?= $idx; ?>">
                                    <label>Frage<input type="text" name="question" value="<?= htmlspecialchars($card['question'] ?? ''); ?>" maxlength="80" required></label>
                                    <label>Option A<input type="text" name="optionA" value="<?= htmlspecialchars($card['optionA'] ?? ''); ?>" maxlength="30" required></label>
                                    <label>Option B<input type="text" name="optionB" value="<?= htmlspecialchars($card['optionB'] ?? ''); ?>" maxlength="30" required></label>
                                    <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                                    <div class="inline">
                                        <button type="submit" name="action" value="update_card" class="secondary">Speichern</button>
                                        <button type="submit" name="action" value="delete_card" class="danger" onclick="return confirm('Wirklich löschen?');">Löschen</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <h3>Neue Karte hinzufügen</h3>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="add_card">
                    <input type="hidden" name="pool" value="<?= htmlspecialchars($selectedPoolKey); ?>">
                    <label>Frage<input type="text" name="question" maxlength="80" required></label>
                    <label>Option A<input type="text" name="optionA" maxlength="30" required></label>
                    <label>Option B<input type="text" name="optionB" maxlength="30" required></label>
                    <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                    <button type="submit" class="primary">Speichern</button>
                </form>
            </section>

            <section class="card stack">
                <h2>KI-Generator</h2>
                <?php if (!openai_enabled()): ?>
                    <p class="muted">OpenAI ist deaktiviert (API-Key fehlt).</p>
                <?php else: ?>
                    <form method="post" class="stack">
                        <input type="hidden" name="action" value="generate_cards">
                        <input type="hidden" name="pool" value="<?= htmlspecialchars($selectedPoolKey); ?>">
                        <label>Prompt / Thema<textarea name="prompt" rows="3" maxlength="240" required></textarea></label>
                        <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                        <button type="submit" class="secondary">Karten für aktuellen Pool erzeugen</button>
                    </form>
                <?php endif; ?>
                <?php if ($generated): ?>
                    <details open>
                        <summary>Letzte KI-Ausgabe (<?= count($generated); ?>)</summary>
                        <ul>
                            <?php foreach ($generated as $card): ?>
                                <li><?= htmlspecialchars($card['question']); ?> — <?= htmlspecialchars($card['optionA']); ?> / <?= htmlspecialchars($card['optionB']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card stack">
            <h2>Neuen Katalog anlegen</h2>
            <form method="post" class="stack">
                <input type="hidden" name="action" value="add_pool">
                <label>Schlüssel (optional, a-z0-9-_)<input type="text" name="pool_key" maxlength="40"></label>
                <label>Label / Anzeige-Name<input type="text" name="label" maxlength="80"></label>
                <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                <button type="submit" class="secondary">Leeren Katalog anlegen</button>
            </form>
            <?php if (openai_enabled()): ?>
                <h3>Vollständigen Katalog per KI generieren</h3>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="generate_pool">
                    <label>Schlüssel (optional)<input type="text" name="pool_key" maxlength="40"></label>
                    <label>Label (optional)<input type="text" name="label" maxlength="80"></label>
                    <label>Prompt / Thema<textarea name="prompt" rows="3" maxlength="240" required></textarea></label>
                    <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                    <button type="submit" class="primary">Neuen KI-Katalog erzeugen</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="card stack">
            <h2>Custom-Pools in Basis übernehmen</h2>
            <?php if (empty($customFiles)): ?>
                <p class="muted">Keine Custom-Pools gefunden.</p>
            <?php else: ?>
                <?php foreach ($customFiles as $file): $cards = read_json_file($file) ?? []; ?>
                    <div class="pool-card">
                        <h3><?= htmlspecialchars(basename($file)); ?> (<?= count($cards); ?>)</h3>
                        <form method="post">
                            <input type="hidden" name="action" value="promote_pool">
                            <input type="hidden" name="source" value="<?= htmlspecialchars($file); ?>">
                            <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                            <button type="submit" class="secondary">Alle in Basis-Pool kopieren</button>
                        </form>
                        <?php if ($cards): ?>
                            <details>
                                <summary>Einzeln kopieren</summary>
                                <?php foreach ($cards as $idx => $card): ?>
                                    <div class="card">
                                        <strong><?= htmlspecialchars($card['question'] ?? ''); ?></strong><br>
                                        <small><?= htmlspecialchars($card['optionA'] ?? ''); ?> / <?= htmlspecialchars($card['optionB'] ?? ''); ?></small>
                                        <form method="post" class="inline">
                                            <input type="hidden" name="action" value="promote_single">
                                            <input type="hidden" name="source" value="<?= htmlspecialchars($file); ?>">
                                            <input type="hidden" name="index" value="<?= $idx; ?>">
                                            <label>Admin-Passwort<input type="password" name="admin_password" required></label>
                                            <button type="submit" class="secondary">Übernehmen</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
