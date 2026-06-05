<?php
/**
 * NetraGo AJAX handler for KYC onboarding.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$status = required_param('status', PARAM_ALPHA);
$selfiedata = optional_param('selfiedata', '', PARAM_RAW);
$ktpdata = optional_param('ktpdata', '', PARAM_RAW);
$descriptor = optional_param('descriptor', '', PARAM_RAW);

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);

// Rate limiting check
$thirty_mins_ago = time() - (30 * 60);
$attempts = $DB->count_records_select('local_netrago_kyc_attempts', 
    "userid = ? AND cmid = ? AND timeattempted > ? AND status = 'failed'", 
    [$USER->id, $cmid, $thirty_mins_ago]);

if ($attempts >= 5) {
    echo json_encode(['success' => false, 'locked' => true, 'message' => 'You have failed the KYC verification 5 times. You are locked out for 30 minutes.']);
    exit;
}

if ($status === 'failed') {
    // Log the failed attempt for rate limiting
    $attempt = new stdClass();
    $attempt->userid = $USER->id;
    $attempt->cmid = $cmid;
    $attempt->timeattempted = time();
    $attempt->status = 'failed';
    $DB->insert_record('local_netrago_kyc_attempts', $attempt);

    $new_count = $attempts + 1;
    echo json_encode(['success' => true, 'message' => "Verification failed. Attempt $new_count of 5."]);
    exit;
}

if ($status === 'success') {
    // Check if a baseline already exists
    $existing = $DB->get_record('local_netrago_kyc', ['userid' => $USER->id, 'cmid' => $cmid]);
    
    $kyc = new stdClass();
    $kyc->userid = $USER->id;
    $kyc->cmid = $cmid;
    
    if ($selfiedata !== '') {
        $kyc->selfiedata = $selfiedata;
    }
    if ($descriptor !== '') {
        $kyc->descriptor = $descriptor;
    }
    
    $kyc->timeverified = time();

    if ($existing) {
        $kyc->id = $existing->id;
        if ($ktpdata !== '') {
            $kyc->ktpdata = $ktpdata;
        }
        $DB->update_record('local_netrago_kyc', $kyc);
    } else {
        $kyc->ktpdata = $ktpdata;
        // If ktpdata is empty (using master face), fetch from the master record
        if ($kyc->ktpdata === '') {
            $master = $DB->get_record_sql("SELECT * FROM {local_netrago_kyc} WHERE userid = ? ORDER BY timeverified DESC", [$USER->id], IGNORE_MULTIPLE);
            if ($master) {
                $kyc->ktpdata = $master->ktpdata;
            }
        }
        $DB->insert_record('local_netrago_kyc', $kyc);
    }

    // Log the successful attempt
    $attempt = new stdClass();
    $attempt->userid = $USER->id;
    $attempt->cmid = $cmid;
    $attempt->timeattempted = time();
    $attempt->status = 'success';
    $DB->insert_record('local_netrago_kyc_attempts', $attempt);

    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid status']);
