<?php

/**
 * ChessEngine.php
 *
 * A self-contained chess rules engine in plain PHP: board state, fully
 * legal move generation (including castling, en passant, promotion),
 * check/checkmate/stalemate/draw detection, FEN import/export, and SAN
 * generation for display.
 *
 * Board convention:
 *   - $board[$row][$col], $row 0..7 maps to ranks 1..8, $col 0..7 maps to
 *     files a..h. So $board[0][0] is a1, $board[7][7] is h8.
 *   - White pieces are uppercase (P N B R Q K), black lowercase, empty = null.
 *
 * Move array shape:
 *   [
 *     'from' => [row, col], 'to' => [row, col],
 *     'piece' => 'P', 'color' => 'w',
 *     'captured' => 'p'|null, 'promotion' => 'Q'|null,
 *     'isEnPassant' => bool, 'isCastle' => 'K'|'Q'|null,
 *     'isDoublePawnPush' => bool,
 *   ]
 */

class Chess
{
    public array $board;
    public string $turn;                 // 'w' or 'b'
    public array $castling;              // ['K'=>bool,'Q'=>bool,'k'=>bool,'q'=>bool]
    public ?array $epTarget;             // [row,col] square a pawn can capture en passant onto, or null
    public int $halfmoveClock;
    public int $fullmoveNumber;

    /** @var string[] FEN snapshots (position-only) for threefold repetition */
    public array $positionHistory = [];

    /** @var array<int, array{move: array, san: string}> full move log for display/undo */
    public array $moveLog = [];

    const START_FEN = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';

    public function __construct(?string $fen = null)
    {
        $this->loadFEN($fen ?? self::START_FEN);
    }

    // ------------------------------------------------------------------
    // FEN
    // ------------------------------------------------------------------

    public function loadFEN(string $fen): void
    {
        $parts = preg_split('/\s+/', trim($fen));
        [$placement, $turn, $castling, $ep, $half, $full] = array_pad($parts, 6, null);

        $this->board = array_fill(0, 8, array_fill(0, 8, null));
        $ranks = explode('/', $placement);
        // FEN ranks go 8 -> 1, i.e. row 7 -> row 0
        for ($i = 0; $i < 8; $i++) {
            $row = 7 - $i;
            $col = 0;
            foreach (str_split($ranks[$i]) as $ch) {
                if (ctype_digit($ch)) {
                    $col += (int)$ch;
                } else {
                    $this->board[$row][$col] = $ch;
                    $col++;
                }
            }
        }

        $this->turn = $turn ?? 'w';

        $this->castling = ['K' => false, 'Q' => false, 'k' => false, 'q' => false];
        if ($castling && $castling !== '-') {
            foreach (str_split($castling) as $ch) {
                if (isset($this->castling[$ch])) $this->castling[$ch] = true;
            }
        }

        $this->epTarget = ($ep && $ep !== '-') ? self::squareToRC($ep) : null;
        $this->halfmoveClock = $half !== null ? (int)$half : 0;
        $this->fullmoveNumber = $full !== null ? (int)$full : 1;

        $this->positionHistory = [$this->positionKey()];
        $this->moveLog = [];
    }

