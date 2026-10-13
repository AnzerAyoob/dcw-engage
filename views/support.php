<?php
$q = $_GET;
unset($q['verify']);
header('Location: /member/support' . ($q ? '?' . http_build_query($q) : ''), true, 302);
exit;
