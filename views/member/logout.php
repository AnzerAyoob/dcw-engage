<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/member_session.php';

// Ends the member session only. An organizer who is also signed in on this browser stays signed in.
MemberSession::logout();
// Shown once as a small line under the headline on the home page (views/home.php), then cleared.
$_SESSION['member_notice'] = 'You have been signed out.';
header('Location: /');
exit;