    public function toFEN(): string
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $row = 7 - $i;
            $empty = 0;
            $line = '';
            for ($col = 0; $col < 8; $col++) {
                $p = $this->board[$row][$col];
                if ($p === null) {
                    $empty++;
                } else {
                    if ($empty > 0) { $line .= $empty; $empty = 0; }
                    $line .= $p;
                }
            }
            if ($empty > 0) $line .= $empty;
            $rows[] = $line;
        }
        $placement = implode('/', $rows);

        $castleStr = '';
        foreach (['K', 'Q', 'k', 'q'] as $c) if ($this->castling[$c]) $castleStr .= $c;
        if ($castleStr === '') $castleStr = '-';

        $epStr = $this->epTarget ? self::rcToSquare($this->epTarget[0], $this->epTarget[1]) : '-';

        return "{$placement} {$this->turn} {$castleStr} {$epStr} {$this->halfmoveClock} {$this->fullmoveNumber}";
    }

    /** Position-only key (placement + turn + castling + ep) used for repetition detection */
    public function positionKey(): string
    {
        $f = $this->toFEN();
        $parts = explode(' ', $f);
        return implode(' ', array_slice($parts, 0, 4));
    }

    // ------------------------------------------------------------------
    // Coordinate helpers
    // ------------------------------------------------------------------

    public static function squareToRC(string $sq): array
    {
        $file = ord($sq[0]) - ord('a');
        $rank = (int)substr($sq, 1) - 1;
        return [$rank, $file];
    }

    public static function rcToSquare(int $row, int $col): string
    {
        return chr(ord('a') + $col) . (string)($row + 1);
    }

    private static function onBoard(int $r, int $c): bool
    {
        return $r >= 0 && $r < 8 && $c >= 0 && $c < 8;
    }

    public function pieceAt(int $r, int $c): ?string
    {
        return self::onBoard($r, $c) ? $this->board[$r][$c] : null;
    }

    private static function colorOf(string $piece): string
    {
        return ctype_upper($piece) ? 'w' : 'b';
    }

    private static function typeOf(string $piece): string
    {
        return strtoupper($piece);
    }

    private static function opponent(string $color): string
    {
        return $color === 'w' ? 'b' : 'w';
    }

    // ------------------------------------------------------------------
    // Attack detection
    // ------------------------------------------------------------------

    /** Is square (r,c) attacked by any piece of $byColor? */
    public function isSquareAttacked(int $r, int $c, string $byColor): bool
    {
        // Pawns: a byColor pawn attacks (r,c) if it sits diagonally "behind" it
        $pawnDir = $byColor === 'w' ? -1 : 1; // white pawns attack upward, so look one rank below (r+pawnDir... )
        // If white pawn attacks (r,c), the pawn is at (r-1, c-1) or (r-1, c+1)
        $pr = $byColor === 'w' ? $r - 1 : $r + 1;
        foreach ([-1, 1] as $dc) {
            $p = $this->pieceAt($pr, $c + $dc);
            if ($p !== null && self::colorOf($p) === $byColor && self::typeOf($p) === 'P') return true;
        }

        // Knights
        $knightOffsets = [[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]];
        foreach ($knightOffsets as [$dr, $dc]) {
            $p = $this->pieceAt($r + $dr, $c + $dc);
            if ($p !== null && self::colorOf($p) === $byColor && self::typeOf($p) === 'N') return true;
        }

        // King
        for ($dr = -1; $dr <= 1; $dr++) {
            for ($dc = -1; $dc <= 1; $dc++) {
                if ($dr === 0 && $dc === 0) continue;
                $p = $this->pieceAt($r + $dr, $c + $dc);
                if ($p !== null && self::colorOf($p) === $byColor && self::typeOf($p) === 'K') return true;
            }
        }

        // Sliding: bishop/queen on diagonals
        foreach ([[1,1],[1,-1],[-1,1],[-1,-1]] as [$dr, $dc]) {
            $nr = $r + $dr; $nc = $c + $dc;
            while (self::onBoard($nr, $nc)) {
                $p = $this->board[$nr][$nc];
                if ($p !== null) {
                    if (self::colorOf($p) === $byColor && in_array(self::typeOf($p), ['B', 'Q'])) return true;
                    break;
                }
                $nr += $dr; $nc += $dc;
            }
        }

        // Sliding: rook/queen on files/ranks
        foreach ([[1,0],[-1,0],[0,1],[0,-1]] as [$dr, $dc]) {
            $nr = $r + $dr; $nc = $c + $dc;
            while (self::onBoard($nr, $nc)) {
                $p = $this->board[$nr][$nc];
                if ($p !== null) {
                    if (self::colorOf($p) === $byColor && in_array(self::typeOf($p), ['R', 'Q'])) return true;
                    break;
                }
                $nr += $dr; $nc += $dc;
            }
        }

        return false;
    }

    public function findKing(string $color): ?array
    {
        $target = $color === 'w' ? 'K' : 'k';
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                if ($this->board[$r][$c] === $target) return [$r, $c];
            }
        }
        return null;
    }

    public function isInCheck(string $color): bool
    {
        $k = $this->findKing($color);
        if ($k === null) return false; // shouldn't happen in a valid game
        return $this->isSquareAttacked($k[0], $k[1], self::opponent($color));
    }

    // ------------------------------------------------------------------
    // Pseudo-legal move generation
    // ------------------------------------------------------------------

    public function generatePseudoMoves(string $color): array
    {
        $moves = [];
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $p = $this->board[$r][$c];
                if ($p === null || self::colorOf($p) !== $color) continue;
                $type = self::typeOf($p);
                switch ($type) {
                    case 'P': $this->genPawnMoves($r, $c, $color, $moves); break;
                    case 'N': $this->genStepMoves($r, $c, $color, $p, [[1,2],[2,1],[-1,2],[-2,1],[1,-2],[2,-1],[-1,-2],[-2,-1]], $moves); break;
                    case 'B': $this->genSlideMoves($r, $c, $color, $p, [[1,1],[1,-1],[-1,1],[-1,-1]], $moves); break;
                    case 'R': $this->genSlideMoves($r, $c, $color, $p, [[1,0],[-1,0],[0,1],[0,-1]], $moves); break;
                    case 'Q': $this->genSlideMoves($r, $c, $color, $p, [[1,1],[1,-1],[-1,1],[-1,-1],[1,0],[-1,0],[0,1],[0,-1]], $moves); break;
                    case 'K':
                        $this->genStepMoves($r, $c, $color, $p, [[1,0],[-1,0],[0,1],[0,-1],[1,1],[1,-1],[-1,1],[-1,-1]], $moves);
                        $this->genCastleMoves($r, $c, $color, $moves);
                        break;
                }
            }
        }
        return $moves;
    }

    private function baseMove(array $from, array $to, string $piece, string $color): array
    {
        return [
            'from' => $from, 'to' => $to, 'piece' => $piece, 'color' => $color,
            'captured' => $this->board[$to[0]][$to[1]], 'promotion' => null,
            'isEnPassant' => false, 'isCastle' => null, 'isDoublePawnPush' => false,
        ];
    }

    private function genStepMoves(int $r, int $c, string $color, string $piece, array $offsets, array &$moves): void
    {
        foreach ($offsets as [$dr, $dc]) {
            $nr = $r + $dr; $nc = $c + $dc;
            if (!self::onBoard($nr, $nc)) continue;
            $target = $this->board[$nr][$nc];
            if ($target !== null && self::colorOf($target) === $color) continue;
            $moves[] = $this->baseMove([$r, $c], [$nr, $nc], $piece, $color);
        }
    }

    private function genSlideMoves(int $r, int $c, string $color, string $piece, array $dirs, array &$moves): void
    {
        foreach ($dirs as [$dr, $dc]) {
            $nr = $r + $dr; $nc = $c + $dc;
            while (self::onBoard($nr, $nc)) {
                $target = $this->board[$nr][$nc];
                if ($target === null) {
                    $moves[] = $this->baseMove([$r, $c], [$nr, $nc], $piece, $color);
                } else {
                    if (self::colorOf($target) !== $color) {
                        $moves[] = $this->baseMove([$r, $c], [$nr, $nc], $piece, $color);
                    }
                    break;
                }
                $nr += $dr; $nc += $dc;
            }
        }
    }

    private function genPawnMoves(int $r, int $c, string $color, array &$moves): void
    {
        $piece = $color === 'w' ? 'P' : 'p';
        $dir = $color === 'w' ? 1 : -1;
        $startRank = $color === 'w' ? 1 : 6;
        $promoRank = $color === 'w' ? 7 : 0;

        // single push
        $nr = $r + $dir;
        if (self::onBoard($nr, $c) && $this->board[$nr][$c] === null) {
            $this->addPawnMoveWithPromotion([$r, $c], [$nr, $c], $piece, $color, $promoRank, $moves);

            // double push
            if ($r === $startRank) {
                $nr2 = $r + 2 * $dir;
                if ($this->board[$nr2][$c] === null) {
                    $m = $this->baseMove([$r, $c], [$nr2, $c], $piece, $color);
                    $m['isDoublePawnPush'] = true;
                    $moves[] = $m;
                }
            }
        }

        // captures
        foreach ([-1, 1] as $dc) {
            $nc = $c + $dc;
            if (!self::onBoard($nr, $nc)) continue;
            $target = $this->board[$nr][$nc];
            if ($target !== null && self::colorOf($target) !== $color) {
                $this->addPawnMoveWithPromotion([$r, $c], [$nr, $nc], $piece, $color, $promoRank, $moves);
            } elseif ($target === null && $this->epTarget !== null && $this->epTarget[0] === $nr && $this->epTarget[1] === $nc) {
                $m = $this->baseMove([$r, $c], [$nr, $nc], $piece, $color);
                $m['isEnPassant'] = true;
                $m['captured'] = $color === 'w' ? 'p' : 'P';
                $moves[] = $m;
            }
        }
    }

    private function addPawnMoveWithPromotion(array $from, array $to, string $piece, string $color, int $promoRank, array &$moves): void
    {
        if ($to[0] === $promoRank) {
            foreach (['Q', 'R', 'B', 'N'] as $promo) {
                $m = $this->baseMove($from, $to, $piece, $color);
                $m['promotion'] = $color === 'w' ? $promo : strtolower($promo);
                $moves[] = $m;
            }
        } else {
            $moves[] = $this->baseMove($from, $to, $piece, $color);
        }
    }

    private function genCastleMoves(int $r, int $c, string $color, array &$moves): void
    {
        $opp = self::opponent($color);
        if ($this->isSquareAttacked($r, $c, $opp)) return; // can't castle out of check

        $rights = $color === 'w' ? ['K' => 'K', 'Q' => 'Q'] : ['K' => 'k', 'Q' => 'q'];
        $rank = $color === 'w' ? 0 : 7;

        // kingside
        if ($this->castling[$rights['K']]
            && $this->board[$rank][5] === null && $this->board[$rank][6] === null
            && !$this->isSquareAttacked($rank, 5, $opp) && !$this->isSquareAttacked($rank, 6, $opp)
            && $this->board[$rank][7] === ($color === 'w' ? 'R' : 'r')) {
            $piece = $color === 'w' ? 'K' : 'k';
            $m = $this->baseMove([$r, $c], [$rank, 6], $piece, $color);
            $m['isCastle'] = 'K';
            $moves[] = $m;
        }

        // queenside
        if ($this->castling[$rights['Q']]
            && $this->board[$rank][1] === null && $this->board[$rank][2] === null && $this->board[$rank][3] === null
            && !$this->isSquareAttacked($rank, 2, $opp) && !$this->isSquareAttacked($rank, 3, $opp)
            && $this->board[$rank][0] === ($color === 'w' ? 'R' : 'r')) {
            $piece = $color === 'w' ? 'K' : 'k';
            $m = $this->baseMove([$r, $c], [$rank, 2], $piece, $color);
            $m['isCastle'] = 'Q';
            $moves[] = $m;
        }
    }

    // ------------------------------------------------------------------
    // Legal move generation (pseudo-legal filtered by king safety)
    // ------------------------------------------------------------------

    public function generateLegalMoves(string $color): array
    {
        $legal = [];
        foreach ($this->generatePseudoMoves($color) as $move) {
            $undo = $this->makeMove($move);
            if (!$this->isInCheck($color)) {
                $legal[] = $move;
            }
            $this->undoMove($move, $undo);
        }
        return $legal;
    }

    public function getLegalMovesFrom(int $r, int $c): array
    {
        $piece = $this->board[$r][$c];
        if ($piece === null) return [];
        $color = self::colorOf($piece);
        if ($color !== $this->turn) return [];
        $all = $this->generateLegalMoves($color);
        return array_values(array_filter($all, fn($m) => $m['from'][0] === $r && $m['from'][1] === $c));
    }

    // ------------------------------------------------------------------
    // Make / unmake move (used both for real play and for search)
    // ------------------------------------------------------------------

    /** Applies $move to the board and returns undo info. Switches turn. */
    public function makeMove(array $move): array
    {
        $undo = [
            'castling' => $this->castling,
            'epTarget' => $this->epTarget,
            'halfmoveClock' => $this->halfmoveClock,
            'fullmoveNumber' => $this->fullmoveNumber,
            'capturedPiece' => $move['captured'],
        ];

        [$fr, $fc] = $move['from'];
        [$tr, $tc] = $move['to'];
        $piece = $move['piece'];
        $color = $move['color'];

        // en passant capture removes the pawn behind the destination square
        if ($move['isEnPassant']) {
            $capRow = $color === 'w' ? $tr - 1 : $tr + 1;
            $this->board[$capRow][$tc] = null;
        }

        $this->board[$fr][$fc] = null;
        $this->board[$tr][$tc] = $move['promotion'] ?? $piece;

        // castling: move the rook too
        if ($move['isCastle'] === 'K') {
            $rank = $color === 'w' ? 0 : 7;
            $rook = $color === 'w' ? 'R' : 'r';
            $this->board[$rank][7] = null;
            $this->board[$rank][5] = $rook;
        } elseif ($move['isCastle'] === 'Q') {
            $rank = $color === 'w' ? 0 : 7;
            $rook = $color === 'w' ? 'R' : 'r';
            $this->board[$rank][0] = null;
            $this->board[$rank][3] = $rook;
        }

        // update castling rights
        if ($piece === 'K') { $this->castling['K'] = false; $this->castling['Q'] = false; }
        if ($piece === 'k') { $this->castling['k'] = false; $this->castling['q'] = false; }
        if ($fr === 0 && $fc === 0) $this->castling['Q'] = false;
        if ($fr === 0 && $fc === 7) $this->castling['K'] = false;
        if ($fr === 7 && $fc === 0) $this->castling['q'] = false;
        if ($fr === 7 && $fc === 7) $this->castling['k'] = false;
        if ($tr === 0 && $tc === 0) $this->castling['Q'] = false;
        if ($tr === 0 && $tc === 7) $this->castling['K'] = false;
        if ($tr === 7 && $tc === 0) $this->castling['q'] = false;
        if ($tr === 7 && $tc === 7) $this->castling['k'] = false;

        // en passant target for next move
        $this->epTarget = $move['isDoublePawnPush']
            ? [intdiv($fr + $tr, 2), $fc]
            : null;

        // halfmove clock
        if (self::typeOf($piece) === 'P' || $move['captured'] !== null) {
            $this->halfmoveClock = 0;
        } else {
            $this->halfmoveClock++;
        }

        if ($color === 'b') $this->fullmoveNumber++;

        $this->turn = self::opponent($color);

        return $undo;
    }

    public function undoMove(array $move, array $undo): void
    {
        [$fr, $fc] = $move['from'];
        [$tr, $tc] = $move['to'];
        $color = $move['color'];

        $this->board[$fr][$fc] = $move['piece'];
        $this->board[$tr][$tc] = null;

        if ($move['isEnPassant']) {
            $capRow = $color === 'w' ? $tr - 1 : $tr + 1;
            $this->board[$capRow][$tc] = $undo['capturedPiece'];
        } elseif ($move['captured'] !== null) {
            $this->board[$tr][$tc] = $move['captured'];
        }

        if ($move['isCastle'] === 'K') {
            $rank = $color === 'w' ? 0 : 7;
            $rook = $color === 'w' ? 'R' : 'r';
            $this->board[$rank][5] = null;
            $this->board[$rank][7] = $rook;
        } elseif ($move['isCastle'] === 'Q') {
            $rank = $color === 'w' ? 0 : 7;
            $rook = $color === 'w' ? 'R' : 'r';
            $this->board[$rank][3] = null;
            $this->board[$rank][0] = $rook;
        }

        $this->castling = $undo['castling'];
        $this->epTarget = $undo['epTarget'];
        $this->halfmoveClock = $undo['halfmoveClock'];
        $this->fullmoveNumber = $undo['fullmoveNumber'];
        $this->turn = $color;
    }

    /** Applies a move permanently for real gameplay: updates logs/history. */
    public function playMove(array $move): void
    {
        $san = $this->moveToSAN($move);
        $this->makeMove($move);
        $this->positionHistory[] = $this->positionKey();
        $this->moveLog[] = ['move' => $move, 'san' => $san];
    }

    // ------------------------------------------------------------------
    // Game state queries
    // ------------------------------------------------------------------

    public function isCheckmate(string $color): bool
    {
        return $this->isInCheck($color) && count($this->generateLegalMoves($color)) === 0;
    }

    public function isStalemate(string $color): bool
    {
        return !$this->isInCheck($color) && count($this->generateLegalMoves($color)) === 0;
    }

    public function isInsufficientMaterial(): bool
    {
        $pieces = [];
        for ($r = 0; $r < 8; $r++) {
            for ($c = 0; $c < 8; $c++) {
                $p = $this->board[$r][$c];
                if ($p !== null && self::typeOf($p) !== 'K') $pieces[] = $p;
            }
        }
        if (count($pieces) === 0) return true; // K v K
        if (count($pieces) === 1 && in_array(self::typeOf($pieces[0]), ['N', 'B'])) return true; // K+minor v K
        if (count($pieces) === 2) {
            $types = array_map([self::class, 'typeOf'], $pieces);
            sort($types);
            if ($types === ['B', 'B']) {
                // same-color bishops only -> draw; opposite-color bishops can still mate, treat as sufficient
                return false; // conservative: don't auto-claim draw here
            }
        }
        return false;
    }

    public function isFiftyMoveRule(): bool
    {
        return $this->halfmoveClock >= 100;
    }

    public function isThreefoldRepetition(): bool
    {
        $counts = array_count_values($this->positionHistory);
        foreach ($counts as $cnt) if ($cnt >= 3) return true;
        return false;
    }

    /** Returns one of: null, 'checkmate', 'stalemate', 'draw_insufficient', 'draw_50move', 'draw_repetition' */
    public function getGameStatus(): ?string
    {
        if ($this->isCheckmate($this->turn)) return 'checkmate';
        if ($this->isStalemate($this->turn)) return 'stalemate';
        if ($this->isInsufficientMaterial()) return 'draw_insufficient';
        if ($this->isFiftyMoveRule()) return 'draw_50move';
        if ($this->isThreefoldRepetition()) return 'draw_repetition';
        return null;
    }

    public function isGameOver(): bool
    {
        return $this->getGameStatus() !== null;
    }

    // ------------------------------------------------------------------
    // Notation
    // ------------------------------------------------------------------

    public static function moveToUCI(array $move): string
    {
        $s = self::rcToSquare($move['from'][0], $move['from'][1]) . self::rcToSquare($move['to'][0], $move['to'][1]);
        if ($move['promotion']) $s .= strtolower($move['promotion']);
        return $s;
    }

    /** Find the legal move matching a UCI string like "e2e4" or "e7e8q". */
    public function findMoveByUCI(string $uci): ?array
    {
        $uci = strtolower(trim($uci));
        if (strlen($uci) < 4) return null;
        $from = self::squareToRC(substr($uci, 0, 2));
        $to = self::squareToRC(substr($uci, 2, 2));
        $promo = strlen($uci) >= 5 ? strtoupper($uci[4]) : null;

        foreach ($this->generateLegalMoves($this->turn) as $m) {
            if ($m['from'] === $from && $m['to'] === $to) {
                if ($m['promotion'] === null) {
                    if ($promo === null) return $m;
                } else {
                    $wantedPromo = $this->turn === 'w' ? $promo : strtolower($promo);
                    if ($promo !== null && $m['promotion'] === $wantedPromo) return $m;
                    if ($promo === null && strtoupper($m['promotion']) === 'Q') return $m; // default to queen
                }
            }
        }
        return null;
    }

    public function moveToSAN(array $move): string
    {
        if ($move['isCastle'] === 'K') $suffix = 'O-O';
        elseif ($move['isCastle'] === 'Q') $suffix = 'O-O-O';
        else {
            $type = self::typeOf($move['piece']);
            $capture = $move['captured'] !== null;
            $toSq = self::rcToSquare($move['to'][0], $move['to'][1]);

            if ($type === 'P') {
                $fromFile = chr(ord('a') + $move['from'][1]);
                $suffix = $capture ? "{$fromFile}x{$toSq}" : $toSq;
                if ($move['promotion']) $suffix .= '=' . strtoupper($move['promotion']);
            } else {
                // disambiguation: other same-type pieces of same color that could also reach 'to'
                $others = [];
                foreach ($this->generatePseudoMoves($move['color']) as $om) {
                    if ($om['piece'] === $move['piece'] && $om['to'] === $move['to'] && $om['from'] !== $move['from']) {
                        $others[] = $om;
                    }
                }
                $disambig = '';
                if (!empty($others)) {
                    $sameFile = array_filter($others, fn($o) => $o['from'][1] === $move['from'][1]);
                    $sameRank = array_filter($others, fn($o) => $o['from'][0] === $move['from'][0]);
                    if (empty($sameFile)) {
                        $disambig = chr(ord('a') + $move['from'][1]);
                    } elseif (empty($sameRank)) {
                        $disambig = (string)($move['from'][0] + 1);
                    } else {
                        $disambig = self::rcToSquare($move['from'][0], $move['from'][1]);
                    }
                }
                $suffix = $type . $disambig . ($capture ? 'x' : '') . $toSq;
            }
        }

        // check / checkmate suffix: simulate the move
        $undo = $this->makeMove($move);
        $opp = self::opponent($move['color']);
        if ($this->isCheckmate($opp)) $suffix .= '#';
        elseif ($this->isInCheck($opp)) $suffix .= '+';
        $this->undoMove($move, $undo);

        return $suffix;
    }

    // ------------------------------------------------------------------
    // Debug / utility
    // ------------------------------------------------------------------

    public function printBoard(): string
    {
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $r = 7 - $i;
            $out .= ($r + 1) . '  ';
            for ($c = 0; $c < 8; $c++) {
                $p = $this->board[$r][$c];
                $out .= ($p ?? '.') . ' ';
            }
            $out .= "\n";
        }
        $out .= "   a b c d e f g h\n";
        return $out;
    }
}
