<?php
/**
 * DCW Engage - Configuration Template
 * 
 * Duplicate this file to `config.php` and update the values.
 * NEVER COMMIT `config.php` TO VERSION CONTROL.
 */

return [
    'app' => [
        'name' => 'DCW Engage',
        'url'  => 'http://localhost:8000', // Update for production
        'env'  => 'development', // 'development' or 'production'
    ],
    'db' => [
        'host'     => '127.0.0.1',
        'name'     => 'dcw_engage',
        'user'     => 'root',
        'password' => '',
        'charset'  => 'utf8mb4'
    ],
    'mail' => [
        'host' => 'smtp.example.com',
        'user' => 'no-reply@dcwwiki.org',
        'pass' => 'your_password',
        'port' => 587
    ],
    'security' => [
        'magic_link_expiry_draft' => '+7 days',
        'magic_link_expiry_edit'  => '+2 hours',
        'invite_expiry'           => '+7 days',
        'email_verify_expiry'     => '+10 minutes',
        'field_encryption_key'    => 'replace-with-a-long-random-string',
        'otp_secret'              => 'replace-with-a-long-random-string',
        'feedback_retention_months' => 12
    ]
];
