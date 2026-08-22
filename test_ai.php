<?php
require __DIR__ . '/ChessEngine.php';
require __DIR__ . '/ChessAI.php';

function check(bool $cond, string $label): void
{
    echo ($cond ? "OK   " : "FAIL ") . $label . "\n";
}

echo "== Checkmate / stalemate detection ==\n";

// Fool's mate: 1.f3 e5 2.g4 Qh4# - white to move, checkmated
$fools = new Chess('rnb1kbnr/pppp1ppp/8/4p3/6Pq/5P2/PPPPP2P/RNBQKBNR w KQkq - 0 3');
check($fools->isCheckmate('w'), 'Fools mate position is checkmate for white');
check(count($fools->generateLegalMoves('w')) === 0, 'No legal moves for white in fools mate');

// Starting position: definitely not checkmate/stalemate
$start = new Chess();
check(!$start->isCheckmate('w') && !$start->isStalemate('w'), 'Start position is neither checkmate nor stalemate');

// Anastasia-ish back rank mate: black king g8/h8 trapped, white rook delivers mate on e8
$backrank = new Chess('4R1k1/5ppp/8/8/8/8/8/6K1 b - - 0 1');
check($backrank->isCheckmate('b'), 'Back rank mate detected for black');

echo "\n== Self-play smoke test (beginner vs beginner, capped at 80 plies) ==\n";
$ai = new ChessAI();
$chess = new Chess();
$plies = 0;
$start = microtime(true);
while ($plies < 80 && $chess->getGameStatus() === null) {
    $move = $ai->getMove($chess, 'beginner');
    if ($move === null) break;
    $chess->playMove($move);
    $plies++;
}
$elapsed = round(microtime(true) - $start, 2);
echo "Plies played: $plies, status: " . ($chess->getGameStatus() ?? 'ongoing') . ", time: {$elapsed}s\n";
check($plies > 0, 'Beginner self-play produced moves without crashing');

echo "\n== Timing per move by difficulty (from starting position) ==\n";
foreach (['beginner', 'medium', 'expert'] as $diff) {
    $c = new Chess();
    $t0 = microtime(true);
    $m = $ai->getMove($c, $diff);
    $t = round(microtime(true) - $t0, 3);
    echo str_pad($diff, 10) . " move: " . Chess::moveToUCI($m) . "  time: {$t}s  nodes: " . $ai->getNodesSearched() . "\n";
}

echo "\n== Medium vs Expert, 30 plies, verify legality + timing per move ==\n";
$chess2 = new Chess();
$totalTime = 0;
$maxTime = 0;
for ($i = 0; $i < 30 && $chess2->getGameStatus() === null; $i++) {
    $diff = $chess2->turn === 'w' ? 'expert' : 'medium';
    $t0 = microtime(true);
    $move = $ai->getMove($chess2, $diff);
    $t = microtime(true) - $t0;
    $totalTime += $t;
    $maxTime = max($maxTime, $t);
    if ($move === null) break;
    // sanity: move must be in the legal move list
    $legal = $chess2->generateLegalMoves($chess2->turn);
    $found = false;
    foreach ($legal as $lm) if ($lm === $move) { $found = true; break; }
    if (!$found) { echo "ILLEGAL MOVE CHOSEN by AI at ply $i!\n"; break; }
    $chess2->playMove($move);
}
echo "Plies: $i, status: " . ($chess2->getGameStatus() ?? 'ongoing') . "\n";
echo "Total AI time: " . round($totalTime, 2) . "s, max single move: " . round($maxTime, 2) . "s\n";
echo "\nFinal position:\n" . $chess2->printBoard();
echo "FEN: " . $chess2->toFEN() . "\n";

echo "\nMove log (SAN): ";
foreach ($chess2->moveLog as $entry) echo $entry['san'] . ' ';
echo "\n";
