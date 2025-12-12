<?php
// Shared utilities for filesystem-backed storage and session handling.

function load_config(): array
{
    static $config;
    if ($config !== null) {
        return $config;
    }
    $base = __DIR__ . '/../config.sample.php';
    $config = require $base;
    $local = __DIR__ . '/../config.local.php';
    if (file_exists($local)) {
        $config = array_replace_recursive($config, require $local);
    }
    return $config;
}

function ensure_directories(): void
{
    $config = load_config();
    foreach ([
        $config['data_path'],
        $config['teams_path'],
        $config['lobbies_path'],
        $config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions'),
        $config['data_path'] . '/pools',
    ] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

function system_status(): array
{
    $config = load_config();
    $pools = available_question_pools();
    $poolSummaries = [];
    foreach ($pools as $key => $meta) {
        $poolSummaries[] = [
            'label' => ($meta['label'] ?? $key),
            'key' => $key,
            'count' => count(read_json_file($meta['path']) ?? []),
            'path' => $meta['path'],
        ];
    }

    return [
        [
            'label' => 'PHP-Version ≥ 8.0',
            'ok' => PHP_VERSION_ID >= 80000,
            'detail' => PHP_VERSION,
        ],
        [
            'label' => 'Datenverzeichnis beschreibbar',
            'ok' => is_writable($config['data_path']),
            'detail' => $config['data_path'],
        ],
        [
            'label' => 'Teams-Storage beschreibbar',
            'ok' => is_writable($config['teams_path']),
            'detail' => $config['teams_path'],
        ],
        [
            'label' => 'Lobbies-Storage beschreibbar',
            'ok' => is_writable($config['lobbies_path']),
            'detail' => $config['lobbies_path'],
        ],
        [
            'label' => 'Pools-Verzeichnis beschreibbar',
            'ok' => is_writable($config['data_path'] . '/pools'),
            'detail' => $config['data_path'] . '/pools',
        ],
        [
            'label' => 'Custom-Fragen beschreibbar',
            'ok' => is_writable($config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions')),
            'detail' => $config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions'),
        ],
        [
            'label' => 'Fragenpools geladen',
            'ok' => !empty($poolSummaries),
            'detail' => implode(', ', array_map(fn($p) => $p['label'] . ' (' . $p['count'] . ')', $poolSummaries)),
        ],
        [
            'label' => 'OpenAI konfiguriert (optional)',
            'ok' => openai_enabled(),
            'detail' => openai_enabled() ? 'aktiv' : 'nicht gesetzt',
        ],
    ];
}

function random_id(int $length = 12): string
{
    return bin2hex(random_bytes($length));
}

function read_json_file(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $content = file_get_contents($path);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function write_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Cannot open ' . $path);
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function load_team(string $teamId): ?array
{
    $config = load_config();
    $file = $config['teams_path'] . '/' . $teamId . '.json';
    return read_json_file($file);
}

function save_team(array $team): void
{
    $config = load_config();
    $file = $config['teams_path'] . '/' . $team['id'] . '.json';
    write_json_file($file, $team);
}

function find_team_by_identifier(string $identifier): ?array
{
    $team = load_team($identifier);
    if ($team) {
        return $team;
    }

    $config = load_config();
    $files = glob($config['teams_path'] . '/*.json');
    foreach ($files as $file) {
        $data = read_json_file($file);
        if ($data && isset($data['name']) && strcasecmp($data['name'], $identifier) === 0) {
            return $data;
        }
    }

    return null;
}

function load_lobby(string $teamId): array
{
    $config = load_config();
    $file = $config['lobbies_path'] . '/' . $teamId . '.json';
    $data = read_json_file($file);
    if ($data === null) {
        $data = [
            'team_id' => $teamId,
            'lobby_id' => $teamId,
            'players' => [],
            'round_history' => [],
            'game_state' => [
                'phase' => 'LOBBY_WAITING',
                'round_index' => 0,
                'active_player_id' => null,
                'pyramid' => null,
                'answers_by_player' => [],
                'finished' => [],
                'scores' => [],
                'totals' => [],
                'timer_seconds' => 0,
                'started_at' => null,
            ],
        ];
        write_json_file($file, $data);
    }
    if (!isset($data['round_history']) || !is_array($data['round_history'])) {
        $data['round_history'] = [];
    }
    return $data;
}

function save_lobby(array $lobby): void
{
    $config = load_config();
    $file = $config['lobbies_path'] . '/' . $lobby['team_id'] . '.json';
    write_json_file($file, $lobby);
}

function pool_registry_path(): string
{
    $config = load_config();
    return $config['data_path'] . '/pools/registry.json';
}

function load_pool_registry(): array
{
    $path = pool_registry_path();
    $data = read_json_file($path);
    return is_array($data) ? $data : [];
}

function save_pool_registry(array $registry): void
{
    write_json_file(pool_registry_path(), $registry);
}

function available_question_pools(): array
{
    $config = load_config();
    $defaults = $config['question_pools'] ?? [
        'basic' => [
            'label' => 'Standard',
            'path' => __DIR__ . '/../questions/questions.json',
            'default' => true,
        ],
    ];
    $registry = load_pool_registry();
    return array_replace($defaults, $registry);
}

function load_custom_questions(?string $teamId = null): array
{
    $config = load_config();
    $dir = $config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions');
    $pool = [];
    $files = [];
    if (is_dir($dir)) {
        $files = glob($dir . '/*.json');
    }
    foreach ($files as $file) {
        $filename = basename($file);
        if ($filename === 'global.json' || ($teamId && $filename === $teamId . '.json')) {
            $data = read_json_file($file);
            if (is_array($data)) {
                $pool = array_merge($pool, $data);
            }
        }
    }
    return $pool;
}

function load_questions_pool(?array $poolKeys = null, ?string $teamId = null): array
{
    $pools = available_question_pools();
    if ($poolKeys === null || empty($poolKeys)) {
        $poolKeys = array_keys(array_filter($pools, fn($p) => !empty($p['default'])));
    }
    $cards = [];
    foreach ($poolKeys as $key) {
        if (!isset($pools[$key])) {
            continue;
        }
        $data = read_json_file($pools[$key]['path']) ?? [];
        if (is_array($data)) {
            $cards = array_merge($cards, $data);
        }
    }
    $cards = array_merge($cards, load_custom_questions($teamId));
    return array_values(array_filter($cards, function ($card) {
        return isset($card['question'], $card['optionA'], $card['optionB']);
    }));
}

function find_player(array $lobby, string $playerId): ?array
{
    foreach ($lobby['players'] as $p) {
        if ($p['player_id'] === $playerId) {
            return $p;
        }
    }
    return null;
}

function ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function require_player(string $teamId): array
{
    ensure_session();
    if (empty($_SESSION['player_id']) || empty($_SESSION['team_id']) || $_SESSION['team_id'] !== $teamId) {
        http_response_code(403);
        echo json_encode(['error' => 'Not joined']);
        exit;
    }
    $lobby = load_lobby($teamId);
    $player = find_player($lobby, $_SESSION['player_id']);
    if (!$player) {
        http_response_code(403);
        echo json_encode(['error' => 'Unknown player']);
        exit;
    }
    return $player;
}

function build_pyramid_from_cards(array $cards, int $depth): array
{
    $nodeCount = (int)(($depth * ($depth + 1)) / 2);
    if ($nodeCount > count($cards)) {
        throw new RuntimeException('Not enough cards for requested depth');
    }
    $selected = [];
    shuffle($cards);
    foreach ($cards as $card) {
        if (count($selected) >= $nodeCount) {
            break;
        }
        $card['id'] = count($selected);
        $selected[] = $card;
    }
    return $selected;
}

function max_depth_for_cards(array $cards, int $cap = 6): int
{
    $count = count($cards);
    $depth = 0;
    while ($depth < $cap && ((($depth + 1) * ($depth + 2)) / 2) <= $count) {
        $depth++;
    }
    return max(1, $depth);
}

function validate_path(array $path, int $depth): bool
{
    return count($path) === $depth && !array_diff($path, ['L', 'R']);
}

function compute_scores(array $lobby): array
{
    $state = $lobby['game_state'];
    $depth = $state['pyramid']['depth'] ?? load_config()['default_depth'];
    $truthPath = $state['answers_by_player'][$state['active_player_id']] ?? [];
    $scores = [];
    foreach ($lobby['players'] as $player) {
        $playerId = $player['player_id'];
        if ($playerId === $state['active_player_id']) {
            continue;
        }
        $path = $state['answers_by_player'][$playerId] ?? [];
        $matches = 0;
        for ($i = 0; $i < $depth; $i++) {
            if (($truthPath[$i] ?? null) === ($path[$i] ?? null)) {
                $matches++;
            }
        }
        $leafMatch = $truthPath === $path;
        $scores[$playerId] = [
            'matches' => $matches,
            'leaf' => $leafMatch,
            'points' => $matches + ($leafMatch ? $depth : 0),
        ];
    }
    return $scores;
}

function append_round_history(array &$lobby): void
{
    if (!isset($lobby['round_history']) || !is_array($lobby['round_history'])) {
        $lobby['round_history'] = [];
    }
    $state = $lobby['game_state'] ?? [];
    if (empty($state['pyramid'])) {
        return;
    }
    $roundIndex = $state['round_index'] ?? 0;
    $startedAt = $state['started_at'] ?? null;
    foreach ($lobby['round_history'] as $entry) {
        if (($entry['round_index'] ?? null) === $roundIndex && ($entry['started_at'] ?? null) === $startedAt) {
            return;
        }
    }
    $lobby['round_history'][] = [
        'round_index' => $roundIndex,
        'active_player_id' => $state['active_player_id'] ?? null,
        'pyramid' => $state['pyramid'] ?? null,
        'answers_by_player' => $state['answers_by_player'] ?? [],
        'finished' => $state['finished'] ?? [],
        'scores' => $state['scores'] ?? [],
        'phase' => 'ROUND_REVEAL',
        'started_at' => $startedAt,
        'finished_at' => time(),
    ];
}

function finalize_round(array &$lobby): void
{
    $lobby['game_state']['scores'] = compute_scores($lobby);
    if (!isset($lobby['game_state']['totals'])) {
        $lobby['game_state']['totals'] = [];
    }
    foreach ($lobby['game_state']['scores'] as $pid => $score) {
        $lobby['game_state']['totals'][$pid] = ($lobby['game_state']['totals'][$pid] ?? 0) + ($score['points'] ?? 0);
    }
    append_round_history($lobby);
    $lobby['game_state']['phase'] = 'ROUND_REVEAL';
}

function current_player_rotation(array $lobby): ?string
{
    $players = $lobby['players'];
    if (empty($players)) {
        return null;
    }
    $index = $lobby['game_state']['round_index'] % count($players);
    return $players[$index]['player_id'];
}

function sanitize_text(string $value, int $max): string
{
    $value = trim($value);
    $value = mb_substr($value, 0, $max);
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sanitize_pool_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = preg_replace('/[^a-z0-9_-]/', '-', $key);
    $key = trim($key, '-_');
    return $key ?: ('pool-' . substr(random_id(3), 0, 6));
}

function apply_round_timeout(array &$lobby): void
{
    $state = &$lobby['game_state'];
    if (($state['phase'] ?? '') !== 'ROUND_ACTIVE') {
        return;
    }
    $timer = (int)($state['timer_seconds'] ?? 0);
    $startedAt = (int)($state['started_at'] ?? 0);
    if ($timer <= 0 || $startedAt <= 0) {
        return;
    }
    if (time() >= ($startedAt + $timer)) {
        finalize_round($lobby);
        save_lobby($lobby);
    }
}

function openai_enabled(): bool
{
    $config = load_config();
    return !empty($config['openai']['api_key']);
}

function generate_cards_with_openai(string $prompt, int $maxCards = 7): array
{
    if (!openai_enabled()) {
        throw new RuntimeException('OpenAI deaktiviert');
    }
    $config = load_config();
    $prompt = trim($prompt);
    if ($prompt === '') {
        throw new RuntimeException('Prompt fehlt');
    }
    $rateFile = $config['data_path'] . '/openai_rate.lock';
    $last = file_exists($rateFile) ? (int)file_get_contents($rateFile) : 0;
    if (time() - $last < ($config['openai']['rate_limit_seconds'] ?? 30)) {
        throw new RuntimeException('Bitte kurz warten, Rate-Limit aktiv');
    }
    file_put_contents($rateFile, (string) time());

    $systemPrompt = 'Du erzeugst JSON-Arrays für ein Entscheidungs-Spiel. '
        . 'Antwort ausschließlich mit JSON-Array von Objekten: [{"question":"","optionA":"","optionB":""}]. '
        . 'Jede Frage max. 80 Zeichen, Optionen max. 30 Zeichen, deutsch, alltagstauglich und neutral. '
        . 'Liefere höchstens ' . $maxCards . ' Einträge. Thema: ' . $prompt;

    $payload = [
        'model' => $config['openai']['model'],
        'messages' => [
            ['role' => 'system', 'content' => 'Du bist ein Generator für kurze Entscheidungsfragen.'],
            ['role' => 'user', 'content' => $systemPrompt],
        ],
        'temperature' => 0.7,
        'max_tokens' => 400,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['openai']['api_key'],
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException('OpenAI Request fehlgeschlagen');
    }
    $json = json_decode($response, true);
    $text = $json['choices'][0]['message']['content'] ?? '';
    $cards = json_decode($text, true);
    $valid = [];
    if (is_array($cards)) {
        foreach ($cards as $card) {
            if (isset($card['question'], $card['optionA'], $card['optionB'])) {
                $valid[] = [
                    'question' => sanitize_text($card['question'], 80),
                    'optionA' => sanitize_text($card['optionA'], 30),
                    'optionB' => sanitize_text($card['optionB'], 30),
                ];
            }
        }
    }
    if (empty($valid)) {
        throw new RuntimeException('Keine validen Karten generiert');
    }
    return array_slice($valid, 0, $maxCards);
}

function list_custom_question_files(): array
{
    $config = load_config();
    $dir = $config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions');
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    foreach (glob($dir . '/*.json') as $file) {
        $files[] = $file;
    }
    return $files;
}

function append_to_base_pool(array $cards): void
{
    $config = load_config();
    $basePath = $config['questions_path'];
    $base = read_json_file($basePath) ?? [];
    foreach ($cards as $card) {
        if (isset($card['question'], $card['optionA'], $card['optionB'])) {
            $base[] = $card;
        }
    }
    write_json_file($basePath, $base);
}

function save_question_pool(string $path, array $cards): void
{
    $clean = [];
    foreach ($cards as $card) {
        if (!isset($card['question'], $card['optionA'], $card['optionB'])) {
            continue;
        }
        $clean[] = [
            'question' => sanitize_text($card['question'], 80),
            'optionA' => sanitize_text($card['optionA'], 30),
            'optionB' => sanitize_text($card['optionB'], 30),
        ];
    }
    write_json_file($path, $clean);
}
