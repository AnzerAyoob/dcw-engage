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
 *        Club card: the club's logo on the left, the club name in the middle, the DCW logo on the right.
 *   2. A thin rule in the chapter's logo colours (about 0.8 mm, no longer a heavy band).
 *   3. Tinted body. Left: username, Member ID (or title), "Works on" (main project, optional),
 *      "Also a member of". Right: the QR code on plain white, no coloured frame.
 *   4. Footer: the motto in the accent colour under a hairline. No solid colour block.
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

    // Member ID as plain text over a thin rule (no box), or the member's title in its place.
    $title = trim((string) ($c['title'] ?? ''));
    if ($title === '') {
        $title = member_card_titles()[(string) ($c['member_id'] ?? '')] ?? '';
    }
    if ($title !== '') {
        $titleHtml = preg_replace('/,\s+/', ',<br />', $h($title), 1);
        $idBlock = '<table cellpadding="0" cellspacing="0"><tr><td class="role">' . $titleHtml . '</td></tr></table>';
    } else {
        $idBlock = '<div class="lb">Member ID</div>'
                 . '<table cellpadding="0" cellspacing="0"><tr><td class="idv">' . $h($c['member_id'] ?? '') . '</td></tr></table>';
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
    .s { font-size: 5.5pt; color: #475569; margin-top: 0.4mm; text-transform: uppercase; letter-spacing: 0.3mm; }

    td.stripe { padding: 0; }

    td.left  { background-color: {{tint}}; padding: 5mm 2mm 4mm 5mm; vertical-align: middle; width: 50mm; }
    td.right { background-color: {{tint}}; padding: 5mm 5mm 4mm 0; vertical-align: middle; text-align: center; width: 34mm; }

    .lb { font-size: 5.5pt; font-weight: bold; color: {{accent}}; text-transform: uppercase; letter-spacing: 0.35mm; margin: 0 0 0.8mm; }
    .nm { font-size: 13pt; font-weight: bold; color: {{ink}}; }
    .ch { font-size: 7pt; color: #334155; }
    .pj { font-size: 7.5pt; font-weight: bold; color: {{ink}}; white-space: nowrap; }

    td.idv { border-bottom: 0.3mm solid {{accent}}; color: {{ink}};
             font-family: dejavusansmono, monospace; font-size: 10pt;
             letter-spacing: 0.3mm; padding: 0 3mm 0.6mm 0; }

    td.role { border-left: 0.8mm solid {{accent}}; padding: 0.4mm 0 0.4mm 2.6mm; font-size: 8.5pt; font-weight: bold; color: {{ink}}; line-height: 1.4; }

    td.qr { background-color: #ffffff; padding: 1.2mm; }
    .scan { font-size: 6pt; font-weight: bold; color: {{accent}}; text-align: center; }

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
                <div class="s">Membership card</div>
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
        '{{primary}}'    => $h($primary),
        '{{stripe}}'     => $stripe,
        '{{who}}'        => $who,
        '{{id_block}}'   => $idBlock,
        '{{project}}'    => $project,
        '{{others}}'     => $others,
        '{{qr}}'         => $h($c['qr_uri'] ?? ''),
        '{{scan_gap}}'   => $gap(2),
        '{{motto}}'      => $motto,
    ]);
}
