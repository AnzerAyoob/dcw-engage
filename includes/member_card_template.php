<?php
/**
 * DCW Engage membership card (PDF and on-screen share the same design).
 *
 * member_card_html() returns the HTML for mPDF; it has no output of its own.
 * member_card_theme() / member_card_accent() hold the palette of each chapter.
 *
 * The card is 85.6 mm wide on an A4 page (height follows the content). Corners stay square because
 * mPDF cannot clip child cells to a rounded border.
 *
 * Layout, top to bottom:
 *   1. White header.
 *        Community card ("generic"): the DCW logo on the left, then the name. No right logo.
 *        Club card: the club's logo on the left, the club name and "Part of Deoband Community Wikimedia" in the
 *        middle, the DCW logo on the right.
 *   2. A thin rule in the chapter's logo colours (about 0.8 mm, no longer a heavy band).
 *   3. Tinted body. Left: username, Member ID (or title), "Works on" (main project, optional),
 *      "Also a member of". Right: the QR code on plain white, no coloured frame.
 *   4. "Issued by Deoband Community Wikimedia, Executive Board" with the Board's signature, signer and role.
 *      The signature sits directly on the card tint (its white background is removed), and the whole block is
 *      left out on the signer's own card.
 *   5. Footer: the motto in the accent colour under a hairline. No solid colour block.
 *
 * $c = [
 *   'chapter'        => 'generic' | 'amu' | 'jamia' | 'photographers',
 *   'member_id'      => 'D12345678',
 *   'primary_name'   => primary chapter name,
 *   'other_chapters' => [names of the other current chapters],
 *   'username'       => verified Wikimedia username or '',
 *   'title'          => optional title shown instead of the Member ID,
 *   'project'        => optional main project (from the profile), one line, cut if too long,
 *   'logo_uri'       => 'var:logo' or '',   'logo_ratio'     => width/height of that logo (float, 0 = unknown),
 *   'dcw_logo_uri'   => 'var:dcw' or '',    'dcw_logo_ratio' => width/height of the DCW logo,
 *   'qr_uri'         => 'var:qr',
 *   'issuer'         => optional "Issued by" block with the Board's signature:
 *                       ['signature_uri' => 'var:sig', 'signature_ratio' => w/h, 'name' => 'Signer', 'title' => 'Role'],
 *                       left out of the card when not passed, and also when the card belongs to the signer.
 * ]
 */

/**
 * Palette per chapter key.
 *   accent : main colour (title, labels, rule, footer text). Dark enough to read on white.
 *   ink    : dark shade used for username, Member ID and title.
 *   tint   : very light shade for the body background.
 *   stripe : colours of the thin rule under the header, left to right.
 */
function member_card_theme(string $chapter): array
{
    static $themes = [
        // Deoband Community Wikimedia: blue, red and green from the logo.
        'generic' => [
            'accent' => '#2d6499',
            'ink'    => '#1b3f66',
            'tint'   => '#eaf2fa',
            'stripe' => ['#2d6499', '#8f1c10', '#539a6d'],
        ],
        // Wiki Club AMU: navy and gold.
        'amu' => [
            'accent' => '#2c3e7e',
            'ink'    => '#1c2a5e',
            'tint'   => '#faf4e0',
            'stripe' => ['#2c3e7e', '#d4b05c'],
        ],
        // Wiki Club Jamia: mint, deep green and charcoal.
        'jamia' => [
            'accent' => '#1f7a5a',
            'ink'    => '#14513b',
            'tint'   => '#e8f6ef',
            'stripe' => ['#7bc4a0', '#1f7a5a', '#2b2b2b'],
        ],
        // DCW Photographers Club: Heritage Lens, the project the club grew out of.
        // Crimson #A81B29 is the exact colour in the Heritage Lens SVG; the wordmark is black.
        'photographers' => [
            'accent' => '#a81b29',
            'ink'    => '#6b1019',
            'tint'   => '#fbf4f1',
            'stripe' => ['#a81b29', '#111111'],
        ],
    ];
    return $themes[$chapter] ?? $themes['generic'];
}

