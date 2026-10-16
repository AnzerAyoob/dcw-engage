<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_card_pdf.php';

/**
 * DCW Engage - the member's card as a PDF (/member/card/pdf).
 * Signed-in members only, and only their own card. Built in memory and streamed as a download;
 * nothing is saved on the server (same approach as the finance receipts).
 * The drawing itself is in includes/member_card_pdf.php, shared with the staff page.
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
    member_card_pdf_send($memberId, $token, $data);
} catch (\Throwable $e) {
    app_log("Membership card PDF failed for $memberId: " . $e->getMessage());
    http_response_code(500);
    die('Something went wrong making your card PDF. This has been logged. You can still show the card on the Membership card page.');
}
