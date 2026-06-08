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

// Reject oversized image payloads (~2MB base64 cap for screen snapshots).
if (strlen($imagedata) > 2000000) {
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
