<?php
require __DIR__ . '/ChessEngine.php';

function perft(Chess $chess, int $depth): int
{
    if ($depth === 0) return 1;
    $moves = $chess->generateLegalMoves($chess->turn);
    if ($depth === 1) return count($moves);
    $nodes = 0;
    foreach ($moves as $move) {
        $undo = $chess->makeMove($move);
        $nodes += perft($chess, $depth - 1);
        $chess->undoMove($move, $undo);
    }
    return $nodes;
}

// Known correct perft values from the standard starting position
$expected = [1 => 20, 2 => 400, 3 => 8902, 4 => 197281];

$chess = new Chess();
foreach ($expected as $depth => $want) {
    $start = microtime(true);
    $got = perft($chess, $depth);
    $t = round(microtime(true) - $start, 2);
    $status = $got === $want ? 'OK' : 'FAIL';
    echo "perft($depth) = $got (expected $want) [$status] {$t}s\n";
}

// Kiwipete position - a well-known perft stress test covering castling,
// en passant, promotions, and pins simultaneously.
echo "\n-- Kiwipete position --\n";
$kiwi = new Chess('r3k2r/p1ppqpb1/bn2pnp1/3PN3/1p2P3/2N2Q1p/PPPBBPPP/R3K2R w KQkq - 0 1');
$kiwiExpected = [1 => 48, 2 => 2039, 3 => 97862];
foreach ($kiwiExpected as $depth => $want) {
    $start = microtime(true);
    $got = perft($kiwi, $depth);
    $t = round(microtime(true) - $start, 2);
    $status = $got === $want ? 'OK' : 'FAIL';
    echo "perft($depth) = $got (expected $want) [$status] {$t}s\n";
}

// Position 3 - good for en passant / promotion edge cases
echo "\n-- Position 3 (ep/promotion edge cases) --\n";
$pos3 = new Chess('8/2p5/3p4/KP5r/1R3p1k/8/4P1P1/8 w - - 0 1');
$pos3Expected = [1 => 14, 2 => 191, 3 => 2812, 4 => 43238];
foreach ($pos3Expected as $depth => $want) {
    $start = microtime(true);
    $got = perft($pos3, $depth);
    $t = round(microtime(true) - $start, 2);
    $status = $got === $want ? 'OK' : 'FAIL';
    echo "perft($depth) = $got (expected $want) [$status] {$t}s\n";
}
