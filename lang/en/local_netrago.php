<?php
/**
 * Language strings for local_netrago
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'NetraGo';
$string['netragoproctoring'] = 'NetraGo Proctoring';
$string['enablenetrago'] = 'Enable NetraGo';
$string['requirecamera'] = 'Require Camera';
$string['requirecamera_help'] = 'If enabled, students must provide access to their webcam during the attempt. Photos will be taken periodically.';
$string['requirekyc'] = 'Require ID Verification (KYC)';
$string['requirekyc_help'] = 'If enabled, students must verify their identity by taking a selfie and showing their ID card before starting the attempt. Requires Camera to be enabled.';
$string['requirefullscreen'] = 'Enforce fullscreen mode';
$string['requirefullscreen_help'] = 'If enabled, students must remain in fullscreen mode. Exiting fullscreen will be logged as a suspicious event.';
$string['disablecopypaste'] = 'Disable copy, paste, and text selection';
$string['disablecopypaste_help'] = 'If enabled, students will not be able to select text, right-click, or use copy/paste shortcuts.';
$string['disablefocusloss'] = 'Enable Focus Loss Detection';
$string['disablefocusloss_help'] = 'Detect when a student clicks outside the browser or switches to another application.';
$string['disabledevtools'] = 'Enable DevTools & Keyboard Blocking';
$string['disabledevtools_help'] = 'Block F12, Ctrl+P, and other developer shortcuts.';
$string['requirescreencapture'] = 'Require screen capture';
$string['requirescreencapture_help'] = 'If enabled, students must share their screen. Snapshots of their screen will be taken when suspicious events occur.';
$string['maxstrikes'] = 'Maximum Violations (Strikes)';
$string['maxstrikes_help'] = 'Set the maximum number of violations allowed before the quiz attempt is automatically terminated. Enter 0 to disable automatic termination (only logs violations).';

// Frontend messages.
$string['setup_required'] = 'Proctoring setup required';
$string['setup_instructions'] = 'This activity is monitored by NetraGo. Before you begin, you must grant the necessary permissions.';
$string['grant_camera'] = 'Grant Camera Access';
$string['camera_denied'] = 'Camera access denied or unavailable. You cannot continue.';
$string['start_fullscreen'] = 'Start Fullscreen & Continue';
$string['fullscreen_exited'] = 'You must remain in Fullscreen mode! Exiting fullscreen has been logged.';

// Event types.
$string['event_snapshot'] = 'Webcam Snapshot';
$string['event_tab_switch'] = 'Switched browser tab or minimized window';
$string['event_fullscreen_exit'] = 'Exited fullscreen mode';
$string['event_copy_attempt'] = 'Attempted to copy/paste';
$string['event_focus_loss'] = 'Window lost focus (clicked outside)';
$string['event_devtools'] = 'Developer Tools (F12) detected';
$string['event_blocked_key'] = 'Attempted forbidden keyboard shortcut';
$string['event_screen_snapshot'] = 'Screen Snapshot (Violation)';
$string['js_required_warning'] = 'You MUST enable JavaScript to view this activity. If you are seeing this message, you are either intentionally disabling JavaScript or using an unsupported browser.';

// Reports.
$string['viewreports'] = 'View NetraGo Proctoring Reports';
$string['report_title'] = 'NetraGo Report: {$a}';
$string['no_suspicious_events'] = 'No suspicious events detected.';
$string['suspicious_events'] = 'Suspicious Events detected';
$string['timestamp'] = 'Time';
$string['eventtype'] = 'Event';
$string['details'] = 'Details';
$string['backtoactivity'] = 'Back to Activity';
