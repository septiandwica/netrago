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

require_login();

// Validate user has access to this course module.
$cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('moodle/course:view', $context);

$log = new stdClass();
$log->cmid = $cm->id;
$log->userid = $USER->id;
$log->eventtype = $eventtype;
$log->imagedata = $imagedata;
$log->timecreated = time();

$DB->insert_record('local_netrago_logs', $log);

echo json_encode(['success' => true]);