/** Main colour of a chapter. Kept for views/member/card.php. */
function member_card_accent(string $chapter): string
{
    return member_card_theme($chapter)['accent'];
}

/** The name as printed on the card: the Generic Community is shown as Deoband Community Wikimedia. */
function member_card_display_name(string $name): string
{
    $n = trim($name);
    if ($n !== '' && (stripos($n, 'generic community') !== false || strcasecmp($n, 'DCW') === 0)) {
        return 'Deoband Community Wikimedia';
    }
    return $n;
}

/** Best guess of the chapter key from a chapter name, to colour the dot in "Also a member of". */
function member_card_key_for_name(string $name): string
{
    if (stripos($name, 'jamia') !== false) return 'jamia';
    if (preg_match('/\bamu\b|aligarh/i', $name)) return 'amu';
    if (stripos($name, 'photograph') !== false || stripos($name, 'heritage') !== false) return 'photographers';
    return 'generic';
}

/** One line of the main project, cut with an ellipsis so it never wraps and pushes the QR code. */
function member_card_project_line(string $project, int $max = 34): string
{
    $p = trim(preg_replace('/\s+/', ' ', $project));
    return $p === '' ? '' : mb_strimwidth($p, 0, $max, '…', 'UTF-8');
}

/**
 * Exceptions: members whose card shows a title instead of the Member ID.
 * Read from a private PHP file returning [ 'MEMBER_ID' => 'Title' ]; see MEMBER_CARD_TITLES_FILE.
 * A caller can also pass 'title' in $c, which wins over this list.
 */
function member_card_titles(): array
{
    static $titles = null;
    if ($titles === null) {
        $titles = [];
        $file = defined('MEMBER_CARD_TITLES_FILE') ? MEMBER_CARD_TITLES_FILE : dirname(__DIR__) . '/config/card_titles.php';
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data)) {
                $titles = $data;
            }
        }
    }
    return $titles;
}

/** The 'card' section of includes/config.php (empty array when missing). */
function member_card_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $file = __DIR__ . '/config.php';
        if (is_file($file)) {
            $all = include $file;
            if (is_array($all) && is_array($all['card'] ?? null)) {
                $cfg = $all['card'];
            }
        }
    }
    return $cfg;
}

/**
 * True when the card belongs to the person who signs the cards, so it carries no signature of their own.
 * Matched by Member ID(s) and/or Wikimedia username from the 'card' config section:
 *
 *   'signer_member_id' => 'D12345678',          // string or array of strings
 *   'signer_username'  => 'Aafi',               // optional; falls back to signer_name
 */
function member_card_is_signer(array $c): bool
{
    $cfg = member_card_config();

    $ids = array_filter(array_map('trim', array_map('strval', (array) ($cfg['signer_member_id'] ?? []))), 'strlen');
    $mid = trim((string) ($c['member_id'] ?? ''));
    if ($mid !== '' && in_array($mid, $ids, true)) {
        return true;
    }

    $user = trim((string) ($c['username'] ?? ''));
    if ($user !== '') {
        $names = array_filter(array_map('trim', array_map('strval', (array) ($cfg['signer_username'] ?? []))), 'strlen');
        if (!$names) {
            $fallback = trim((string) ($cfg['signer_name'] ?? ''));
            $names = $fallback !== '' ? [$fallback] : [];
        }
        foreach ($names as $n) {
            if (strcasecmp($user, $n) === 0) {
                return true;
            }
        }
    }
    return false;
}

/* ------------------------------------------------------------------------------------------------
 * Signature without its white background.
 * The signature file is cleaned once (cached in the temp folder) so the ink sits straight on the
 * card's tint: SVG files lose their white background shapes, raster images (PNG/JPG/GIF/WebP, also
 * the ones embedded inside an SVG) get their white turned into transparency.
 * ---------------------------------------------------------------------------------------------- */

