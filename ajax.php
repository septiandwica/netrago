<?php
/**
 * NetraGo AJAX handler for saving logs and snapshots.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$eventtype = required_param('eventtype', PARAM_ALPHANUMEXT);
$imagedata = optional_param('imagedata', '', PARAM_RAW);

$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
require_login($cm->course, true, $cm);
require_sesskey();
$context = context_module::instance($cm->id);

// Allowlist: only accept known event types to prevent log poisoning.
$allowed_events = [
    'snapshot', 'tab_switch', 'focus_loss', 'fullscreen_exit',
    'tab_switch_snapshot', 'fullscreen_exit_snapshot',
    'blocked_key', 'devtools',
    'face_violation_1', 'face_violation_2', 'face_violation_3',
    'face_violation_1_screen', 'face_violation_2_screen', 'face_violation_3_screen',
    'snapshot_screen',
];
if (!in_array($eventtype, $allowed_events)) {
    echo json_encode(['error' => 'Invalid event type']);
    die();
}

// Reject oversized image payloads (~200KB base64 cap).
if (strlen($imagedata) > 200000) {
    echo json_encode(['error' => 'Image data too large']);
    die();
}

$log = new stdClass();
$log->cmid = $cm->id;
$log->userid = $USER->id;
$log->eventtype = $eventtype;
$log->imagedata = $imagedata;
$log->timecreated = time();

$DB->insert_record('local_netrago_logs', $log);

echo json_encode(['success' => true]);
