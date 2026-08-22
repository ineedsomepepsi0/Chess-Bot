<?php

require_once __DIR__ . '/ChessEngine.php';

/**
 * ChessAI.php
 *
 * Three difficulty tiers on top of the Chess rules engine:
 *
 *   beginner - mostly random legal moves, occasionally grabs a free capture.
 *              Makes real blunders (hangs pieces, ignores threats). Roughly
 *              intended to feel like a new player, not to hit an ELO target.
 *
 *   medium   - depth-limited negamax with alpha-beta (depth ~3), material +
 *              piece-square evaluation, no quiescence search, so it still
 *              misses deeper tactics and can walk into 3-4 ply combinations.
 *
 *   expert   - iterative deepening negamax with alpha-beta (up to depth 5),
 *              move ordering (captures first via MVV-LVA), and a quiescence
 *              search on captures to avoid the horizon effect. This is a
 *              plain-PHP engine, not a bitboard engine like Stockfish -
 *              expect club-level play (rough estimate 1600-1900), not
 *              engine-level play.
 */
class ChessAI
{
    const MATE_SCORE = 1000000;

    private array $pieceValues = ['P' => 100, 'N' => 320, 'B' => 330, 'R' => 500, 'Q' => 900, 'K' => 0];

    /** Piece-square tables, indexed [rank 0..7 = rank1..rank8][file 0..7 = a..h], white's perspective. */
    private array $pst;

    private float $deadline = 0;
    private int $nodesSearched = 0;

    public function __construct()
    {
        $this->pst = [
            'P' => [
                [  0,   0,   0,   0,   0,   0,   0,   0],
                [  5,  10,  10, -20, -20,  10,  10,   5],
                [  5,  -5, -10,   0,   0, -10,  -5,   5],
                [  0,   0,   0,  20,  20,   0,   0,   0],
                [  5,   5,  10,  25,  25,  10,   5,   5],
                [ 10,  10,  20,  30,  30,  20,  10,  10],
                [ 50,  50,  50,  50,  50,  50,  50,  50],
                [  0,   0,   0,   0,   0,   0,   0,   0],
            ],
            'N' => [
                [-50, -40, -30, -30, -30, -30, -40, -50],
                [-40, -20,   0,   5,   5,   0, -20, -40],
                [-30,   5,  10,  15,  15,  10,   5, -30],
                [-30,   0,  15,  20,  20,  15,   0, -30],
                [-30,   5,  15,  20,  20,  15,   5, -30],
                [-30,   0,  10,  15,  15,  10,   0, -30],
                [-40, -20,   0,   0,   0,   0, -20, -40],
                [-50, -40, -30, -30, -30, -30, -40, -50],
            ],
            'B' => [
                [-20, -10, -10, -10, -10, -10, -10, -20],
                [-10,   5,   0,   0,   0,   0,   5, -10],
                [-10,  10,  10,  10,  10,  10,  10, -10],
                [-10,   0,  10,  10,  10,  10,   0, -10],
                [-10,   5,   5,  10,  10,   5,   5, -10],
                [-10,   0,   5,  10,  10,   5,   0, -10],
                [-10,   0,   0,   0,   0,   0,   0, -10],
                [-20, -10, -10, -10, -10, -10, -10, -20],
            ],
            'R' => [
                [  0,   0,   0,   5,   5,   0,   0,   0],
                [ -5,   0,   0,   0,   0,   0,   0,  -5],
                [ -5,   0,   0,   0,   0,   0,   0,  -5],
                [ -5,   0,   0,   0,   0,   0,   0,  -5],
                [ -5,   0,   0,   0,   0,   0,   0,  -5],
                [ -5,   0,   0,   0,   0,   0,   0,  -5],
                [  5,  10,  10,  10,  10,  10,  10,   5],
                [  0,   0,   0,   0,   0,   0,   0,   0],
            ],
            'Q' => [
                [-20, -10, -10,  -5,  -5, -10, -10, -20],
                [-10,   0,   5,   0,   0,   0,   0, -10],
                [-10,   5,   5,   5,   5,   5,   0, -10],
                [  0,   0,   5,   5,   5,   5,   0,  -5],
                [ -5,   0,   5,   5,   5,   5,   0,  -5],
                [-10,   0,   5,   5,   5,   5,   0, -10],
                [-10,   0,   0,   0,   0,   0,   0, -10],
                [-20, -10, -10,  -5,  -5, -10, -10, -20],
            ],
            'K' => [
                [ 20,  30,  10,   0,   0,  10,  30,  20],
                [ 20,  20,   0,   0,   0,   0,  20,  20],
                [-10, -20, -20, -20, -20, -20, -20, -10],
                [-20, -30, -30, -40, -40, -30, -30, -20],
                [-30, -40, -40, -50, -50, -40, -40, -30],
                [-30, -40, -40, -50, -50, -40, -40, -30],
                [-30, -40, -40, -50, -50, -40, -40, -30],
                [-30, -40, -40, -50, -50, -40, -40, -30],
            ],
        ];
    }

