<?php
require_once __DIR__ . '/qr.php';
require_once __DIR__ . '/app_log.php';
require_once __DIR__ . '/member_card_template.php';
require_once __DIR__ . '/../models/MemberCardModel.php';
require_once __DIR__ . '/../models/MemberProfileModel.php';

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
 * Build one member's card as a PDF and stream it as a download. Used by the member's own page
 * (/member/card/pdf) and by the staff page (/admin/membership-card), so both print the same card.
 *
 * $token is the card's current token (its QR points at it); $data is MemberCardModel::summary().
 * Nothing is saved on the server. Throws on failure; the caller decides what to show.
 */
function member_card_pdf_send(string $memberId, string $token, array $data): void
{
    $png = QrCode::png(MemberCardModel::url($token), 10);

    // Memberships come primary first. The card lists the other chapters that are current.
    $others = [];
    foreach (array_slice($data['memberships'], 1) as $m) {
        if ($m['state'] === 'active') {
            $others[] = $m['name'];
        }
    }

    // A title set on the profile (for example a founder or chair) replaces the Member ID on the card.
    // A lookup failure must never stop the card from printing.
    $title = '';
    try {
        $profile = (new MemberProfileModel())->get($memberId);
        $title = trim((string) ($profile['card_title'] ?? ''));
    } catch (\Throwable $e) {
        app_log("Card title lookup failed for $memberId: " . $e->getMessage());
    }

    require_once __DIR__ . '/../vendor/autoload.php';
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
        'title'          => $title,
        'logo_uri'       => $logoRef,
        'qr_uri'         => 'var:qr',
    ]));

    $filename = 'DCW-Membership-Card-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $memberId) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
