<?php
/**
 * Global Admin Settings for NetraGo
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_netrago', get_string('pluginname', 'local_netrago'));
    $ADMIN->add('localplugins', $settings);

    // Copyright & Information Header
    $info_html = '<div style="background-color: #f8f9fa; padding: 15px; border-left: 5px solid #0052cc; border-radius: 4px; margin-bottom: 20px;">
        <h4 style="margin-top: 0; color: #0052cc;">NetraGo Proctoring System</h4>
        <p style="margin-bottom: 5px;">&copy; ' . date('Y') . ' Self-hosted and maintained by <strong><a href="https://samastanuswantara.com" target="_blank">Tateta</a></strong>.</p>
        <p style="margin-bottom: 0;"><strong>Exclusive for President University</strong> (PRESOLA).</p>
    </div>';
    
    $settings->add(new admin_setting_heading(
        'local_netrago/info_heading',
        '',
        $info_html
    ));

    // Master Switch
    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/enable_plugin',
        'Enable NetraGo Plugin globally',
        'If unchecked, the proctoring features will be completely disabled across the site.',
        1
    ));

    // Feature Toggles
    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_camera',
        'Allow Camera & Face Verification',
        'Allow teachers to enable webcam snapshots and AI Face Verification for their activities.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_audio',
        'Allow Audio/Microphone Monitoring',
        'Allow teachers to enable ambient noise and audio monitoring during activities.',
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_fullscreen',
        'Allow Fullscreen Enforcement',
        'Allow teachers to enforce fullscreen mode during activities.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_screencapture',
        'Allow Screen Capture',
        'Allow teachers to require screen sharing and take snapshots of the user\'s screen upon violations.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_copypaste',
        'Allow Anti Copy-Paste',
        'Allow teachers to disable copy, paste, and text selection during activities.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_focusloss',
        'Enable Focus Loss Detection',
        'Detect when a student clicks outside the browser or switches to another application.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_netrago/allow_devtools',
        'Enable DevTools & Keyboard Blocking',
        'Block F12, Ctrl+P, and other developer shortcuts.',
        1
    ));
}
