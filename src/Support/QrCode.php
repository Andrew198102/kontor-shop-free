<?php

declare(strict_types=1);

namespace Kontor\Shop\Support;

/**
 * Kleiner QR-Code-Encoder (ISO/IEC 18004) fuer den Ueberweisungs-QR-Code:
 * nur Byte-Modus, Fehlerkorrektur waehlbar (Standard M, wie der
 * EPC-Standard verlangt), Version 1-40 automatisch, Maske nach den
 * Strafpunkten der Norm. Reine Logik ohne Abhaengigkeiten - Ablauf nach
 * dem frei beschriebenen Referenzverfahren (Project Nayuki, MIT-Lizenz).
 *
 * matrix() liefert Zeilen von bool (true = dunkles Modul); svg() eine
 * eigenstaendige SVG-Grafik mit Ruhezone.
 */
final class QrCode
{
    public const ECC_L = 'L';
    public const ECC_M = 'M';
    public const ECC_Q = 'Q';
    public const ECC_H = 'H';

    /** Formatbits je Stufe */
    private const ECC_FORMAT = [self::ECC_L => 1, self::ECC_M => 0, self::ECC_Q => 3, self::ECC_H => 2];

    private const ECC_CODEWORDS_PER_BLOCK = [
        self::ECC_L => [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        self::ECC_M => [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        self::ECC_Q => [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        self::ECC_H => [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        self::ECC_L => [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        self::ECC_M => [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        self::ECC_Q => [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        self::ECC_H => [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    private int $size = 0;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $is_function = [];

    private function __construct(private readonly int $version, private readonly string $ecc)
    {
        $this->size = $version * 4 + 17;
        $row        = array_fill(0, $this->size, false);
        $this->modules     = array_fill(0, $this->size, $row);
        $this->is_function = array_fill(0, $this->size, $row);
    }

    /**
     * Modul-Matrix fuer $data (Bytes, z. B. UTF-8-Text); null, wenn die Daten
     * selbst fuer Version 40 zu lang sind.
     *
     * @return array<int, array<int, bool>>|null
     */
    public static function matrix(string $data, string $ecc = self::ECC_M): ?array
    {
        $ecc   = isset(self::ECC_FORMAT[$ecc]) ? $ecc : self::ECC_M;
        $bytes = array_values(unpack('C*', $data) ?: []);
        $len   = count($bytes);

        for ($version = 1; $version <= 40; $version++) {
            $capacity = self::num_data_codewords($version, $ecc) * 8;
            $needed   = 4 + ($version <= 9 ? 8 : 16) + 8 * $len;

            if ($needed <= $capacity) {
                $qr = new self($version, $ecc);

                return $qr->build($bytes, $capacity);
            }
        }

        return null;
    }

    /**
     * SVG mit 4 Module Ruhezone (Pflicht fuer zuverlaessiges Scannen).
     *
     * @param array<int, array<int, bool>> $matrix
     */
    public static function svg(array $matrix, string $title = ''): string
    {
        $size  = count($matrix);
        $total = $size + 8;
        $path  = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + 4) . ',' . ($y + 4) . 'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" shape-rendering="crispEdges" role="img"'
            . ($title !== '' ? ' aria-label="' . htmlspecialchars($title, ENT_QUOTES) . '"' : '') . '>'
            . '<rect width="' . $total . '" height="' . $total . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /**
     * @param array<int, int> $bytes
     * @return array<int, array<int, bool>>
     */
    private function build(array $bytes, int $capacity_bits): array
    {
        // Bitstrom: Modus "Byte" (0100), Laenge, Daten, Abschluss, Fuellbytes.
        $bits = [];
        $append = static function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0x4, 4);
        $append(count($bytes), $this->version <= 9 ? 8 : 16);

        foreach ($bytes as $byte) {
            $append($byte, 8);
        }

        $append(0, min(4, $capacity_bits - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);

        for ($pad = 0xEC; count($bits) < $capacity_bits; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $codewords = [];

        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = (int) bindec(implode('', $chunk));
        }

        $this->draw_function_patterns();
        $this->draw_codewords($this->add_ecc_and_interleave($codewords));

        $best = 0;
        $min  = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->apply_mask($mask);
            $this->draw_format_bits($mask);
            $penalty = $this->penalty();

            if ($penalty < $min) {
                $min  = $penalty;
                $best = $mask;
            }

            $this->apply_mask($mask); // XOR: wieder entfernen
        }

        $this->apply_mask($best);
        $this->draw_format_bits($best);

        return $this->modules;
    }

    private static function num_raw_data_modules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $align   = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function num_data_codewords(int $version, string $ecc): int
    {
        return intdiv(self::num_raw_data_modules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecc][$version] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecc][$version];
    }

    private function set_function(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x]     = $dark;
        $this->is_function[$y][$x] = true;
    }

    private function draw_function_patterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->set_function(6, $i, $i % 2 === 0);
            $this->set_function($i, 6, $i % 2 === 0);
        }

        $this->draw_finder(3, 3);
        $this->draw_finder($this->size - 4, 3);
        $this->draw_finder(3, $this->size - 4);

        $positions = $this->alignment_positions();
        $count     = count($positions);

        for ($i = 0; $i < $count; $i++) {
            for ($j = 0; $j < $count; $j++) {
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0))) {
                    $this->draw_alignment($positions[$i], $positions[$j]);
                }
            }
        }

        $this->draw_format_bits(0); // Platz reservieren, echte Bits nach der Maskenwahl
        $this->draw_version();
    }

    private function draw_finder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx   = $x + $dx;
                $yy   = $y + $dy;

                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->set_function($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function draw_alignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->set_function($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function alignment_positions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count  = intdiv($this->version, 7) + 2;
        $step   = $this->version === 32 ? 26 : (int) ceil(($this->version * 4 + 4) / ($count * 2 - 2)) * 2;
        $result = [6];

        for ($pos = $this->size - 7; count($result) < $count; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private function draw_format_bits(int $mask): void
    {
        $data = self::ECC_FORMAT[$this->ecc] << 3 | $mask;
        $rem  = $data;

        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }

        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit  = static fn (int $i): bool => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) {
            $this->set_function(8, $i, $bit($i));
        }

        $this->set_function(8, 7, $bit(6));
        $this->set_function(8, 8, $bit(7));
        $this->set_function(7, 8, $bit(8));

        for ($i = 9; $i < 15; $i++) {
            $this->set_function(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->set_function($this->size - 1 - $i, 8, $bit($i));
        }

        for ($i = 8; $i < 15; $i++) {
            $this->set_function(8, $this->size - 15 + $i, $bit($i));
        }

        $this->set_function(8, $this->size - 8, true);
    }

    private function draw_version(): void
    {
        if ($this->version < 7) {
            return;
        }

        $rem = $this->version;

        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }

        $bits = $this->version << 12 | $rem;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a    = $this->size - 11 + $i % 3;
            $b    = intdiv($i, 3);
            $this->set_function($a, $b, $dark);
            $this->set_function($b, $a, $dark);
        }
    }

    /**
     * @param array<int, int> $data
     * @return array<int, int>
     */
    private function add_ecc_and_interleave(array $data): array
    {
        $blocks_count = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecc][$this->version];
        $ecc_len      = self::ECC_CODEWORDS_PER_BLOCK[$this->ecc][$this->version];
        $raw          = intdiv(self::num_raw_data_modules($this->version), 8);
        $short_count  = $blocks_count - $raw % $blocks_count;
        $short_len    = intdiv($raw, $blocks_count);
        $divisor      = self::rs_divisor($ecc_len);
        $blocks       = [];
        $k            = 0;

        for ($i = 0; $i < $blocks_count; $i++) {
            $length = $short_len - $ecc_len + ($i < $short_count ? 0 : 1);
            $block  = array_slice($data, $k, $length);
            $k     += $length;
            $ecc    = self::rs_remainder($block, $divisor);

            if ($i < $short_count) {
                $block[] = 0;
            }

            $blocks[] = array_merge($block, $ecc);
        }

        $result = [];

        for ($i = 0, $n = count($blocks[0]); $i < $n; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $short_len - $ecc_len || $j >= $short_count) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /**
     * @return array<int, int>
     */
    private static function rs_divisor(int $degree): array
    {
        $result              = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root                = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::rs_multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::rs_multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param array<int, int> $data
     * @param array<int, int> $divisor
     * @return array<int, int>
     */
    private static function rs_remainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::rs_multiply($coef, $factor);
            }
        }