/** PNG bytes of a raster image with white made transparent (ink colours un-blended from white). Null on failure. */
function member_card_raster_to_transparent_png(string $binary): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($binary);
    if (!$src) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);

    // Keep the work small; a signature never needs more than this.
    $maxW = 1200;
    if ($w > $maxW) {
        $nh = (int) round($h * $maxW / $w);
        $dst = imagecreatetruecolor($maxW, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
        $w = $maxW;
        $h = $nh;
    }

    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $clear = imagecolorallocatealpha($out, 255, 255, 255, 127);
    imagefill($out, 0, 0, $clear);

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $rgba = imagecolorat($src, $x, $y);
            $srcA = ($rgba >> 24) & 0x7F;               // 0 opaque .. 127 transparent
            if ($srcA >= 120) {
                continue;                               // already transparent
            }
            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            // Treat the pixel as ink laid over white: alpha from the darkest channel, then un-blend.
            $alpha = 255 - min($r, $g, $b);
            if ($alpha < 10) {
                continue;                               // white or almost white: stays transparent
            }
            $f = $alpha / 255;
            $r = (int) max(0, min(255, round(($r - 255 * (1 - $f)) / $f)));
            $g = (int) max(0, min(255, round(($g - 255 * (1 - $f)) / $f)));
            $b = (int) max(0, min(255, round(($b - 255 * (1 - $f)) / $f)));
            $a127 = 127 - (int) round($alpha * 127 / 255);
            imagesetpixel($out, $x, $y, imagecolorallocatealpha($out, $r, $g, $b, $a127));
        }
    }

    ob_start();
    imagepng($out);
    $png = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($out);
    return $png !== '' ? $png : null;
}

/** True for CSS/SVG colour values that mean white. */
function member_card_is_white_value(string $v): bool
{
    $v = strtolower(trim($v));
    if ($v === 'white' || $v === '#fff' || $v === '#ffffff' || $v === '#fffffe') {
        return true;
    }
    return (bool) preg_match('/^rgb\(\s*255\s*,\s*255\s*,\s*255\s*\)$/', $v);
}

/** SVG markup without white background shapes (rects/paths filled white) and with embedded rasters made transparent. */
function member_card_svg_strip_white(string $svg): string
{
    // White rectangles and white-filled, stroke-less paths are the "paper"; drop them.
    $svg = preg_replace_callback('/<(rect|path|polygon)\b[^>]*?\/?>(\s*<\/\1>)?/is', function ($m) {
        $tag = $m[0];
        $fill = null;
        if (preg_match('/\bfill\s*=\s*["\']([^"\']+)["\']/i', $tag, $f)) {
            $fill = $f[1];
        } elseif (preg_match('/style\s*=\s*["\'][^"\']*\bfill\s*:\s*([^;"\']+)/i', $tag, $f)) {
            $fill = $f[1];
        }
        if ($fill === null || !member_card_is_white_value($fill)) {
            return $tag;
        }
        $hasStroke = preg_match('/\bstroke\s*=\s*["\'](?!none)[^"\']+["\']/i', $tag)
                  || preg_match('/\bstroke\s*:\s*(?!none)[^;"\']+/i', $tag);
        return $hasStroke ? $tag : '';
    }, $svg) ?? $svg;

    // Background set on the root element.
    $svg = preg_replace('/(<svg\b[^>]*?)\s+style\s*=\s*"([^"]*)"/i', '$1 style="$2"', $svg) ?? $svg;
    $svg = preg_replace('/background(-color)?\s*:\s*(white|#fff(fff)?)\s*;?/i', '', $svg) ?? $svg;

    // Embedded raster images (data: URIs): make the white transparent.
    $svg = preg_replace_callback(
        '/(href\s*=\s*["\'])data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+\/=\s]+)(["\'])/i',
        function ($m) {
            $bin = base64_decode(preg_replace('/\s+/', '', $m[3]), true);
            if ($bin === false) {
                return $m[0];
            }
            $png = member_card_raster_to_transparent_png($bin);
            return $png === null ? $m[0] : $m[1] . 'data:image/png;base64,' . base64_encode($png) . $m[4];
        },
        $svg
    ) ?? $svg;

    return $svg;
}

