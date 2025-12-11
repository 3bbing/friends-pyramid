<?php
require __DIR__ . '/../includes/utils.php';
ensure_directories();
header('Content-Type: application/json');
ensure_session();

$teamId = $_POST['team_id'] ?? '';
$action = $_POST['action'] ?? '';
if ($teamId === '' || $action === '') {
    echo json_encode(['error' => 'missing params']);
    exit;
}

$lobby = load_lobby($teamId);
$player = require_player($teamId);
$config = load_config();
apply_round_timeout($lobby);

function sanitize_timer($value, $default = 0): int
{
    $seconds = max(0, (int)$value);
    $allowed = load_config()['round_timer_options'] ?? [0, 60, 120, 180];
    if (!in_array($seconds, $allowed, true)) {
        $seconds = $default;
    }
    return $seconds;
}

function sanitize_pool_selection($input): array
{
    $pools = available_question_pools();
    $keys = [];
    if (is_array($input)) {
        $keys = array_values(array_map('strval', $input));
    } elseif (is_string($input) && $input !== '') {
        $keys = [$input];
    }
    $valid = array_values(array_intersect($keys, array_keys($pools)));
    if (empty($valid)) {
        $valid = array_keys(array_filter($pools, fn($p) => !empty($p['default'])));
    }
    if (empty($valid)) {
        $valid = ['basic'];
    }
    return $valid;
}

function save_custom_question(string $teamId, array $card, bool $global): void
{
    $config = load_config();
    $dir = $config['custom_questions_path'] ?? ($config['data_path'] . '/custom_questions');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $global ? $dir . '/global.json' : $dir . '/' . $teamId . '.json';
    $existing = read_json_file($file) ?? [];
    $existing[] = $card;
    write_json_file($file, $existing);
}

switch ($action) {
    case 'start_game':
        if (!$player['is_host']) {
            echo json_encode(['error' => 'Only host']);
            exit;
        }
        $depth = max(2, (int)($_POST['depth'] ?? $config['default_depth']));
        $timer = sanitize_timer($_POST['timer'] ?? $_POST['timer_seconds'] ?? ($config['default_timer_seconds'] ?? 0), $config['default_timer_seconds'] ?? 0);
        $selectedPools = sanitize_pool_selection($_POST['pools'] ?? []);
        $cards = load_questions_pool($selectedPools, $teamId);
        $maxDepth = max_depth_for_cards($cards);
        if ($depth > $maxDepth) {
            echo json_encode(['error' => 'Zu wenige Karten für diese Tiefe (max. ' . $maxDepth . ')']);
            exit;
        }
        $pyramid = build_pyramid_from_cards($cards, $depth);
        $lobby['game_state'] = [
            'phase' => 'ROUND_ACTIVE',
            'round_index' => 0,
            'active_player_id' => current_player_rotation($lobby),
            'pyramid' => ['depth' => $depth, 'nodes' => $pyramid],
            'selected_pools' => $selectedPools,
            'answers_by_player' => [],
            'finished' => [],
            'scores' => [],
            'totals' => [],
            'timer_seconds' => $timer,
            'started_at' => time(),
        ];
        save_lobby($lobby);
        echo json_encode(['ok' => true, 'lobby' => $lobby]);
        break;
    case 'submit_answers':
        if ($lobby['game_state']['phase'] !== 'ROUND_ACTIVE') {
            echo json_encode(['error' => 'No active round']);
            exit;
        }
        $path = str_split(strtoupper($_POST['path'] ?? ''));
        $depth = $lobby['game_state']['pyramid']['depth'] ?? $config['default_depth'];
        $path = array_slice(array_values(array_filter($path, function ($c) { return in_array($c, ['L', 'R'], true); })), 0, $depth);
        if (!validate_path($path, $depth)) {
            echo json_encode(['error' => 'Invalid path']);
            exit;
        }
        $lobby['game_state']['answers_by_player'][$player['player_id']] = $path;
        $lobby['game_state']['finished'][$player['player_id']] = true;
        $allFinished = count($lobby['game_state']['finished']) === count($lobby['players']);
        if ($allFinished) {
            finalize_round($lobby);
        }
        save_lobby($lobby);
        echo json_encode(['ok' => true, 'lobby' => $lobby]);
        break;
    case 'force_reveal':
        if (!$player['is_host']) {
            echo json_encode(['error' => 'Only host']);
            exit;
        }
        if ($lobby['game_state']['phase'] !== 'ROUND_ACTIVE') {
            echo json_encode(['error' => 'No active round']);
            exit;
        }
        finalize_round($lobby);
        save_lobby($lobby);
        echo json_encode(['ok' => true, 'lobby' => $lobby]);
        break;
    case 'next_round':
        if (!$player['is_host']) {
            echo json_encode(['error' => 'Only host']);
            exit;
        }
        $lobby['game_state']['round_index'] = ($lobby['game_state']['round_index'] ?? 0) + 1;
        $lobby['game_state']['phase'] = 'ROUND_ACTIVE';
        $lobby['game_state']['active_player_id'] = current_player_rotation($lobby);
        $depth = max(2, (int)($_POST['depth'] ?? $config['default_depth']));
        $timer = sanitize_timer($_POST['timer'] ?? $_POST['timer_seconds'] ?? ($config['default_timer_seconds'] ?? 0), $config['default_timer_seconds'] ?? 0);
        $selectedPools = sanitize_pool_selection($_POST['pools'] ?? ($lobby['game_state']['selected_pools'] ?? []));
        $cards = load_questions_pool($selectedPools, $teamId);
        $maxDepth = max_depth_for_cards($cards);
        if ($depth > $maxDepth) {
            echo json_encode(['error' => 'Zu wenige Karten für diese Tiefe (max. ' . $maxDepth . ')']);
            exit;
        }
        $pyramid = build_pyramid_from_cards($cards, $depth);
        $lobby['game_state']['pyramid'] = ['depth' => $depth, 'nodes' => $pyramid];
        $lobby['game_state']['selected_pools'] = $selectedPools;
        $lobby['game_state']['answers_by_player'] = [];
        $lobby['game_state']['finished'] = [];
        $lobby['game_state']['scores'] = [];
        $lobby['game_state']['timer_seconds'] = $timer;
        $lobby['game_state']['started_at'] = time();
        save_lobby($lobby);
        echo json_encode(['ok' => true, 'lobby' => $lobby]);
        break;
    case 'add_question':
        $question = sanitize_text($_POST['question'] ?? '', 80);
        $optionA = sanitize_text($_POST['optionA'] ?? '', 30);
        $optionB = sanitize_text($_POST['optionB'] ?? '', 30);
        $global = isset($_POST['global']);
        if ($question === '' || $optionA === '' || $optionB === '') {
            echo json_encode(['error' => 'Missing fields']);
            exit;
        }
        $card = ['question' => $question, 'optionA' => $optionA, 'optionB' => $optionB];
        save_custom_question($teamId, $card, $global);
        echo json_encode(['ok' => true]);
        break;
    default:
        echo json_encode(['error' => 'unknown action']);
}
