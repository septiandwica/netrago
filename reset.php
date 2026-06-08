<?php
require_once(__DIR__ . '/../../config.php');
require_login();
if (is_siteadmin()) {
    global $DB;
    $DB->delete_records('local_netrago_logs', ['userid' => $USER->id]);
    $DB->delete_records('local_netrago_kyc_attempts', ['userid' => $USER->id]);
    $DB->delete_records('local_netrago_kyc', ['userid' => $USER->id]);
    echo "<h1>All your NetraGo strikes and KYC attempts have been cleared!</h1><p>You can now close this tab and try the quiz again.</p>";
} else {
    echo "Must be admin.";
}
