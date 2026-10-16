<?php
/**
 * DCW Engage membership card (PDF and on-screen share the same design).
 *
 * member_card_html() returns the HTML for mPDF (views/member/card_pdf.php); it has no output of its own.
 * member_card_accent() gives the chapter's main colour and is also used by views/member/card.php.
 * member_card_theme() holds the full palette of each chapter, taken from its logo.
 *
 * The card is credit-card size (85.6 mm wide; the height now follows the content) on an A4 page, so it can be printed and cut out.
 * Faint corner marks sit outside the card to guide the scissors.
 *
 * Layout, top to bottom (identical for every chapter, only the palette changes):
 *   1. White header: the chapter's logo and the chapter name in the chapter's colour.
 *   2. A stripe in the logo's own colours.
 *   3. Tinted body. Left: the Wikimedia username (bold, no label), a gap, then the Member ID.
 *      Right: the QR code in a framed white box.
 *   4. Optional full-width row of small pills: the member's other current chapters.
 *   5. A solid colour footer with the motto: Identify, Learn, Think, Mentor.
 *
 * The PDF deliberately shows no status or dates: those change, and the QR code always shows the live
 * answer. It shows the Wikimedia username, not the person's real name.
 *
 * The chapter "DCW Generic Community" is always printed as "Deoband Community Wikimedia" here
 * (see member_card_display_name()), whatever the stored chapter name is.
 *
 * $c = [
 *   'chapter'        => chapter key ('generic', 'amu', 'jamia', 'photographers'), picks the palette,
 *   'member_id'      => 'D12345678',
 *   'primary_name'   => the primary chapter's name, the top line of the card,
 *   'other_chapters' => [names of the other current chapters],
 *   'username'       => verified Wikimedia username, or '' to leave it off,
 *   'logo_uri'       => 'var:logo' (mPDF image variable) or '' for none,
 *   'qr_uri'         => 'var:qr',
 * ]
 */

