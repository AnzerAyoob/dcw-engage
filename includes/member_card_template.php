<?php
/**
 * DCW Engage membership card PDF (given to mPDF by views/member/card_pdf.php).
 *
 * Same idea as internet_receipt_template.php: one function that returns the HTML, no output of its own.
 * The card is credit-card size (85.6 x 54 mm) on an A4 page, so it can be printed and cut out.
 * The PDF deliberately shows no status or dates: those change, and the QR code always shows the live answer.
 *
 * $c = [
 *   'name'        => full name,
 *   'member_id'   => 'D12345678',
 *   'memberships' => [['name' => ..., ...], ...]   (only 'name' is used),
 *   'qr_uri'      => 'data:image/png;base64,...'   (QrCode::png, embedded so nothing is fetched),
 * ]
 */
function member_card_html(array $c): string
{
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $chapters = [];
    foreach ($c['memberships'] as $m) {
        $chapters[(string) $m['name']] = true;   // one line per chapter
    }
    $lines = '';
    foreach (array_keys($chapters) as $n) {
        $lines .= '<div class="ch">' . $h($n) . '</div>';
    }

    return '
<style>
    body { font-family: dejavusans, sans-serif; color: #0f172a; font-size: 10pt; }
    h1 { font-size: 15pt; margin: 0 0 4mm; color: #047857; }
    p.small { font-size: 9pt; color: #475569; line-height: 1.5; margin: 0 0 3mm; }
    table.card { width: 85.6mm; border: 0.4mm solid #047857; border-collapse: separate; border-radius: 3mm; }
    td.band { background-color: #047857; color: #ffffff; padding: 2.5mm 4mm; }
    td.band .t { font-size: 9pt; font-weight: bold; }
    td.band .s { font-size: 6.5pt; }
    td.left { padding: 3mm 2mm 2mm 4mm; vertical-align: top; width: 49mm; }
    td.right { padding: 3mm 4mm 2mm 0; vertical-align: top; text-align: center; width: 32mm; }
    .nm { font-size: 11pt; font-weight: bold; margin: 0 0 1.5mm; }
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
        <div class="t">Deoband Community Wikimedia</div>
        <div class="s">Membership card</div>
    </td></tr>
    <tr>
        <td class="left">
            <div class="nm">' . $h($c['name']) . '</div>
            <div class="id">' . $h($c['member_id']) . '</div>
            ' . $lines . '
        </td>
        <td class="right">
            <img src="' . $h($c['qr_uri']) . '" style="width: 30mm; height: 30mm;" />
            <div class="scan">Scan to verify</div>
        </td>
    </tr>
    <tr><td class="foot" colspan="2">Confirms membership only. Ask to see photo ID if you need to be sure who is presenting it.</td></tr>
</table>';
}
