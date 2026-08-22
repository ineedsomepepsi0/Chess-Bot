<?php

/**
 * api.php - session-backed chess game API.
 *
 * Endpoints (all relative to this file, e.g. POST /api.php?action=move):
 *
 *   POST ?action=new_game   body: {difficulty: "beginner"|"medium"|"expert", playerColor: "white"|"black"}
 *   GET  ?action=state
 *   POST ?action=move       body: {from: "e2", to: "e4", promotion?: "q"}
 *   GET  ?action=legal_moves&square=e2
 *   POST ?action=reset
 *
 * State is kept in the PHP session, keyed by session id - no database
 * required. Swap the storage layer for a DB/session-table if you need
 * games to survive across devices or a load-balanced setup without
 * sticky sessions.
 */

declare(strict_types=1);

require_once __DIR__ . '/ChessEngine.php';
require_once __DIR__ . '/ChessAI.php';

header('Content-Type: application/json');
// Demo-friendly CORS. Restrict this to your own origin before shipping.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

session_start();

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function errorOut(string $message, int $status = 400): void
{
    respond(['error' => $message], $status);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** Board as an 8x8 array with row 0 = rank 8 (top), handy for direct rendering. */
function boardForDisplay(Chess $chess): array
{
    $rows = [];
    for ($r = 7; $r >= 0; $r--) $rows[] = $chess->board[$r];
    return $rows;
}

function statusAndWinner(Chess $chess): array
{
    $status = $chess->getGameStatus();
    $winner = null;
    if ($status === 'checkmate') {
        // side to move is the one checkmated, so the other side wins
        $winner = $chess->turn === 'w' ? 'b' : 'w';
    } elseif ($status !== null) {
        $winner = 'draw';
    }
    return [$status, $winner];
}

function buildState(Chess $chess, ?string $difficulty, ?string $playerColor, ?array $lastMove = null, ?int $aiThinkMs = null): array
{
    [$status, $winner] = statusAndWinner($chess);
    $moveLog = array_map(function ($entry) {
        return [
            'san' => $entry['san'],
            'uci' => Chess::moveToUCI($entry['move']),
            'by' => $entry['move']['color'],
        ];
    }, $chess->moveLog);

    return [
        'fen' => $chess->toFEN(),
        'board' => boardForDisplay($chess),
        'turn' => $chess->turn,
        'playerColor' => $playerColor,
        'difficulty' => $difficulty,
        'status' => $status,
        'winner' => $winner,
        'inCheck' => $chess->isInCheck($chess->turn),
        'moveLog' => $moveLog,
        'lastMove' => $lastMove,
        'aiThinkTimeMs' => $aiThinkMs,
    ];
}

function loadGame(): ?array
{
    if (!isset($_SESSION['chess_fen'])) return null;
    $chess = new Chess($_SESSION['chess_fen']);
    // restore richer history so repetition/SAN move-log stay correct across requests
    if (isset($_SESSION['chess_position_history'])) $chess->positionHistory = $_SESSION['chess_position_history'];
    if (isset($_SESSION['chess_move_log'])) $chess->moveLog = $_SESSION['chess_move_log'];
    return [
        'chess' => $chess,
        'difficulty' => $_SESSION['chess_difficulty'] ?? 'medium',
        'playerColor' => $_SESSION['chess_player_color'] ?? 'w',
    ];
}

function saveGame(Chess $chess, string $difficulty, string $playerColor): void
{
    $_SESSION['chess_fen'] = $chess->toFEN();
    $_SESSION['chess_position_history'] = $chess->positionHistory;
    $_SESSION['chess_move_log'] = $chess->moveLog;
    $_SESSION['chess_difficulty'] = $difficulty;
    $_SESSION['chess_player_color'] = $playerColor;
}

/** Plays the AI's move if it's currently the AI's turn and the game isn't over. Returns [move|null, thinkMs|null]. */
function maybePlayAiMove(Chess $chess, string $difficulty, string $playerColor): array
{
    $aiColor = $playerColor === 'w' ? 'b' : 'w';
    if ($chess->turn !== $aiColor || $chess->isGameOver()) return [null, null];

    $ai = new ChessAI();
    $t0 = microtime(true);
    $move = $ai->getMove($chess, $difficulty);
    $thinkMs = (int)round((microtime(true) - $t0) * 1000);
    if ($move !== null) $chess->playMove($move);
    return [$move, $thinkMs];
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'new_game': {
        if ($method !== 'POST') errorOut('new_game requires POST', 405);
        $body = readJsonBody();
        $difficulty = $body['difficulty'] ?? 'medium';
        if (!in_array($difficulty, ['beginner', 'medium', 'expert'], true)) {
            errorOut('difficulty must be beginner, medium, or expert');
        }
        $playerColorRaw = $body['playerColor'] ?? 'white';
        $playerColor = str_starts_with(strtolower($playerColorRaw), 'b') ? 'b' : 'w';

        $chess = new Chess();
        [$aiMove, $thinkMs] = maybePlayAiMove($chess, $difficulty, $playerColor);
        saveGame($chess, $difficulty, $playerColor);

        $lastMove = $aiMove ? ['from' => Chess::rcToSquare(...$aiMove['from']), 'to' => Chess::rcToSquare(...$aiMove['to'])] : null;
        respond(buildState($chess, $difficulty, $playerColor, $lastMove, $thinkMs));
    }

    case 'state': {
        $game = loadGame();
        if ($game === null) errorOut('No active game. Call new_game first.', 404);
        respond(buildState($game['chess'], $game['difficulty'], $game['playerColor']));
    }

    case 'move': {
        if ($method !== 'POST') errorOut('move requires POST', 405);
        $game = loadGame();
        if ($game === null) errorOut('No active game. Call new_game first.', 404);
        ['chess' => $chess, 'difficulty' => $difficulty, 'playerColor' => $playerColor] = $game;

        if ($chess->isGameOver()) errorOut('Game is already over.', 409);
        if ($chess->turn !== $playerColor) errorOut('It is not your turn.', 409);

        $body = readJsonBody();
        $from = $body['from'] ?? null;
        $to = $body['to'] ?? null;
        $promotion = $body['promotion'] ?? null;
        if (!$from || !$to) errorOut('Body must include "from" and "to" squares, e.g. {"from":"e2","to":"e4"}.');

        $uci = strtolower($from) . strtolower($to) . ($promotion ? strtolower($promotion) : '');
        $move = $chess->findMoveByUCI($uci);
        if ($move === null) errorOut("Illegal move: {$from} to {$to}.", 422);

        $chess->playMove($move);
        $playerLastMove = ['from' => $from, 'to' => $to];

        [$aiMove, $thinkMs] = maybePlayAiMove($chess, $difficulty, $playerColor);
        saveGame($chess, $difficulty, $playerColor);

        $lastMove = $aiMove
            ? ['from' => Chess::rcToSquare(...$aiMove['from']), 'to' => Chess::rcToSquare(...$aiMove['to'])]
            : $playerLastMove;

        respond(buildState($chess, $difficulty, $playerColor, $lastMove, $thinkMs));
    }

    case 'legal_moves': {
        $game = loadGame();
        if ($game === null) errorOut('No active game. Call new_game first.', 404);
        $chess = $game['chess'];
        $square = $_GET['square'] ?? '';
        if (!preg_match('/^[a-h][1-8]$/', $square)) errorOut('Provide a valid square, e.g. ?square=e2');

        [$r, $c] = Chess::squareToRC($square);
        $moves = $chess->getLegalMovesFrom($r, $c);
        $dests = array_map(fn($m) => Chess::rcToSquare($m['to'][0], $m['to'][1]), $moves);
        respond(['square' => $square, 'legalMoves' => array_values(array_unique($dests))]);
    }

    case 'reset': {
        if ($method !== 'POST') errorOut('reset requires POST', 405);
        unset($_SESSION['chess_fen'], $_SESSION['chess_position_history'], $_SESSION['chess_move_log'], $_SESSION['chess_difficulty'], $_SESSION['chess_player_color']);
        respond(['ok' => true]);
    }

    default:
        errorOut('Unknown action. Use new_game, state, move, legal_moves, or reset.', 404);
}
