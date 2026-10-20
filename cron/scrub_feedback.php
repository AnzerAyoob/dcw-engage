<?php
/**
 * DCW Engage - PII scrub for feedback responses.
 *
 * Blanks name and email on feedback responses older than the retention period
 * (config security.feedback_retention_months, default 12). The written answers are kept
 * unless SCRUB_DROP_ANSWERS is true.
 *
 * Run daily from cron, e.g.:   17 3 * * *  php /path/to/cron/scrub_feedback.php
 *
 * NOTE: bootstrap below is a guess. Match whatever the existing scripts in cron/ do.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

const SCRUB_DROP_ANSWERS = false;

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/FeedbackModel.php';

$months = FeedbackModel::retentionMonths();
$count  = (new FeedbackModel())->scrubOlderThan($months, SCRUB_DROP_ANSWERS);

echo date('c') . " feedback scrub: $count response(s) older than $months months anonymised.\n";