/**
 * Path of a cleaned copy of the signature file (white background removed). Cached; the original file is never
 * touched. Returns the original path when it cannot be cleaned.
 */
function member_card_signature_file(string $file): string
{
    if ($file === '' || !is_file($file)) {
        return $file;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $key = md5($file . '|' . filemtime($file) . '|' . filesize($file) . '|sig2');
    $dir = sys_get_temp_dir();

    if ($ext === 'svg') {
        $out = $dir . '/dcw_sig_' . $key . '.svg';
        if (!is_file($out)) {
            $svg = (string) file_get_contents($file);
            if ($svg === '') return $file;
            if (@file_put_contents($out, member_card_svg_strip_white($svg)) === false) return $file;
        }
        return $out;
    }

    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
        // A PNG that already has an alpha channel is transparent as it is; leave it alone.
        if ($ext === 'png') {
            $head = (string) @file_get_contents($file, false, null, 0, 33);
            if (strlen($head) >= 26 && in_array(ord($head[25]), [4, 6], true)) {
                return $file;
            }
        }
        $out = $dir . '/dcw_sig_' . $key . '.png';
        if (!is_file($out)) {
            $png = member_card_raster_to_transparent_png((string) file_get_contents($file));
            if ($png === null || @file_put_contents($out, $png) === false) return $file;
        }
        return $out;
    }

    return $file;
}

/**
 * Who signs the cards, read from the 'card' section of includes/config.php:
 *
 *   'card' => [
 *       'signer_name'      => 'Aafi',
 *       'signer_title'     => 'Founder and Chair',
 *       'signature_file'   => 'config/board_signature.svg',   // optional; relative to the app root, or absolute
 *       'signer_member_id' => 'D12345678',                    // optional; the signer's own card has no signature block
 *       'signer_username'  => 'Aafi',                         // optional; defaults to signer_name
 *   ],
 *
 * Without signature_file the card looks for board_signature.png, then aafi.svg, in config/, assets/chapters/
 * and public/assets/chapters/ (under the app root). A PNG or JPG signature is cleaned of its white background.
 * With no signature file at all the card still shows the "Issued by" block and the signer line, just no image.
 * 'file' is a cleaned copy of the signature with the white background removed.
 */
function member_card_issuer_info(): array
{
    $cfg = member_card_config();

    $root = dirname(__DIR__);
    $candidates = [];
    $configured = trim((string) ($cfg['signature_file'] ?? ''));
    if ($configured !== '') {
        $candidates[] = $configured[0] === '/' ? $configured : $root . '/' . $configured;
    }
    foreach (['board_signature.png', 'aafi.svg'] as $name) {
        $candidates[] = $root . '/config/' . $name;
        $candidates[] = $root . '/assets/chapters/' . $name;
        $candidates[] = $root . '/public/assets/chapters/' . $name;
    }

    $found = '';
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) { $found = $candidate; break; }
    }
    return [
        'name'  => trim((string) ($cfg['signer_name'] ?? '')),
        'title' => trim((string) ($cfg['signer_title'] ?? '')),
        'file'  => $found !== '' ? member_card_signature_file($found) : '',
    ];
}