    /**
     * @param Chess  $chess      current game (turn = side to move = AI's side)
     * @param string $difficulty 'beginner' | 'medium' | 'expert'
     * @return array|null the chosen move, or null if no legal moves
     */
    public function getMove(Chess $chess, string $difficulty): ?array
    {
        $legalMoves = $chess->generateLegalMoves($chess->turn);
        if (empty($legalMoves)) return null;

        switch ($difficulty) {
            case 'beginner':
                return $this->beginnerMove($chess, $legalMoves);
            case 'medium':
                return $this->searchMove($chess, $legalMoves, maxDepth: 3, timeLimit: 2.0, quiescence: false, noiseCp: 15);
            case 'expert':
                return $this->searchMove($chess, $legalMoves, maxDepth: 6, timeLimit: 3.0, quiescence: true, noiseCp: 0);
            default:
                throw new InvalidArgumentException("Unknown difficulty: $difficulty");
        }
    }

    // ------------------------------------------------------------------
    // Beginner: mostly random, sometimes grabs a free piece, doesn't look ahead
    // ------------------------------------------------------------------

    private function beginnerMove(Chess $chess, array $legalMoves): array
    {
        $roll = mt_rand(1, 100);

        // ~35% of the time, play the single best-looking immediate capture
        // (a beginner *will* take a free queen, but won't see anything past that).
        if ($roll <= 35) {
            $best = null;
            $bestScore = -PHP_INT_MAX;
            foreach ($legalMoves as $move) {
                if ($move['captured'] === null) continue;
                $score = $this->pieceValues[strtoupper($move['captured'])];
                if ($score > $bestScore) { $bestScore = $score; $best = $move; }
            }
            if ($best !== null) return $best;
        }

        // otherwise: fully random legal move (this is what produces authentic
        // beginner blunders - hanging pieces, ignoring threats, no plan)
        return $legalMoves[array_rand($legalMoves)];
    }

    // ------------------------------------------------------------------
    // Medium / Expert: iterative deepening negamax with alpha-beta
    // ------------------------------------------------------------------

    private function searchMove(Chess $chess, array $legalMoves, int $maxDepth, float $timeLimit, bool $quiescence, int $noiseCp): array
    {
        $this->deadline = microtime(true) + $timeLimit;
        $this->quiescenceEnabled = $quiescence;

        $bestMove = $legalMoves[array_rand($legalMoves)]; // safe fallback
        $bestScore = -PHP_INT_MAX;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $orderedMoves = $this->orderMoves($chess, $legalMoves, null);
            $alpha = -self::MATE_SCORE - 1;
            $beta = self::MATE_SCORE + 1;
            $depthBestMove = null;
            $depthBestScore = -PHP_INT_MAX;
            $scored = [];

            $timedOut = false;
            foreach ($orderedMoves as $move) {
                if (microtime(true) > $this->deadline) { $timedOut = true; break; }
                $undo = $chess->makeMove($move);
                $score = -$this->negamax($chess, $depth - 1, -$beta, -$alpha);
                $chess->undoMove($move, $undo);

                $scored[] = ['move' => $move, 'score' => $score];

                if ($score > $depthBestScore) {
                    $depthBestScore = $score;
                    $depthBestMove = $move;
                }
                if ($score > $alpha) $alpha = $score;
            }

            if ($depthBestMove !== null && !$timedOut) {
                $bestMove = $depthBestMove;
                $bestScore = $depthBestScore;

                // add some human-like variety among near-equal top moves
                if ($noiseCp > 0) {
                    $top = array_values(array_filter($scored, fn($s) => $s['score'] >= $depthBestScore - $noiseCp));
                    if (!empty($top)) {
                        $bestMove = $top[array_rand($top)]['move'];
                    }
                }
            }

            if ($timedOut) break;
            if (abs($bestScore) >= self::MATE_SCORE - 100) break; // found forced mate, no need to go deeper
        }