        return $result;
    }

    private static function rs_multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    /**
     * @param array<int, int> $data
     */
    private function draw_codewords(array $data): void
    {
        $i     = 0;
        $total = count($data) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x      = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y      = $upward ? $this->size - 1 - $vert : $vert;

                    if (!$this->is_function[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function apply_mask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert && !$this->is_function[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penalty(): int
    {
        $result = 0;
        $size   = $this->size;

        foreach ([false, true] as $columns) {
            for ($a = 0; $a < $size; $a++) {
                $run_color = false;
                $run       = 0;
                $history   = array_fill(0, 7, 0);

                for ($b = 0; $b < $size; $b++) {
                    $color = $columns ? $this->modules[$b][$a] : $this->modules[$a][$b];

                    if ($color === $run_color) {
                        $run++;

                        if ($run === 5) {
                            $result += 3;
                        } elseif ($run > 5) {
                            $result++;
                        }
                    } else {
                        $this->history_add($run, $history);

                        if (!$run_color) {
                            $result += $this->finder_patterns($history) * 40;
                        }

                        $run_color = $color;
                        $run       = 1;
                    }
                }

                if ($run_color) {
                    $this->history_add($run, $history);
                    $run = 0;
                }

                $this->history_add($run + $size, $history);
                $result += $this->finder_patterns($history) * 40;
            }
        }

        $dark = 0;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $color = $this->modules[$y][$x];
                $dark += $color ? 1 : 0;

                if ($y < $size - 1 && $x < $size - 1 && $color === $this->modules[$y][$x + 1] && $color === $this->modules[$y + 1][$x] && $color === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        $total = $size * $size;
        $k     = (int) ceil(abs($dark * 20 - $total * 10) / $total) - 1;

        return $result + $k * 10;
    }

    /**
     * @param array<int, int> $history
     */
    private function history_add(int $run, array &$history): void
    {
        if ($history[0] === 0) {
            $run += $this->size; // helle Ruhezone vor dem ersten Lauf
        }

        array_pop($history);
        array_unshift($history, $run);
    }

    /**
     * @param array<int, int> $history
     */
    private function finder_patterns(array $history): int
    {
        $n    = $history[1];
        $core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0) + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }
}
