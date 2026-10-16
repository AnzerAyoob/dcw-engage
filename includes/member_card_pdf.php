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
 * Decode a logo data URI into [bytes, width/height ratio]. Returns [null, 0.0] when there is no usable logo.
 * PNGs are trimmed first, so the ratio describes the artwork and not its empty margin. For an SVG the
 * ratio comes from its viewBox (or width/height); 0.0 means unknown and the template treats it as square.
 */
function member_card_logo_prepare(?string $uri): array
{
    if ($uri === null || $uri === '') {
        return [null, 0.0];
    }
    $bytes = base64_decode(substr($uri, strpos($uri, ',') + 1));
    if ($bytes === false || $bytes === '') {
        return [null, 0.0];
    }
    $ratio = 0.0;
    if (strncmp($bytes, "\x89PNG", 4) === 0) {
        $bytes = trim_logo($bytes);
        $size  = @getimagesizefromstring($bytes);
        if ($size && $size[1] > 0) {
            $ratio = $size[0] / $size[1];
        }
    } elseif (preg_match('/viewBox\s*=\s*"[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $bytes, $m) && (float) $m[2] > 0) {
        $ratio = (float) $m[1] / (float) $m[2];
    }
    return [$bytes, $ratio];
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

    // Title (replaces the Member ID) and main project both come from the profile.
    // A lookup failure must never stop the card from printing.
    $title   = '';
    $project = '';
    try {
        $profile = (new MemberProfileModel())->get($memberId);
        $title   = trim((string) ($profile['card_title'] ?? ''));
        // TODO: confirm the real profile field for the member's main project.
        $project = trim((string) ($profile['main_project'] ?? ''));
    } catch (\Throwable $e) {
        app_log("Card profile lookup failed for $memberId: " . $e->getMessage());
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

    $chapter = (string) $data['primary']['chapter'];

    $ownUri = MemberCardModel::logoDataUri($chapter);
    [$logoBytes, $logoRatio] = member_card_logo_prepare($ownUri);
    $logoRef = '';
    if ($logoBytes !== null) {
        $mpdf->imageVars['logo'] = $logoBytes;
        $logoRef = 'var:logo';
    }

    // The DCW logo goes on the right of club cards. If a club has no logo of its own yet, the model falls
    // back to the DCW logo; then the same logo would appear twice, so the right one is left off.
    $dcwRef = '';
    $dcwRatio = 0.0;
    $dcwUri = MemberCardModel::logoDataUri('generic');
    if ($chapter !== 'generic' && $dcwUri !== null && $dcwUri !== $ownUri) {
        [$dcwBytes, $dcwRatio] = member_card_logo_prepare($dcwUri);
        if ($dcwBytes !== null) {
            $mpdf->imageVars['dcw'] = $dcwBytes;
            $dcwRef = 'var:dcw';
        }
    }

    $mpdf->WriteHTML(member_card_html([
        'chapter'        => $chapter,
        'member_id'      => $memberId,
        'primary_name'   => $data['primary']['name'],
        'other_chapters' => $others,
        'username'       => $data['username'],
        'title'          => $title,
        'project'        => $project,
        'logo_uri'       => $logoRef,
        'logo_ratio'     => $logoRatio,
        'dcw_logo_uri'   => $dcwRef,
        'dcw_logo_ratio' => $dcwRatio,
        'qr_uri'         => 'var:qr',
    ]));

    $filename = 'DCW-Membership-Card-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $memberId) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
}