        return $bestMove;
    }

    private bool $quiescenceEnabled = false;

    private function negamax(Chess $chess, int $depth, float $alpha, float $beta): float
    {
        $this->nodesSearched++;

        $color = $chess->turn;
        $inCheck = $chess->isInCheck($color);

        if ($depth <= 0) {
            return $this->quiescenceEnabled
                ? $this->quiescence($chess, $alpha, $beta)
                : $this->evaluate($chess, $color);
        }

        if (microtime(true) > $this->deadline) {
            return $this->evaluate($chess, $color);
        }

        $moves = $chess->generateLegalMoves($color);
        if (empty($moves)) {
            if ($inCheck) return -self::MATE_SCORE - $depth; // checkmated - prefer slower mates less negative... (see note)
            return 0; // stalemate
        }

        $moves = $this->orderMoves($chess, $moves, null);

        $best = -PHP_INT_MAX;
        foreach ($moves as $move) {
            $undo = $chess->makeMove($move);
            $score = -$this->negamax($chess, $depth - 1, -$beta, -$alpha);
            $chess->undoMove($move, $undo);

            if ($score > $best) $best = $score;
            if ($best > $alpha) $alpha = $best;
            if ($alpha >= $beta) break; // beta cutoff
        }
        return $best;
    }

    /** Extends search on captures only, to avoid the horizon effect at leaf nodes. */
    private function quiescence(Chess $chess, float $alpha, float $beta): float
    {
        $this->nodesSearched++;
        $color = $chess->turn;

        $standPat = $this->evaluate($chess, $color);
        if ($standPat >= $beta) return $beta;
        if ($standPat > $alpha) $alpha = $standPat;

        if (microtime(true) > $this->deadline) return $standPat;

        $moves = $chess->generateLegalMoves($color);
        $captures = array_values(array_filter($moves, fn($m) => $m['captured'] !== null));
        $captures = $this->orderMoves($chess, $captures, null);

        foreach ($captures as $move) {
            $undo = $chess->makeMove($move);
            $score = -$this->quiescence($chess, -$beta, -$alpha);
            $chess->undoMove($move, $undo);

            if ($score >= $beta) return $beta;
            if ($score > $alpha) $alpha = $score;
        }
        return $alpha;
    }

    /** Simple move ordering: captures first, ranked by MVV-LVA (Most Valuable Victim - Least Valuable Attacker). */
    private function orderMoves(Chess $chess, array $moves, ?array $unused): array
    {
        usort($moves, function ($a, $b) {
            $aScore = $this->moveOrderScore($a);
            $bScore = $this->moveOrderScore($b);
            return $bScore <=> $aScore;
        });
        return $moves;
    }

    private function moveOrderScore(array $move): int
    {
        $score = 0;
        if ($move['captured'] !== null) {
            $victim = $this->pieceValues[strtoupper($move['captured'])];
            $attacker = $this->pieceValues[strtoupper($move['piece'])];
            $score += 10000 + $victim * 10 - $attacker;
        }
        if ($move['promotion'] !== null) $score += 9000;
        if ($move['isCastle'] !== null) $score += 50;
        return $score;
    }

    /** Static evaluation from the perspective of $sideToMove (positive = good for that side). */
    public function evaluate(Chess $chess, string $sideToMove): float
    {
        $score = 0;
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $p = $chess->board[$r][$c];
                if ($p === null) continue;
                $type = strtoupper($p);
                $isWhite = ctype_upper($p);
                $value = $this->pieceValues[$type];
                $pstRow = $isWhite ? $r : 7 - $r;
                $pstCol = $isWhite ? $c : 7 - $c;
                $posValue = $this->pst[$type][$pstRow][$pstCol];
                $total = $value + $posValue;
                $score += $isWhite ? $total : -$total;
            }
        }

        // small mobility bonus - encourages active piece play, more relevant at higher depth
        $whiteMobility = count($chess->generatePseudoMoves('w'));
        $blackMobility = count($chess->generatePseudoMoves('b'));
        $score += ($whiteMobility - $blackMobility) * 2;

        return $sideToMove === 'w' ? $score : -$score;
    }

    public function getNodesSearched(): int
    {
        return $this->nodesSearched;
    }
}
