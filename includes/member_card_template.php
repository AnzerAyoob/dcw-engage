<?php
/**
 * DCW Engage membership card (PDF and on-screen share the same design).
 *
 * member_card_html() returns the HTML for mPDF (views/member/card_pdf.php); it has no output of its own.
 * member_card_accent() gives the chapter's colour and is also used by views/member/card.php.
 *
 * The card is credit-card size (85.6 x 54 mm) on an A4 page, so it can be printed and cut out.
 * Header: the chapter's logo (no box around it) and the chapter name. Body: Wikimedia username, Member ID,
 * other current chapters, QR code. The PDF deliberately shows no status or dates: those change, and the QR
 * code always shows the live answer. It shows the Wikimedia username, not the person's real name.
 *
 * $c = [
 *   'chapter'        => chapter key ('generic', 'amu', ...), picks the accent colour,
 *   'member_id'      => 'D12345678',
 *   'primary_name'   => the primary chapter's name, the top line of the card,
 *   'other_chapters' => [names of the other current chapters],
 *   'username'       => verified Wikimedia username, or '' to leave it off,
 *   'logo_uri'       => 'var:logo' (mPDF image variable) or '' for none,
 *   'qr_uri'         => 'var:qr',
 * ]
 */

/** Accent colour per chapter key. Change these to match each chapter's logo. */
function member_card_accent(string $chapter): string
{
    static $map = [
        'generic'       => '#047857',
        'amu'           => '#1d4ed8',
        'jamia'         => '#0f766e',
        'photographers' => '#b45309',
    ];
    return $map[$chapter] ?? $map['generic'];
}

function member_card_html(array $c): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $chapter = (string) ($c['chapter'] ?? 'generic');
    $accent  = member_card_accent($chapter);
    $org     = 'Deoband Community Wikimedia';

    // For a club the card says whose club it is; for DCW itself that would only repeat the top line.
    $isOrg = $chapter === 'generic' || strcasecmp(trim((string) $c['primary_name']), $org) === 0;
    $sub   = $isOrg ? 'Membership card' : $org . ' membership card';

    $logo = !empty($c['logo_uri'])
        ? '<td class="lg"><img src="' . $h($c['logo_uri']) . '" style="height: 9mm;" /></td>'
        : '';

    $username = trim((string) ($c['username'] ?? ''));
    $who = $username !== ''
        ? '<div class="lb">Wikimedia username</div><div class="nm">' . $h($username) . '</div>'
        : '';

    $others = '';
    if (!empty($c['other_chapters'])) {
        $others = '<div class="lb" style="margin-top: 2mm;">Also a member of</div>';
        foreach ($c['other_chapters'] as $n) {
            $others .= '<div class="ch">' . $h($n) . '</div>';
        }
    }

    return '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    table.card { width: 85.6mm; border: 0.5mm solid ' . $accent . '; border-collapse: separate; border-radius: 3mm; }
    td.head { padding: 2.5mm 4mm; border-bottom: 0.7mm solid ' . $accent . '; }
    td.lg { padding-right: 3mm; vertical-align: middle; }
    td.bt { vertical-align: middle; }
    .t { font-size: 10pt; font-weight: bold; color: #0f172a; }
    .s { font-size: 6.5pt; color: #475569; }
    td.left { padding: 3mm 2mm 2mm 4mm; vertical-align: top; width: 51mm; }
    td.right { padding: 3mm 4mm 2mm 0; vertical-align: top; text-align: center; width: 30mm; }
    .lb { font-size: 5.5pt; color: #64748b; margin: 0 0 0.3mm; }
    .nm { font-size: 12pt; font-weight: bold; margin: 0 0 2mm; }
    .id { font-family: dejavusansmono, monospace; font-size: 10pt; font-weight: bold; letter-spacing: 0.3mm; }
    .ch { font-size: 7.5pt; color: #334155; margin: 0 0 0.5mm; }
    .scan { font-size: 6pt; color: #475569; margin-top: 0.6mm; }
    td.foot { padding: 0 4mm 2.5mm 4mm; font-size: 6pt; color: #64748b; }
    .cut { margin-top: 3mm; font-size: 7pt; color: #94a3b8; }
</style>

<table class="card" cellpadding="0" cellspacing="0">
    <tr><td class="head" colspan="2">
        <table cellpadding="0" cellspacing="0"><tr>' . $logo . '
            <td class="bt">
                <div class="t">' . $h($c['primary_name']) . '</div>
                <div class="s">' . $h($sub) . '</div>
            </td>
        </tr></table>
    </td></tr>
    <tr>
        <td class="left">
            ' . $who . '
            <div class="lb">Member ID</div>
            <div class="id">' . $h($c['member_id']) . '</div>
            ' . $others . '
        </td>
        <td class="right">
            <img src="' . $h($c['qr_uri']) . '" style="width: 26mm; height: 26mm;" />
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    <tr><td class="foot" colspan="2">Confirms membership only, not identity. Ask for photo ID if you need to be sure.</td></tr>
</table>
<div class="cut">Print at 100% (no scaling) and cut along the border.</div>';
}
