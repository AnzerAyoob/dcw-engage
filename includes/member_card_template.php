<?php
/**
 * DCW Engage membership card (PDF and on-screen share the same design).
 *
 * member_card_html() returns the HTML for mPDF (views/member/card_pdf.php); it has no output of its own.
 * member_card_accent() gives the chapter's main colour and is also used by views/member/card.php.
 * member_card_theme() holds the full palette of each chapter, taken from its logo.
 *
 * The card is credit-card size (85.6 x 54 mm) on an A4 page, so it can be printed and cut out.
 *
 * Layout, top to bottom:
 *   1. White header: the chapter's logo (it sits on white, as the logos were drawn) and the chapter name
 *      in the chapter's colour.
 *   2. A stripe in the logo's own colours.
 *   3. Tinted body: Wikimedia username, Member ID on a coloured chip, other current chapters with a
 *      coloured dot each, and the QR code in a framed white box.
 *   4. A solid colour footer with the one-line "membership only" notice.
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
 *   accent : main colour (header title, labels, ID chip, QR frame, footer). Dark enough for white text.
 *   ink    : dark shade of the accent, used for the card title and the username.
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
    $isOrg = $chapter === 'generic' || strcasecmp($primary, $org) === 0;
    $sub   = $isOrg ? 'Membership card' : 'Part of ' . $org;

    $logo = !empty($c['logo_uri'])
        ? '<td class="lg"><img src="' . $h($c['logo_uri']) . '" style="height: 9mm;" /></td>'
        : '';

    // Stripe under the header, one equal cell per colour.
    $stripe = '';
    $w = round(100 / max(1, count($t['stripe'])), 2);
    foreach ($t['stripe'] as $col) {
        $stripe .= '<td style="width: ' . $w . '%; background-color: ' . $col . '; height: 1.5mm; font-size: 1pt; line-height: 1pt;">&nbsp;</td>';
    }

    $username = trim((string) ($c['username'] ?? ''));
    $who = $username !== ''
        ? '<div class="lb">Wikimedia username</div><div class="nm">' . $h($username) . '</div>'
        : '';

    // Other current chapters, each with a dot in that chapter's colour. At most three, so the card keeps its size.
    $others = '';
    $list = array_values(array_filter(array_map(
        fn($n) => member_card_display_name((string) $n),
        (array) ($c['other_chapters'] ?? [])
    ), 'strlen'));
    if ($list) {
        $others = '<div class="lb" style="margin-top: 2.4mm;">Also a member of</div>';
        foreach (array_slice($list, 0, 3) as $n) {
            $dot = member_card_theme(member_card_key_for_name($n))['accent'];
            $others .= '<div class="ch"><span style="color: ' . $dot . ';">&#9679;</span>&nbsp;' . $h($n) . '</div>';
        }
        if (count($list) > 3) {
            $others .= '<div class="ch">+' . (count($list) - 3) . ' more</div>';
        }
    }

    $tpl = '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    table.card { width: 85.6mm; border: 0.5mm solid {{accent}}; border-collapse: separate; border-radius: 3mm; }

    td.head { background-color: #ffffff; padding: 2.2mm 4mm; border-radius: 2.5mm 2.5mm 0 0; }
    td.lg { padding-right: 3mm; vertical-align: middle; }
    td.bt { vertical-align: middle; }
    .t { font-size: 10.5pt; font-weight: bold; color: {{ink}}; }
    .s { font-size: 6.5pt; color: #475569; margin-top: 0.4mm; }

    td.stripe { padding: 0; }

    td.left { background-color: {{tint}}; padding: 3mm 2mm 2.5mm 4mm; vertical-align: top; width: 51mm; }
    td.right { background-color: {{tint}}; padding: 3mm 4mm 2.5mm 0; vertical-align: top; text-align: center; width: 30mm; }
    .lb { font-size: 5.5pt; font-weight: bold; color: {{accent}}; text-transform: uppercase; letter-spacing: 0.25mm; margin: 0 0 0.6mm; }
    .nm { font-size: 12pt; font-weight: bold; color: {{ink}}; margin: 0 0 2.4mm; }
    td.chip { background-color: {{accent}}; color: #ffffff; font-family: dejavusansmono, monospace; font-size: 10pt; font-weight: bold;
              letter-spacing: 0.3mm; padding: 0.9mm 2.4mm; border-radius: 1.2mm; }
    .ch { font-size: 7.5pt; color: #334155; margin: 0 0 0.7mm; }

    td.qr { background-color: #ffffff; border: 0.5mm solid {{accent}}; border-radius: 2mm; padding: 1mm; }
    .scan { font-size: 6pt; font-weight: bold; color: {{accent}}; margin-top: 0.8mm; }

    td.foot { background-color: {{accent}}; color: #ffffff; padding: 1.5mm 4mm; font-size: 5.8pt; text-align: center;
              border-radius: 0 0 2.5mm 2.5mm; }
    .cut { margin-top: 3mm; font-size: 7pt; color: #94a3b8; }
</style>

<table class="card" cellpadding="0" cellspacing="0">
    <tr><td class="head" colspan="2">
        <table cellpadding="0" cellspacing="0"><tr>{{logo}}
            <td class="bt">
                <div class="t">{{primary}}</div>
                <div class="s">{{sub}}</div>
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
            {{others}}
        </td>
        <td class="right">
            <table cellpadding="0" cellspacing="0" align="center"><tr><td class="qr">
                <img src="{{qr}}" style="width: 22mm; height: 22mm;" />
            </td></tr></table>
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    <tr><td class="foot" colspan="2">Confirms membership only, not identity. Ask for photo ID if unsure.</td></tr>
</table>
<div class="cut">Print at 100% (no scaling) and cut along the border.</div>';

    return strtr($tpl, [
        '{{accent}}'    => $t['accent'],
        '{{ink}}'       => $t['ink'],
        '{{tint}}'      => $t['tint'],
        '{{logo}}'      => $logo,
        '{{primary}}'   => $h($primary),
        '{{sub}}'       => $h($sub),
        '{{stripe}}'    => $stripe,
        '{{who}}'       => $who,
        '{{member_id}}' => $h($c['member_id'] ?? ''),
        '{{others}}'    => $others,
        '{{qr}}'        => $h($c['qr_uri'] ?? ''),
    ]);
}
