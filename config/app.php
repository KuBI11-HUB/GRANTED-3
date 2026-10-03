<?php
define('CURRENT_SCHOOL_YEAR', '2026-2027');
define('CURRENT_SEMESTER', '1st');          // '1st' | '2nd' | 'Summer'
define('DEADLINE_REMINDER_DAYS', 7);
// ---- Mail (Phase 4) -------------------------------------------------------
if (is_file(__DIR__ . '/app.local.php')) {
    require __DIR__ . '/app.local.php';
}
defined('MAIL_ENABLED')           || define('MAIL_ENABLED', false);
defined('MAIL_FROM_EMAIL')        || define('MAIL_FROM_EMAIL', 'your.sender.account@gmail.com');
defined('MAIL_FROM_NAME')         || define('MAIL_FROM_NAME', 'GRANTED Scholarship Office');
defined('GMAIL_CREDENTIALS_PATH') || define('GMAIL_CREDENTIALS_PATH', __DIR__ . '/gmail_credentials.json');
defined('GMAIL_TOKEN_PATH')       || define('GMAIL_TOKEN_PATH', __DIR__ . '/gmail_token.json');
defined('GMAIL_REDIRECT_URI')     || define('GMAIL_REDIRECT_URI', 'http://localhost/GRANTED/tools/gmail_authorize.php');
