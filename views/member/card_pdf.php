<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/qr.php';
require_once __DIR__ . '/../../includes/member_card_template.php';
require_once __DIR__ . '/../../models/MemberCardModel.php';

/**
 * Cut the empty margin (transparent or near-white) off a logo PNG so the card shows the artwork itself.
 * Needs PHP's GD extension; without it, or if anything fails, the logo is used as it is.
 * Only used for PNG logos; an SVG is passed to mPDF as it is.
 */
function trim_logo(string $png): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecropauto')) {
        return $png;
    }
    $im = @imagecreatefromstring($png);
    if ($im === false) {
        return $png;
    }
    foreach ([[IMG_CROP_TRANSPARENT, 0.0], [IMG_CROP_THRESHOLD, 0.08]] as [$mode, $threshold]) {
        $cropped = $mode === IMG_CROP_THRESHOLD
            ? @imagecropauto($im, $mode, $threshold, 0xFFFFFF)
            : @imagecropauto($im, $mode);
        if ($cropped !== false) {
            imagedestroy($im);
            $im = $cropped;
        }
    }
    imagesavealpha($im, true);
    ob_start();
    imagepng($im);
    $out = (string) ob_get_clean();
    imagedestroy($im);
    return $out !== '' ? $out : $png;
}

/**
 * DCW Engage - the member's card as a PDF (/member/card/pdf).
 * Signed-in members only, and only their own card. Built in memory and streamed as a download;
 * nothing is saved on the server (same approach as the finance receipts).
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];

header('Cache-Control: private, no-store, max-age=0');

try {
    $cards = new MemberCardModel();
    $token = $cards->issue($memberId);
    $data  = $cards->summary($memberId);
    if ($data === null) {
        throw new RuntimeException('No membership found.');
    }

    $png = QrCode::png(MemberCardModel::url($token), 10);

    // Memberships come primary first. The PDF lists the other chapters that are current.
    $others = [];
    foreach (array_slice($data['memberships'], 1) as $m) {
        if ($m['state'] === 'active') {
            $others[] = $m['name'];
        }
    }

    require_once __DIR__ . '/../../vendor/autoload.php';
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_top' => 20, 'margin_bottom' => 15,
        'margin_left' => 20, 'margin_right' => 20,
    ]);
    $mpdf->SetTitle('DCW Membership Card ' . $memberId);

    // Hand the images to mPDF as bytes (src="var:name") so they are not part of the HTML string.
    $mpdf->imageVars['qr'] = $png;
    $logoUri = MemberCardModel::logoDataUri($data['primary']['chapter']);
    $logoRef = '';
    if ($logoUri !== null) {
        $bytes = base64_decode(substr($logoUri, strpos($logoUri, ',') + 1));
        if ($bytes !== false && $bytes !== '') {
            if (strncmp($bytes, "\x89PNG", 4) === 0) {
                $bytes = trim_logo($bytes);   // PNG only; an SVG goes in as it is
            }
            $mpdf->imageVars['logo'] = $bytes;
            $logoRef = 'var:logo';
        }
    }
    $mpdf->WriteHTML(member_card_html([
        'chapter'        => $data['primary']['chapter'],
        'member_id'      => $memberId,
        'primary_name'   => $data['primary']['name'],
        'other_chapters' => $others,
        'username'       => $data['username'],
        'logo_uri'       => $logoRef,
        'qr_uri'         => 'var:qr',
    ]));

    $filename = 'DCW-Membership-Card-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $memberId) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    app_log("Membership card PDF failed for $memberId: " . $e->getMessage());
    http_response_code(500);
    die('Something went wrong making your card PDF. This has been logged. You can still show the card on the Membership card page.');
}