/**
 * Palette per chapter key. Colours are taken from each chapter's logo.
 *   accent : main colour (header title, labels, ID chip border, QR frame, footer). Dark enough for white text.
 *   ink    : dark shade of the accent, used for the card title, the username and the Member ID.
 *   tint   : very light shade of the accent, the background of the body.
 *   stripe : the colours of the band under the header, left to right.
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
        // DCW Photographers Club: placeholder warm palette until its logo is added.
        'photographers' => [
            'accent' => '#b45309',
            'ink'    => '#7c3a07',
            'tint'   => '#fff3e0',
            'stripe' => ['#b45309', '#f59e0b', '#1f2937'],
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
    if (stripos($name, 'photograph') !== false) return 'photographers';
    return 'generic';
}

function member_card_html(array $c): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $chapter = (string) ($c['chapter'] ?? 'generic');
    $t       = member_card_theme($chapter);
    $org     = 'Deoband Community Wikimedia';

    $primary = member_card_display_name((string) ($c['primary_name'] ?? ''));
    if ($primary === '') {
        $primary = $org;
    }

    // For a club the card says whose club it is; for the community itself that would only repeat the title.
    // Only the short "Membership card" line is set in tracked capitals; the longer line would overflow.
    $isOrg    = $chapter === 'generic' || strcasecmp($primary, $org) === 0;
    $sub      = $isOrg ? 'Membership card' : 'Part of ' . $org;
    $subClass = $isOrg ? 's up' : 's';

    $logo = !empty($c['logo_uri'])
        ? '<td class="lg"><img src="' . $h($c['logo_uri']) . '" style="height: 9mm;" /></td>'
        : '';

    // Stripe under the header, one equal cell per colour.
    $stripe = '';
    $w = round(100 / max(1, count($t['stripe'])), 2);
    foreach ($t['stripe'] as $col) {
        $stripe .= '<td style="width: ' . $w . '%; background-color: ' . $col . '; height: 1.5mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td>';
    }

    // Username: bold, no label. Without one, the Member ID simply stands alone.
    $username = trim((string) ($c['username'] ?? ''));
    // The gap is a table row with a fixed height: mPDF honours that, but often ignores margin/padding on a div.
    $who = $username !== ''
        ? '<table cellpadding="0" cellspacing="0"><tr><td class="nm" style="font-weight: bold;"><b>' . $h($username) . '</b></td></tr>'
          . '<tr><td style="height: 7mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td></tr></table>'
        : '';

    // Other current chapters: small pills in a full-width row under the body.
    // At most two names plus a "+N more" pill, so the row stays on one line and the card keeps its size.
    $othersRow = '';
    $list = array_values(array_filter(array_map(
        fn($n) => member_card_display_name((string) $n),
        (array) ($c['other_chapters'] ?? [])
    ), 'strlen'));
    if ($list) {
        $cells = '';
        foreach (array_slice($list, 0, 2) as $n) {
            $dot = member_card_theme(member_card_key_for_name($n))['accent'];
            $cells .= '<td class="pill"><span style="color: ' . $dot . ';">&#9679;</span>&nbsp;' . $h($n) . '</td><td style="width: 1.2mm;"></td>';
        }
        if (count($list) > 2) {
            $cells .= '<td class="pill">+' . (count($list) - 2) . ' more</td>';
        }
        $others = '<div class="lb" style="margin: 0 0 1.5mm;">Also a member of</div>'
                . '<table cellpadding="0" cellspacing="0" style="border-collapse: separate;"><tr>' . $cells . '</tr></table>';
        $othersRow = '<tr><td class="chapters" colspan="2">' . $others . '</td></tr>';
    }

    $tpl = '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    table.card { width: 85.6mm; border: 0.5mm solid {{accent}}; border-collapse: separate; border-radius: 3mm; }

    td.head { background-color: #ffffff; padding: 3mm 5mm; border-radius: 2.5mm 2.5mm 0 0; }
    td.lg { padding-right: 3mm; vertical-align: middle; }
    td.bt { vertical-align: middle; }
    .t { font-size: 9.5pt; font-weight: bold; color: {{ink}}; line-height: 1.3; white-space: nowrap; }
    .s { font-size: 6pt; color: #475569; margin-top: 0.4mm; }
    .s.up { text-transform: uppercase; letter-spacing: 0.3mm; font-size: 5.5pt; }

    td.stripe { padding: 0; }

    td.left  { background-color: {{tint}}; padding: 5mm 2mm 5mm 5mm; vertical-align: middle; width: 53mm; }
    td.right { background-color: {{tint}}; padding: 5mm 2mm 5mm 2mm; vertical-align: middle; text-align: center; width: 31mm; }

    .lb { font-size: 5.5pt; font-weight: bold; color: {{accent}}; text-transform: uppercase; letter-spacing: 0.35mm; margin: 0 0 0.8mm; }
    td.nm { font-size: 13pt; font-weight: bold; color: {{ink}}; padding: 0; }

    td.chip { background-color: #ffffff; border: 0.25mm solid {{accent}}; color: {{ink}};
              font-family: dejavusansmono, monospace; font-size: 10pt; font-weight: normal;
              letter-spacing: 0.3mm; padding: 0.8mm 2.4mm; border-radius: 1.2mm; }

    td.qr { background-color: #ffffff; border: 0.5mm solid {{accent}}; border-radius: 2mm; padding: 1mm; }
    .scan { font-size: 6pt; font-weight: bold; color: {{accent}}; margin-top: 1.2mm; }

    td.chapters { background-color: {{tint}}; padding: 0 5mm 4mm 5mm; }
    td.pill { background-color: #ffffff; border: 0.2mm solid #cbd5e1; border-radius: 2mm;
              padding: 0.5mm 2mm; font-size: 6pt; color: #334155; }

    td.foot { background-color: {{accent}}; color: #ffffff; padding: 2.6mm 4mm; font-size: 7pt; font-weight: bold; letter-spacing: 0.4mm; text-align: center;
              border-radius: 0 0 2.5mm 2.5mm; }
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
        <table cellpadding="0" cellspacing="0"><tr>{{logo}}
            <td class="bt">
                <div class="t">{{primary}}</div>
                <div class="{{subclass}}">{{sub}}</div>
            </td>
        </tr></table>
    </td></tr>
    <tr><td class="stripe" colspan="2">
        <table cellpadding="0" cellspacing="0" style="width: 100%;"><tr>{{stripe}}</tr></table>
    </td></tr>
    <tr>
        <td class="left">
            {{who}}
            <div class="lb">Member ID</div>
            <table cellpadding="0" cellspacing="0"><tr><td class="chip">{{member_id}}</td></tr></table>
        </td>
        <td class="right">
            <table cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto;"><tr><td class="qr">
                <img src="{{qr}}" style="width: 18mm; height: 18mm;" />
            </td></tr></table>
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    {{others_row}}
    <tr><td class="foot" colspan="2">Identify &middot; Learn &middot; Think &middot; Mentor</td></tr>
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
        '{{logo}}'       => $logo,
        '{{primary}}'    => $h($primary),
        '{{subclass}}'   => $subClass,
        '{{sub}}'        => $h($sub),
        '{{stripe}}'     => $stripe,
        '{{who}}'        => $who,
        '{{member_id}}'  => $h($c['member_id'] ?? ''),
        '{{others_row}}' => $othersRow,
        '{{qr}}'         => $h($c['qr_uri'] ?? ''),
    ]);
}
