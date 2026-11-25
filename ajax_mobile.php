<?php
/**
 * NetraGo Mobile Side-Camera AJAX endpoint.
 * Handles smartphone heartbeat, snapshot uploads, and laptop status polling.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$action = required_param('action', PARAM_ALPHA);
$cmid   = required_param('cmid', PARAM_INT);
$uid    = required_param('uid', PARAM_INT);
$exp    = required_param('exp', PARAM_INT);
$sig    = required_param('sig', PARAM_ALPHANUMEXT);

header('Content-Type: application/json');

// Verify HMAC signature
$secret = !empty($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : 'netrago_dualcam_secret_2026';
$expected_sig = hash_hmac('sha256', $cmid . ':' . $uid . ':' . $exp, $secret);

if (!hash_equals($expected_sig, $sig)) {
    echo json_encode(['error' => 'Invalid security signature']);
    die();
}

if (time() > $exp) {
    echo json_encode(['error' => 'Pairing session expired']);
    die();
}

$tempdir = $CFG->dataroot . '/temp';
if (!is_dir($tempdir)) {
    @mkdir($tempdir, 0777, true);
}
$statusfile = $tempdir . '/netrago_mobile_' . $cmid . '_' . $uid . '.json';

if ($action === 'upload') {
    $imagedata = optional_param('imagedata', '', PARAM_RAW);
    $save_log  = optional_param('savelog', 0, PARAM_INT);

    if (strlen($imagedata) > 1500000) {
        echo json_encode(['error' => 'Image payload too large']);
        die();
    }

    $now = time();
    $state = [
        'connected' => true,
        'last_seen' => $now,
        'cmid'      => $cmid,
        'userid'    => $uid,
    ];
    file_put_contents($statusfile, json_encode($state));

    // Persist periodic snapshot to Moodle proctoring logs (e.g. every 30s)
    if ($save_log === 1 && !empty($imagedata)) {
        $log = new stdClass();
        $log->cmid        = $cmid;
        $log->userid      = $uid;
        $log->eventtype   = 'side_cam_snapshot';
        $log->imagedata   = $imagedata;
        $log->timecreated = $now;
        $DB->insert_record('local_netrago_logs', $log);
    }

    echo json_encode(['success' => true, 'timestamp' => $now]);
    die();
}

if ($action === 'status') {
    if (!file_exists($statusfile)) {
        echo json_encode(['connected' => false, 'last_seen' => 0, 'age' => 9999]);
        die();
    }

    $raw = @file_get_contents($statusfile);
    $data = json_decode($raw, true);
    $last_seen = isset($data['last_seen']) ? (int)$data['last_seen'] : 0;
    $age = time() - $last_seen;

    echo json_encode([
        'connected' => ($last_seen > 0 && $age <= 20),
        'last_seen' => $last_seen,
        'age'       => $age,
    ]);
    die();
}

echo json_encode(['error' => 'Invalid action']);
