<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/qr.php';
require_once __DIR__ . '/../../includes/member_card_template.php';
require_once __DIR__ . '/../../models/MemberCardModel.php';

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
    $mpdf->WriteHTML(member_card_html([
        'name'           => $data['full_name'] !== '' ? $data['full_name'] : $memberId,
        'member_id'      => $memberId,
        'primary_name'   => $data['primary']['name'],
        'other_chapters' => $others,
        'username'       => $data['username'],
        'logo_uri'       => MemberCardModel::logoDataUri($data['primary']['chapter']) ?? '',
        'qr_uri'         => 'data:image/png;base64,' . base64_encode($png),
    ]));

    $filename = 'DCW-Membership-Card-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $memberId) . '.pdf';
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    app_log("Membership card PDF failed for $memberId: " . $e->getMessage());
    http_response_code(500);
    die('Something went wrong making your card PDF. This has been logged. You can still show the card on the Membership card page.');
}
