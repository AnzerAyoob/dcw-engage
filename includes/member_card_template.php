<?php
/**
 * DCW Engage membership card PDF (given to mPDF by views/member/card_pdf.php).
 *
 * Same idea as internet_receipt_template.php: one function that returns the HTML, no output of its own.
 * The card is credit-card size (85.6 x 54 mm) on an A4 page, so it can be printed and cut out.
 * The PDF deliberately shows no status or dates: those change, and the QR code always shows the live answer.
 *
 * $c = [
 *   'name'           => full name,
 *   'member_id'      => 'D12345678',
 *   'primary_name'   => the primary chapter, shown in the header next to its logo,
 *   'other_chapters' => [chapter names other than the primary one],
 *   'username'       => verified Wikimedia username, or '' to leave it off,
 *   'logo_uri'       => 'data:image/png;base64,...' for the primary chapter, or '' for none,
 *   'qr_uri'         => 'data:image/png;base64,...' (QrCode::png, embedded so nothing is fetched),
 * ]
 */
function member_card_html(array $c): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $others = '';
    foreach ($c['other_chapters'] as $n) {
        $others .= '<div class="ch">' . $h($n) . '</div>';
    }
    $logo = !empty($c['logo_uri'])
        ? '<td class="lg"><img src="' . $h($c['logo_uri']) . '" style="height: 9mm;" /></td>'
        : '';
    $wm = ($c['username'] ?? '') !== ''
        ? '<div class="wm">Wikimedia: <b>' . $h($c['username']) . '</b></div>'
        : '';

    return '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    h1 { font-size: 15pt; margin: 0 0 4mm; color: #047857; }
    p.small { font-size: 9pt; color: #475569; line-height: 1.5; margin: 0 0 3mm; }
    table.card { width: 85.6mm; border: 0.4mm solid #047857; border-collapse: separate; border-radius: 3mm; }
    td.band { background-color: #047857; padding: 2mm 3mm; }
    td.lg { background-color: #ffffff; padding: 0.7mm; width: 10mm; }
    td.bt { padding-left: 2.5mm; vertical-align: middle; }
    .t { font-size: 9pt; font-weight: bold; color: #ffffff; }
    .s { font-size: 6.5pt; color: #ffffff; }
    td.left { padding: 3mm 2mm 2mm 4mm; vertical-align: top; width: 49mm; }
    td.right { padding: 3mm 4mm 2mm 0; vertical-align: top; text-align: center; width: 32mm; }
    .nm { font-size: 11pt; font-weight: bold; margin: 0 0 1mm; }
    .wm { font-size: 7pt; color: #334155; margin: 0 0 1mm; }
    .id { font-family: dejavusansmono, monospace; font-size: 9pt; letter-spacing: 0.3mm; margin: 0 0 2mm; }
    .ch { font-size: 7pt; color: #334155; margin: 0 0 0.6mm; }
    .scan { font-size: 6pt; color: #475569; margin-top: 0.8mm; }
    td.foot { padding: 0 4mm 2.5mm 4mm; font-size: 5.5pt; color: #64748b; }
</style>

<h1>Your DCW membership card</h1>
<p class="small">Print this page and cut along the border, or keep the PDF on your phone. Anyone can scan the code to check your membership.
It shows only your first name and last initial, your chapters and whether they are current. If you lose the card or share it by mistake,
replace it on your Membership card page and the old code stops working.</p>

<table class="card" cellpadding="0" cellspacing="0">
    <tr><td class="band" colspan="2">
        <table cellpadding="0" cellspacing="0"><tr>' . $logo . '
            <td class="bt">
                <div class="t">Deoband Community Wikimedia</div>
                <div class="s">' . $h($c['primary_name']) . ' &middot; Membership card</div>
            </td>
        </tr></table>
    </td></tr>
    <tr>
        <td class="left">
            <div class="nm">' . $h($c['name']) . '</div>
            ' . $wm . '
            <div class="id">' . $h($c['member_id']) . '</div>
            ' . $others . '
        </td>
        <td class="right">
            <img src="' . $h($c['qr_uri']) . '" style="width: 30mm; height: 30mm;" />
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    <tr><td class="foot" colspan="2">Confirms membership only. Ask to see photo ID if you need to be sure who is presenting it.</td></tr>
</table>';
}
