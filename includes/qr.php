<?php
/**
 * DCW Engage - small self-contained QR code encoder. No libraries, no network.
 *
 * Byte mode, error correction level M (about 15% of the code can be damaged and it still scans),
 * QR versions 1 to 6. That fits up to 106 bytes, so a short link such as
 * https://engage.example.org/card/<32 hex characters> (about 64 bytes) is version 5.
 *
 * This is a line-by-line port of a reference implementation that was checked against a real QR
 * decoder for every version and every mask pattern. tools/qr_selftest.php proves this copy produces
 * exactly the same modules as that reference.
 *
 * Use:
 *   QrCode::svg($url)      // an <svg> string, scales to any size with CSS
 *   QrCode::png($url)      // PNG image bytes (black and white, no libraries needed), for PDFs and e-mail
 *   QrCode::matrix($url)   // ['size' => int, 'version' => int, 'modules' => bool[row][col]]
 *                          // for drawing the code yourself
 */
final class QrCode
{
    /** version => [error-correction codewords per block, number of blocks, data codewords per block] (level M) */
    private const VER = [1 => [10, 1, 16], 2 => [16, 1, 28], 3 => [26, 1, 44], 4 => [18, 2, 32], 5 => [24, 2, 43], 6 => [16, 4, 27]];

    /** Alignment pattern centre positions per version. */
    private const ALIGN = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];

    private static $exp = null;
    private static $log = null;

    // ---- Public ---------------------------------------------------------------------------------

    /** The code as a matrix of modules (true = dark), without the quiet zone. */
    public static function matrix(string $text): array
    {
        [$version, $codewords] = self::encodeCodewords($text);
        $best = null;
        for ($mask = 0; $mask < 8; $mask++) {
            $mat = self::buildMatrix($version, $codewords, $mask);
            $p = self::penalty($mat);
            if ($best === null || $p < $best[0]) {
                $best = [$p, $mask, $mat];
            }
        }
        return ['size' => 17 + 4 * $version, 'version' => $version, 'mask' => $best[1], 'modules' => $best[2]];
    }

    /**
     * An SVG of the code, with the quiet zone (4 modules) a scanner needs. It has no fixed width or
     * height, so size it with CSS (for example width: 220px).
     */
    public static function svg(string $text, string $label = 'QR code', string $fg = '#000000', string $bg = '#ffffff'): string
    {
        $m = self::matrix($text);
        $size = $m['size'];
        $quiet = 4;
        $total = $size + 2 * $quiet;

        // One rectangle per horizontal run of dark modules keeps the file small.
        $d = '';
        for ($y = 0; $y < $size; $y++) {
            $x = 0;
            while ($x < $size) {
                if ($m['modules'][$y][$x]) {
                    $start = $x;
                    while ($x < $size && $m['modules'][$y][$x]) {
                        $x++;
                    }
                    $len = $x - $start;
                    $d .= 'M' . ($start + $quiet) . ' ' . ($y + $quiet) . 'h' . $len . 'v1h-' . $len . 'z';
                } else {
                    $x++;
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" role="img" aria-label="'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" shape-rendering="crispEdges">'
            . '<rect width="' . $total . '" height="' . $total . '" fill="' . $bg . '"/>'
            . '<path d="' . $d . '" fill="' . $fg . '"/></svg>';
    }

    /**
     * A PNG of the code (1-bit black and white, quiet zone included), as raw bytes. Written by hand,
     * so it needs only PHP's zlib. $scale is pixels per module: 10 gives about 450 px for a
     * version 5 code, plenty for printing at 30 mm.
     * To embed it in HTML (for example for mPDF): 'data:image/png;base64,' . base64_encode(QrCode::png($url))
     */
    public static function png(string $text, int $scale = 10): string
    {
        $scale = max(1, min(40, $scale));
        $m = self::matrix($text);
        $size = $m['size'];
        $quiet = 4;
        $modulesWide = $size + 2 * $quiet;
        $w = $modulesWide * $scale;

        $raw = '';
        for ($my = 0; $my < $modulesWide; $my++) {
            $row = $my - $quiet;
            $line = '';
            $byte = 0;
            $bitCount = 0;
            for ($x = 0; $x < $w; $x++) {
                $col = intdiv($x, $scale) - $quiet;
                $dark = ($row >= 0 && $row < $size && $col >= 0 && $col < $size) ? $m['modules'][$row][$col] : false;
                $byte = ($byte << 1) | ($dark ? 0 : 1);   // in a 1-bit grayscale PNG, 0 is black and 1 is white
                $bitCount++;
                if ($bitCount === 8) {
                    $line .= chr($byte);
                    $byte = 0;
                    $bitCount = 0;
                }
            }
            if ($bitCount > 0) {
                $line .= chr($byte << (8 - $bitCount));
            }
            $raw .= str_repeat("\0" . $line, $scale);   // each scanline starts with filter type 0
        }

        $chunk = function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $w, 1, 0, 0, 0, 0))
            . $chunk('IDAT', gzcompress($raw, 9))
            . $chunk('IEND', '');
    }

    // ---- Galois field and Reed-Solomon ------------------------------------------------------------

    private static function initGf(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** Generator polynomial of degree $n, highest degree first (length n + 1). */
    private static function generator(int $n): array
    {
        $g = [1];
        for ($i = 0; $i < $n; $i++) {
            $nxt = array_fill(0, count($g) + 1, 0);
            for ($j = 0; $j < count($g); $j++) {
                $nxt[$j] ^= $g[$j];
                $nxt[$j + 1] ^= self::mul($g[$j], self::$exp[$i]);
            }
            $g = $nxt;
        }
        return $g;
    }

    /** The $n error-correction codewords for a block of data codewords. */
    private static function remainder(array $data, int $n): array
    {
        $g = self::generator($n);
        $rem = array_fill(0, $n, 0);
        foreach ($data as $b) {
            $factor = $b ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($i = 0; $i < $n; $i++) {
                $rem[$i] ^= self::mul($g[$i + 1], $factor);
            }
        }
        return $rem;
    }

    // ---- Data -> codewords --------------------------------------------------------------------------

    /** @return array [version, interleaved data + error-correction codewords] */
    private static function encodeCodewords(string $text): array
    {
        self::initGf();
        if ($text === '') {
            throw new InvalidArgumentException('Nothing to encode.');
        }
        $payload = array_values(unpack('C*', $text));
        $n = count($payload);

        $version = null;
        for ($v = 1; $v <= 6; $v++) {
            [$ec, $blocks, $per] = self::VER[$v];
            if (4 + 8 + 8 * $n <= $blocks * $per * 8) {
                $version = $v;
                break;
            }
        }
        if ($version === null) {
            throw new InvalidArgumentException('Too long for a QR code of this size (106 bytes at most).');
        }
        [$ec, $blocks, $per] = self::VER[$version];
        $cap = $blocks * $per;

        $bits = [];
        $put = function (int $val, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $put(4, 4);          // byte mode
        $put($n, 8);         // character count
        foreach ($payload as $b) {
            $put($b, 8);
        }
        $put(0, min(4, $cap * 8 - count($bits)));   // terminator
        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $data[] = bindec(implode('', array_slice($bits, $i, 8)));
        }
        $k = 0;
        while (count($data) < $cap) {
            $data[] = ($k % 2 === 0) ? 0xEC : 0x11;
            $k++;
        }

        $blks = [];
        $ecs = [];
        for ($i = 0; $i < $blocks; $i++) {
            $blk = array_slice($data, $i * $per, $per);
            $blks[] = $blk;
            $ecs[] = self::remainder($blk, $ec);
        }

        $out = [];
        for ($i = 0; $i < $per; $i++) {
            foreach ($blks as $b) {
                $out[] = $b[$i];
            }
        }
        for ($i = 0; $i < $ec; $i++) {
            foreach ($ecs as $e) {
                $out[] = $e[$i];
            }
        }
        return [$version, $out];
    }

    // ---- Matrix ---------------------------------------------------------------------------------------

    private static function buildMatrix(int $version, array $codewords, int $mask): array
    {
        $size = 17 + 4 * $version;
        $mod = array_fill(0, $size, array_fill(0, $size, false));
        $fn  = array_fill(0, $size, array_fill(0, $size, false));

        $setf = function (int $x, int $y, bool $dark) use (&$mod, &$fn): void {
            $mod[$y][$x] = $dark;
            $fn[$y][$x] = true;
        };

        // Timing patterns
        for ($i = 0; $i < $size; $i++) {
            $setf(6, $i, $i % 2 === 0);
            $setf($i, 6, $i % 2 === 0);
        }

        // Finder patterns with their separators
        $finder = function (int $cx, int $cy) use ($size, $setf): void {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $size && $y >= 0 && $y < $size) {
                        $dist = max(abs($dx), abs($dy));
                        $setf($x, $y, $dist !== 2 && $dist !== 4);
                    }
                }
            }
        };
        $finder(3, 3);
        $finder($size - 4, 3);
        $finder(3, $size - 4);

        // Alignment patterns (not where they would overlap a finder)
        $pos = self::ALIGN[$version];
        $n = count($pos);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $setf($pos[$i] + $dx, $pos[$j] + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // Reserve the format areas (the real bits are drawn after the mask is applied)
        self::drawFormat($mod, $fn, $size, 0);

        // Data bits, with remainder bits for versions 2 and up
        $bits = [];
        foreach ($codewords as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($cw >> $i) & 1;
            }
        }
        if ($version >= 2) {
            for ($i = 0; $i < 7; $i++) {
                $bits[] = 0;
            }
        }

        // Place them in the zigzag order, two columns at a time from the bottom right
        $k = 0;
        $total = count($bits);
        $right = $size - 1;
        while ($right >= 1) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $size - 1 - $vert : $vert;
                    if (!$fn[$y][$x] && $k < $total) {
                        $mod[$y][$x] = ($bits[$k] === 1);
                        $k++;
                    }
                }
            }
            $right -= 2;
        }

        // Mask, then the real format bits
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($fn[$y][$x]) {
                    continue;
                }
                if (self::maskBit($mask, $y, $x)) {
                    $mod[$y][$x] = !$mod[$y][$x];
                }
            }
        }
        self::drawFormat($mod, $fn, $size, $mask);
        return $mod;
    }

    private static function maskBit(int $m, int $i, int $j): bool
    {
        switch ($m) {
            case 0: return ($i + $j) % 2 === 0;
            case 1: return $i % 2 === 0;
            case 2: return $j % 3 === 0;
            case 3: return ($i + $j) % 3 === 0;
            case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
            case 5: return ($i * $j) % 2 + ($i * $j) % 3 === 0;
            case 6: return ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0;
            default: return ((($i + $j) % 2) + (($i * $j) % 3)) % 2 === 0;
        }
    }

    /** Format information: error correction level M (bits 00) and the mask number, protected by BCH. */
    private static function drawFormat(array &$mod, array &$fn, int $size, int $mask): void
    {
        $data = (0 << 3) | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $gb = function (int $i) use ($bits): bool {
            return (($bits >> $i) & 1) === 1;
        };
        $set = function (int $x, int $y, bool $dark) use (&$mod, &$fn): void {
            $mod[$y][$x] = $dark;
            $fn[$y][$x] = true;
        };

        for ($i = 0; $i < 6; $i++) {
            $set(8, $i, $gb($i));
        }
        $set(8, 7, $gb(6));
        $set(8, 8, $gb(7));
        $set(7, 8, $gb(8));
        for ($i = 9; $i < 15; $i++) {
            $set(14 - $i, 8, $gb($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, $gb($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set(8, $size - 15 + $i, $gb($i));
        }
        $set(8, $size - 8, true);   // the always-dark module
    }

    // ---- Choosing the mask ------------------------------------------------------------------------------

    /** The standard four penalty rules. A lower score scans more reliably. */
    private static function penalty(array $mod): int
    {
        $size = count($mod);
        $total = 0;

        // Columns as rows, so rows and columns are scored the same way
        $cols = [];
        for ($x = 0; $x < $size; $x++) {
            $col = [];
            for ($y = 0; $y < $size; $y++) {
                $col[] = $mod[$y][$x];
            }
            $cols[] = $col;
        }

        // Rule 1: runs of five or more of the same colour
        foreach ([$mod, $cols] as $lines) {
            foreach ($lines as $row) {
                $run = 1;
                for ($i = 1; $i < $size; $i++) {
                    if ($row[$i] === $row[$i - 1]) {
                        $run++;
                    } else {
                        if ($run >= 5) {
                            $total += 3 + ($run - 5);
                        }
                        $run = 1;
                    }
                }
                if ($run >= 5) {
                    $total += 3 + ($run - 5);
                }
            }
        }

        // Rule 2: 2x2 blocks of one colour
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $mod[$y][$x];
                if ($c === $mod[$y][$x + 1] && $c === $mod[$y + 1][$x] && $c === $mod[$y + 1][$x + 1]) {
                    $total += 3;
                }
            }
        }

        // Rule 3: patterns that look like a finder pattern
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        foreach ([$mod, $cols] as $lines) {
            foreach ($lines as $row) {
                for ($i = 0; $i < $size - 10; $i++) {
                    $seg = [];
                    for ($k = 0; $k < 11; $k++) {
                        $seg[] = $row[$i + $k] ? 1 : 0;
                    }
                    if ($seg === $p1 || $seg === $p2) {
                        $total += 40;
                    }
                }
            }
        }

        // Rule 4: balance of dark and light
        $dark = 0;
        foreach ($mod as $row) {
            foreach ($row as $v) {
                if ($v) {
                    $dark++;
                }
            }
        }
        $cells = $size * $size;
        $k = intdiv(abs($dark * 20 - $cells * 10) + $cells - 1, $cells) - 1;
        $total += $k * 10;

        return $total;
    }
}
