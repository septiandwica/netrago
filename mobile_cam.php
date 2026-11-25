<?php
/**
 * NetraGo Dual-Camera Mobile Side-Angle Proctoring Page.
 * Opened on the student's smartphone via QR code during pre-flight setup.
 *
 * @package    local_netrago
 * @copyright  2026 Tateta
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$uid  = required_param('uid', PARAM_INT);
$exp  = required_param('exp', PARAM_INT);
$sig  = required_param('sig', PARAM_ALPHANUMEXT);

$secret = !empty($CFG->passwordsaltmain) ? $CFG->passwordsaltmain : 'netrago_dualcam_secret_2026';
$expected_sig = hash_hmac('sha256', $cmid . ':' . $uid . ':' . $exp, $secret);

if (!hash_equals($expected_sig, $sig) || time() > $exp) {
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;text-align:center;padding:40px;background:#0f172a;color:#f8fafc;"><h2>Invalid or Expired QR Pairing Link</h2><p>Please refresh your exam page on your laptop and scan the new QR code.</p></body></html>');
}

$ajax_url = (new moodle_url('/local/netrago/ajax_mobile.php'))->out(false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>NetraGo Side-Camera Proctor</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .header {
            padding: 14px 18px;
            background: rgba(15, 23, 42, 0.8);
            border-bottom: 1px solid #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-title {
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }
        .badge.live {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
            border-color: rgba(34, 197, 94, 0.4);
        }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }
        .video-wrap {
            position: relative;
            width: 100%;
            aspect-ratio: 4/3;
            background: #000;
        }
        video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .guide-overlay {
            position: absolute;
            inset: 12px;
            border: 2px dashed rgba(255,255,255,0.35);
            border-radius: 10px;
            pointer-events: none;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 10px;
        }
        .guide-text {
            background: rgba(0,0,0,0.75);
            color: #e2e8f0;
            font-size: 11px;
            padding: 5px 10px;
            border-radius: 8px;
            text-align: center;
        }
        .body {
            padding: 16px 18px;
            font-size: 13px;
            color: #cbd5e1;
            line-height: 1.5;
        }
        .btn {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 12px;
            background: #2563eb;
            color: white;
        }
        .btn-secondary {
            background: #334155;
            color: #f8fafc;
            margin-top: 8px;
            font-size: 12px;
            padding: 9px;
        }
        .stats {
            margin-top: 12px;
            font-size: 11px;
            color: #94a3b8;
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="header-title">NetraGo Side-Camera</div>
            <div id="status-badge" class="badge">
                <span class="dot"></span>
                <span id="status-text">Waiting Camera</span>
            </div>
        </div>

        <div class="video-wrap">
            <video id="mobile-video" autoplay muted playsinline></video>
            <div class="guide-overlay">
                <div class="guide-text">45° Side Angle: Keep desk, hands &amp; laptop screen visible</div>
            </div>
        </div>

        <div class="body">
            <p id="instruction-text">
                Place your smartphone beside your desk at a <strong>45° side angle</strong> so your hands, keyboard, and laptop screen are clearly visible. Do not lock or minimize this browser tab during the exam.
            </p>
            <button id="btn-start-cam" class="btn">Enable Side-Angle Camera</button>
            <button id="btn-flip-cam" class="btn btn-secondary" style="display:none;">Switch Front / Rear Camera</button>

            <div class="stats">
                <span id="sync-status">Status: Not streaming</span>
                <span id="frame-count">Frames sent: 0</span>
            </div>
        </div>
    </div>

    <canvas id="capture-canvas" width="480" height="360" style="display:none;"></canvas>

    <script>
        (function() {
            var ajaxUrl = <?php echo json_encode($ajax_url); ?>;
            var cmid = <?php echo (int)$cmid; ?>;
            var uid = <?php echo (int)$uid; ?>;
            var exp = <?php echo (int)$exp; ?>;
            var sig = <?php echo json_encode($sig); ?>;

            var video = document.getElementById('mobile-video');
            var canvas = document.getElementById('capture-canvas');
            var btnStart = document.getElementById('btn-start-cam');
            var btnFlip = document.getElementById('btn-flip-cam');
            var badge = document.getElementById('status-badge');
            var statusText = document.getElementById('status-text');
            var syncStatus = document.getElementById('sync-status');
            var frameCountEl = document.getElementById('frame-count');

            var currentStream = null;
            var facingMode = 'environment';
            var tickCount = 0;
            var framesSent = 0;
            var loopId = null;

            async function requestWakeLock() {
                try {
                    if ('wakeLock' in navigator) {
                        await navigator.wakeLock.request('screen');
                    }
                } catch (e) {}
            }

            function startCamera() {
                if (currentStream) {
                    currentStream.getTracks().forEach(function(t) { t.stop(); });
                }

                navigator.mediaDevices.getUserMedia({
                    video: { facingMode: facingMode, width: { ideal: 640 }, height: { ideal: 480 } },
                    audio: false
                }).then(function(stream) {
                    currentStream = stream;
                    video.srcObject = stream;
                    btnStart.style.display = 'none';
                    btnFlip.style.display = 'block';
                    badge.classList.add('live');
                    statusText.innerText = 'LIVE STREAMING';
                    syncStatus.innerText = 'Status: Connected to Laptop';
                    requestWakeLock();

                    if (!loopId) {
                        sendFrame();
                        loopId = setInterval(sendFrame, 5000);
                    }
                }).catch(function(err) {
                    // Fallback to any camera
                    navigator.mediaDevices.getUserMedia({ video: true, audio: false }).then(function(stream) {
                        currentStream = stream;
                        video.srcObject = stream;
                        btnStart.style.display = 'none';
                        btnFlip.style.display = 'block';
                        badge.classList.add('live');
                        statusText.innerText = 'LIVE STREAMING';
                        syncStatus.innerText = 'Status: Connected to Laptop';
                        requestWakeLock();

                        if (!loopId) {
                            sendFrame();
                            loopId = setInterval(sendFrame, 5000);
                        }
                    }).catch(function(e2) {
                        alert('Camera permission denied. Please allow camera access in your mobile browser settings.');
                    });
                });
            }

            function sendFrame() {
                if (!currentStream || video.videoWidth === 0) return;

                tickCount++;
                var saveLog = (tickCount % 6 === 1) ? 1 : 0; // Save snapshot to DB every 30s

                var dataUrl = '';
                if (saveLog === 1) {
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    dataUrl = canvas.toDataURL('image/jpeg', 0.45);
                }

                var formData = new FormData();
                formData.append('action', 'upload');
                formData.append('cmid', cmid);
                formData.append('uid', uid);
                formData.append('exp', exp);
                formData.append('sig', sig);
                formData.append('savelog', saveLog);
                if (dataUrl) {
                    formData.append('imagedata', dataUrl);
                }

                fetch(ajaxUrl, {
                    method: 'POST',
                    body: formData
                }).then(function(res) {
                    return res.json();
                }).then(function(data) {
                    if (data && data.success) {
                        framesSent++;
                        frameCountEl.innerText = 'Heartbeats sent: ' + framesSent;
                        syncStatus.innerText = 'Last synced: ' + new Date().toLocaleTimeString();
                    }
                }).catch(function() {
                    syncStatus.innerText = 'Status: Reconnecting...';
                });
            }

            btnStart.addEventListener('click', startCamera);
            btnFlip.addEventListener('click', function() {
                facingMode = (facingMode === 'environment') ? 'user' : 'environment';
                startCamera();
            });
        })();
    </script>
</body>
</html>