function member_card_html(array $c): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // Vertical space. mPDF honours a table row's height, but often ignores margin/padding on a div.
    $gap = fn(float $mm) => '<table cellpadding="0" cellspacing="0"><tr><td style="height: ' . $mm
         . 'mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td></tr></table>';

    // A logo fitted inside a box ($boxW x $boxH mm) whatever its shape, so wide and tall logos both sit
    // centred and neither crowds the text. Unknown ratio: treat as square.
    $fit = function (string $uri, float $ratio, float $boxW, float $boxH) use ($h): string {
        if ($uri === '') return '';
        $ratio = $ratio > 0 ? $ratio : 1.0;
        $style = $ratio > $boxW / $boxH
            ? 'width: ' . $boxW . 'mm;'
            : 'height: ' . $boxH . 'mm;';
        return '<img src="' . $h($uri) . '" style="' . $style . '" />';
    };

    $chapter = (string) ($c['chapter'] ?? 'generic');
    $t       = member_card_theme($chapter);
    $org     = 'Deoband Community Wikimedia';

    $primary = member_card_display_name((string) ($c['primary_name'] ?? ''));
    if ($primary === '') {
        $primary = $org;
    }
    $isOrg = $chapter === 'generic' || strcasecmp($primary, $org) === 0;

    // For a club the card says whose club it is; for the community itself that would only repeat the title.
    // Only the short "Membership card" line is set in tracked capitals; the longer line would overflow.
    $sub      = $isOrg ? 'Membership card' : 'Part of ' . $org;
    $subClass = $isOrg ? 's up' : 's';

    // Header. Both logos sit in fixed-width cells, centred, so the text never shifts with logo shape.
    $leftLogo = $fit((string) ($c['logo_uri'] ?? ''), (float) ($c['logo_ratio'] ?? 0), 15, 11);
    $leftCell = $leftLogo !== '' ? '<td class="lg">' . $leftLogo . '</td>' : '';

    $rightCell = '';
    if (!$isOrg && !empty($c['dcw_logo_uri'])) {
        $rightCell = '<td class="rg">' . $fit((string) $c['dcw_logo_uri'], (float) ($c['dcw_logo_ratio'] ?? 0), 12, 11) . '</td>';
    }
    $titleClass = $isOrg ? 't nw' : 't';
    // A club card with a right logo centres its name between the two logos.
    $textAlign = $rightCell !== '' ? 'text-align: center;' : '';

    // Thin rule under the header, one equal cell per colour.
    $stripe = '';
    $w = round(100 / max(1, count($t['stripe'])), 2);
    foreach ($t['stripe'] as $col) {
        $stripe .= '<td style="width: ' . $w . '%; background-color: ' . $col . '; height: 0.8mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td>';
    }

    // Username: bold, no label.
    $username = trim((string) ($c['username'] ?? ''));
    $who = $username !== '' ? '<div class="nm"><b>' . $h($username) . '</b></div>' . $gap(4) : '';

    // Member ID as plain text under its label (no rule, no box), or the member's title in its place.
    $title = trim((string) ($c['title'] ?? ''));
    if ($title === '') {
        $title = member_card_titles()[(string) ($c['member_id'] ?? '')] ?? '';
    }
    if ($title !== '') {
        $titleHtml = preg_replace('/,\s+/', ',<br />', $h($title), 1);
        $idBlock = '<table cellpadding="0" cellspacing="0"><tr><td class="role">' . $titleHtml . '</td></tr></table>';
    } else {
        $idBlock = '<div class="lb">Member ID</div>'
                 . '<div class="idv">' . $h($c['member_id'] ?? '') . '</div>';
    }

    // Main project: one short line, left out entirely when empty so there is no blank row.
    $projectLine = member_card_project_line((string) ($c['project'] ?? ''));
    $project = $projectLine !== ''
        ? $gap(3.5) . '<div class="lb">Works on</div><div class="pj">' . $h($projectLine) . '</div>'
        : '';

    // Other current chapters: a coloured dot and the name. At most two names plus "+N more".
    $others = '';
    $list = array_values(array_filter(array_map(
        fn($n) => member_card_display_name((string) $n),
        (array) ($c['other_chapters'] ?? [])
    ), 'strlen'));
    if ($list) {
        $others = $gap(3.5) . '<div class="lb">Also a member of</div>' . $gap(1);
        foreach (array_slice($list, 0, 2) as $n) {
            $dot = member_card_theme(member_card_key_for_name($n))['accent'];
            $others .= '<div class="ch"><span style="color: ' . $dot . ';">&#9679;</span>&nbsp;&nbsp;' . $h($n) . '</div>' . $gap(0.6);
        }
        if (count($list) > 2) {
            $others .= '<div class="ch" style="color: #64748b;">+' . (count($list) - 2) . ' more</div>';
        }
    }

    // "Issued by" block: the community's name on the left, the Board's signature on the right.
    // The signer's own card does not carry it. Fixed column widths and a no-wrap name keep it on one line,
    // so the card is the same height for every chapter.
    $issuerRow = '';
    $iss = (array) ($c['issuer'] ?? []);
    if ($iss && !member_card_is_signer($c)) {
        $sig     = $fit((string) ($iss['signature_uri'] ?? ''), (float) ($iss['signature_ratio'] ?? 0), 24, 9);
        $sigImg  = $sig !== '' ? '<div style="text-align: center;">' . $sig . '</div>' : '';
        $signer  = trim((string) ($iss['name'] ?? ''));
        $role    = trim((string) ($iss['title'] ?? ''));
        $signHtml = $signer !== ''
            ? '<div class="sgn">' . $h($signer) . '</div>' . ($role !== '' ? '<div class="sgt">' . $h($role) . '</div>' : '')
            : '<div class="sgn">' . $h($role !== '' ? $role : 'Authorised signatory') . '</div>';
        $issuerRow = '<tr><td class="issue" colspan="2"><table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>'
            . '<td class="isl"><div class="lb">Issued by</div><div class="isn">' . $h($org) . '</div><div class="iss">Executive Board</div></td>'
            . '<td class="isr">' . $sigImg
            . '<table cellpadding="0" cellspacing="0" align="center" style="width: 24mm;"><tr><td class="sigline">&nbsp;</td></tr></table>'
            . $signHtml . '</td>'
            . '</tr></table></td></tr>';
    }

    // Motto in the accent colour, diamonds in the second logo colour.
    $diamond = $t['stripe'][1] ?? $t['accent'];
    $sep   = '&nbsp;&nbsp;<span style="color: ' . $diamond . '; font-size: 6pt;">&#9670;</span>&nbsp;&nbsp;';
    $motto = implode($sep, ['Identify', 'Learn', 'Think', 'Mentor']);

    $tpl = '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    table.card { width: 85.6mm; border: 0.3mm solid {{accent}}; border-collapse: separate; }

    td.head { background-color: #ffffff; padding: 3mm 4mm; }
    td.lg { width: 17mm; text-align: center; vertical-align: middle; }
    td.rg { width: 15mm; text-align: center; vertical-align: middle; }
    td.bt { vertical-align: middle; padding: 0 1mm; }
    .t { font-size: 9.5pt; font-weight: bold; color: {{ink}}; line-height: 1.3; }
    .t.nw { white-space: nowrap; }
    .s { font-size: 6pt; color: #475569; margin-top: 0.4mm; }
    .s.up { text-transform: uppercase; letter-spacing: 0.3mm; font-size: 5.5pt; }

    td.stripe { padding: 0; }

    td.left  { background-color: {{tint}}; padding: 5mm 2mm 4mm 5mm; vertical-align: middle; width: 50mm; }
    td.right { background-color: {{tint}}; padding: 5mm 5mm 4mm 0; vertical-align: middle; text-align: center; width: 34mm; }

    .lb { font-size: 5.5pt; font-weight: bold; color: {{accent}}; text-transform: uppercase; letter-spacing: 0.35mm; margin: 0 0 0.8mm; }
    .nm { font-size: 13pt; font-weight: bold; color: {{ink}}; }
    .ch { font-size: 7pt; color: #334155; }
    .pj { font-size: 7.5pt; font-weight: bold; color: {{ink}}; white-space: nowrap; }

    .idv { color: {{ink}}; font-family: dejavusansmono, monospace; font-size: 10pt; letter-spacing: 0.3mm; }

    td.role { border-left: 0.8mm solid {{accent}}; padding: 0.4mm 0 0.4mm 2.6mm; font-size: 8.5pt; font-weight: bold; color: {{ink}}; line-height: 1.4; }

    td.qr { background-color: #ffffff; padding: 1.2mm; }
    .scan { font-size: 6pt; font-weight: bold; color: {{accent}}; text-align: center; }

    td.issue { background-color: {{tint}}; }
    td.isl { width: 52mm; padding: 3mm 1mm 3mm 5mm; vertical-align: bottom; white-space: nowrap; }
    td.isr { width: 30mm; padding: 3mm 3mm 3mm 0; vertical-align: bottom; text-align: center; }
    .isn { font-size: 7.5pt; font-weight: bold; color: {{ink}}; line-height: 1.3; white-space: nowrap; }
    .iss { font-size: 6.5pt; color: #475569; white-space: nowrap; }
    td.sigline { border-top: 0.25mm solid {{ink}}; font-size: 1pt; line-height: 1pt; }
    .sgn { font-size: 6.5pt; font-weight: bold; color: {{ink}}; text-align: center; margin-top: 0.6mm; }
    .sgt { font-size: 5.5pt; color: #475569; text-align: center; }

    td.foot { background-color: #ffffff; border-top: 0.2mm solid #d6dbe1; color: {{accent}}; padding: 2.4mm 4mm; font-size: 7pt; font-weight: bold;
              letter-spacing: 0.3mm; text-align: center; }
    .cut { margin-top: 3mm; font-size: 7pt; color: #94a3b8; }
</style>

<table cellpadding="0" cellspacing="0">
    <tr>
        <td style="width: 5mm; height: 5mm; border-right: 0.2mm solid #cccccc; border-bottom: 0.2mm solid #cccccc;"></td>
        <td></td>
        <td style="width: 5mm; border-left: 0.2mm solid #cccccc; border-bottom: 0.2mm solid #cccccc;"></td>
    </tr>
    <tr>
        <td></td>
        <td>
<table class="card" cellpadding="0" cellspacing="0">
    <tr><td class="head" colspan="2">
        <table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>{{leftcell}}
            <td class="bt" style="{{textalign}}">
                <div class="{{titleclass}}">{{primary}}</div>
                <div class="{{subclass}}">{{sub}}</div>
            </td>{{rightcell}}
        </tr></table>
    </td></tr>
    <tr><td class="stripe" colspan="2">
        <table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>{{stripe}}</tr></table>
    </td></tr>
    <tr>
        <td class="left">
            {{who}}
            {{id_block}}
            {{project}}
            {{others}}
        </td>
        <td class="right">
            <table cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto;"><tr><td class="qr">
                <img src="{{qr}}" style="width: 21mm; height: 21mm;" />
            </td></tr></table>
            {{scan_gap}}
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    {{issuer_row}}
    <tr><td class="foot" colspan="2">{{motto}}</td></tr>
</table>
        </td>
        <td></td>
    </tr>
    <tr>
        <td style="height: 5mm; border-right: 0.2mm solid #cccccc; border-top: 0.2mm solid #cccccc;"></td>
        <td></td>
        <td style="border-left: 0.2mm solid #cccccc; border-top: 0.2mm solid #cccccc;"></td>
    </tr>
</table>
<div class="cut">Confirms membership only, not identity. Ask for photo ID if unsure.<br />Print at 100% (no scaling) and cut at the corner marks.</div>';

    return strtr($tpl, [
        '{{accent}}'     => $t['accent'],
        '{{ink}}'        => $t['ink'],
        '{{tint}}'       => $t['tint'],
        '{{leftcell}}'   => $leftCell,
        '{{rightcell}}'  => $rightCell,
        '{{textalign}}'  => $textAlign,
        '{{titleclass}}' => $titleClass,
        '{{subclass}}'   => $subClass,
        '{{sub}}'        => $h($sub),
        '{{primary}}'    => $h($primary),
        '{{stripe}}'     => $stripe,
        '{{who}}'        => $who,
        '{{id_block}}'   => $idBlock,
        '{{project}}'    => $project,
        '{{others}}'     => $others,
        '{{qr}}'         => $h($c['qr_uri'] ?? ''),
        '{{scan_gap}}'   => $gap(2),
        '{{issuer_row}}' => $issuerRow,
        '{{motto}}'      => $motto,
    ]);
}
