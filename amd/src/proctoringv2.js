define(['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {

    var NetraGoProctor = {
        config: null,
        videoElement: null,
        canvasElement: null,
        stream: null,
        screenVideoElement: null,
        screenCanvasElement: null,
        screenStream: null,
        intervalId: null,
        faceLoopId: null,
        baselineDescriptor: null,
        strikes: 0,
        devToolsLogged: false,
        proctoringStarted: false,

        init: function(config) {
            window.netragoProctorInstance = this;
            this.config = config;
            this.proctoringStarted = false;
            this.isSEB = (this.config && this.config.is_seb) || 
                         (window.netragoIsSEB === true) || 
                         (navigator.userAgent && navigator.userAgent.indexOf('SafeExamBrowser') !== -1);
            
            this.initOfflineVault();
            
            // Only show browser recommendation if NOT running inside Safe Exam Browser
            if (!this.isSEB && window.screen && window.screen.isExtended === undefined) {
                notification.alert('Browser Recommendation', 'You are using a browser that does not fully support NetraGo Advanced Security (e.g., Safari or Firefox). For the best experience and to avoid false violation flags, we strongly recommend using Google Chrome or Microsoft Edge.', 'I Understand');
            }
            
            // In SEB, dual-monitor policy is enforced natively by SEB kiosk at startup
            if (!this.isSEB && window.screen && window.screen.isExtended) {
                document.getElementById('netrago-preflight-container').style.display = 'block';
                document.getElementById('netrago-quiz-frame').style.display = 'none';
                
                var overlay = document.getElementById('netrago-preflight-container');
                overlay.innerHTML = '<div style="background:white;padding:30px;border-radius:10px;text-align:center;box-shadow:0 0 20px rgba(0,0,0,0.1);max-width:500px;margin: 50px auto;">' +
                    '<h2 class="text-danger"><i class="fa fa-ban"></i> Multiple Displays Detected</h2>' +
                    '<p class="mt-3">Dual-monitor setups are strictly prohibited during this exam. You must disconnect all external monitors to proceed.</p>' +
                    '<button onclick="window.location.reload()" class="btn btn-primary mt-3">I have disconnected it. Reload</button>' +
                    '</div>';
                return;
            }
            
            // Persistent Strikes
            this.strikes = this.config.current_strikes || 0;
            if (this.config.maxstrikes > 0 && this.strikes > 0 && this.strikes < this.config.maxstrikes) {
                notification.alert('NetraGo Proctoring', 'Warning: You have ' + this.strikes + ' recorded violations for this activity. A total of ' + this.config.maxstrikes + ' violations will terminate your attempt automatically.', 'I Understand');
            } else if (this.config.maxstrikes > 0 && this.strikes >= this.config.maxstrikes) {
                this.handleViolation('You have already exceeded the maximum allowed violations.');
                return;
            }
            
            if (this.config.descriptor) {
                try {
                    var parsed = JSON.parse(this.config.descriptor);
                    if (Array.isArray(parsed)) {
                        this.baselineDescriptor = new Float32Array(parsed);
                    } else if (typeof parsed === 'object' && parsed !== null) {
                        this.baselineDescriptor = new Float32Array(Object.values(parsed));
                    } else {
                        console.error("KYC Descriptor is not an array or object:", parsed);
                    }
                } catch (e) {
                    console.error("Invalid KYC descriptor data from database:", e);
                }
            }

            if (this.config.disablecopypaste == 1) {
                this.disableCopyPaste();
            }

            if (this.config.requirefullscreen == 1) {
                this.enforceFullscreen();
            }

            if (this.config.allow_tabswitch != 0) {
                this.monitorTabSwitching();
            }
            
            if (this.config.allow_focusloss == 1) {
                this.monitorFocusLoss();
            }

            if (this.config.allow_devtools == 1) {
                this.blockKeyboardShortcuts();
                this.detectDevTools();
            }
            
            this.monitorMultipleDisplays();
            
            if (this.config.allow_dualcam == 1) {
                this.initMobileSideCamMonitor();
            }

            this.syncOfflineVault();

            this.bindSubmitListener();

            var self = this;
            document.addEventListener('netrago_start_clicked', function() {
                document.getElementById('nf-step-loading').classList.remove('active');
                document.getElementById('nf-step-1').classList.add('active');
                self.bindPreflightEvents();
            });

            $(document).on('netrago_kyc_done', function() {
                $('.netrago-step').removeClass('active').css('display', '');
                var step2 = document.getElementById('nf-step-2');
                var step3 = document.getElementById('nf-step-3');
                if (!self.isSEB && self.config.requirescreencapture == 1) {
                    if (step2) step2.classList.add('active');
                } else {
                    if (self.config.requirecamera == 1 && !self.stream) {
                        self.requestCameraForPreview();
                    } else {
                        self.moveToStep3();
                    }
                }
            });

            this.bindPreflightEvents();
        },

        bindPreflightEvents: function() {
            var self = this;
            
            // Step 1 -> Step 2
            var btnNext1 = document.getElementById('nf-btn-next-1');
            if (btnNext1) {
                btnNext1.onclick = function(e) {
                    if (e) e.preventDefault();
                    
                    var step1 = document.getElementById('nf-step-1');
                    if (step1) step1.classList.remove('active');
                    
                    if (self.config.requirekyc == 1 && !window.netragoKycCompleted) {
                        if (window.netragoKycInstance) {
                            window.netragoKycInstance.startCamera();
                        }
                    } else {
                        var step2 = document.getElementById('nf-step-2');
                        var step3 = document.getElementById('nf-step-3');
                        if (!self.isSEB && self.config.requirescreencapture == 1) {
                            if (step2) step2.classList.add('active');
                        } else {
                            if (self.config.requirecamera == 1 && !self.stream) {
                                self.requestCameraForPreview();
                            } else {
                                self.moveToStep3();
                            }
                        }
                    }
                };
            }

            // Step 2 Screen Share
            var btnShare = document.getElementById('nf-btn-share-screen');
            if (btnShare) {
                btnShare.onclick = function(e) {
                    if (e) e.preventDefault();
                    this.disabled = true;
                    this.innerHTML = "<i class='fa fa-spinner fa-spin'></i> Requesting Access...";
                    self.initScreenCapture();
                };
            }

            // Step 3 Consent Checkbox
            var consentCheckbox = document.getElementById('nf-consent-checkbox');
            if (consentCheckbox) {
                consentCheckbox.onchange = function(e) {
                    var btnStart = document.getElementById('nf-btn-start-attempt');
                    if (btnStart) {
                        btnStart.disabled = !this.checked;
                    }
                };
            }

            // Step 3 Start Button
            var btnStart = document.getElementById('nf-btn-start-attempt');
            if (btnStart) {
                btnStart.onclick = function(e) {
                    if (e) e.preventDefault();
                    if (this.disabled) return;
                    if (self.config.requirefullscreen == 1 && !self.isSEB) {
                        self.requestFullscreenDirect();
                    }
                    document.getElementById('nf-step-3').classList.remove('active');
                    document.getElementById('nf-step-warning').classList.add('active');
                    self.startProctoringAndUnlock();
                };
            }
        },

        moveToStep3: function() {
            var step2 = document.getElementById('nf-step-2');
            if (step2) {
                step2.classList.remove('active');
                step2.style.display = 'none';
            }
            var step3 = document.getElementById('nf-step-3');
            if (step3) {
                step3.classList.add('active');
                step3.style.display = 'block';
            }
            // Auto check consent if in SEB or enable start button
            var consentCheckbox = document.getElementById('nf-consent-checkbox');
            if (consentCheckbox && consentCheckbox.checked) {
                var btnStart = document.getElementById('nf-btn-start-attempt');
                if (btnStart) btnStart.disabled = false;
            }
        },
        
        requestCameraForPreview: function() {
            var self = this;
            var needAudio = (self.config && self.config.requireaudio == 1);
            var constraints = { 
                video: { width: 320, height: 240 }, 
                audio: needAudio 
            };

            var handleStream = function(stream) {
                var track = stream.getVideoTracks()[0];
                var label = (track && track.label) ? track.label.toLowerCase() : '';
                var forbidden = ['obs', 'virtual', 'manycam', 'splitcam', 'logicapture', 'xsplit', 'camtwist'];
                
                var isForbidden = forbidden.some(function(keyword) {
                    return label.indexOf(keyword) !== -1;
                });
                
                if (isForbidden) {
                    stream.getTracks().forEach(t => t.stop());
                    notification.alert('NetraGo Security Warning', 'Virtual cameras (' + (track.label || 'Unknown') + ') are strictly prohibited. Please use a real hardware webcam and reload the page.', 'I Understand');
                    var btnShare2 = document.getElementById('nf-btn-share-screen');
                    if (btnShare2) {
                        btnShare2.disabled = false;
                        btnShare2.innerHTML = "<i class='fa fa-desktop'></i> Allow Share Screen";
                    }
                    return;
                }
            
                self.stream = stream;
                
                var video = document.createElement('video');
                video.autoplay = true;
                video.muted = true;
                video.playsInline = true;
                video.srcObject = stream;
                video.style.display = 'none';
                document.body.appendChild(video);
                self.videoElement = video;
                
                var preview = document.getElementById('nf-preview-camera');
                if (preview) { preview.srcObject = stream; }
                
                self.moveToStep3();
            };

            navigator.mediaDevices.getUserMedia(constraints)
                .then(handleStream)
                .catch(function(err) {
                    // Fallback: If audio failed, retry with pure video
                    if (constraints.audio) {
                        navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240 }, audio: false })
                            .then(handleStream)
                            .catch(function(err2) {
                                notification.alert('NetraGo Error', 'Camera permission denied. You must allow camera access to proceed.', 'OK');
                            });
                        return;
                    }
                    notification.alert('NetraGo Error', 'Camera permission denied. You must allow camera access to proceed.', 'OK');
                    var btnShare = document.getElementById('nf-btn-share-screen');
                    if (btnShare) {
                        btnShare.disabled = false;
                        btnShare.innerHTML = "<i class='fa fa-desktop'></i> Allow Share Screen";
                    }
                });
        },
        
        startProctoringAndUnlock: function() {
            var self = this;
            if (this._startAttemptFired) return;
            this._startAttemptFired = true;
            
            if (this.config.requirecamera == 1 && this.stream) {
                this.canvasElement = document.createElement('canvas');
                this.canvasElement.width = 320;
                this.canvasElement.height = 240;
                this.canvasElement.style.display = 'none';
                document.body.appendChild(this.canvasElement);

                this.modelsLoaded = false;
                var modelPath = M.cfg.wwwroot + '/local/netrago/models';
                Promise.all([
                    faceapi.nets.ssdMobilenetv1.loadFromUri(modelPath),
                    faceapi.nets.faceLandmark68Net.loadFromUri(modelPath),
                    faceapi.nets.faceRecognitionNet.loadFromUri(modelPath)
                ]).then(() => {
                    self.modelsLoaded = true;
                }).catch(err => {
                    console.error("NetraGo AI Model Load Error:", err);
                });

                this.intervalId = setInterval(function() {
                    self.takeSnapshot('snapshot');
                }, 60000);

                if (self.config.requireaudio == 1) {
                    self.monitorAudio();
                }

                self.faceLoopId = setInterval(function() {
                    self.verifyFaceLoop();
                }, 4000);

                // Initialize isolated Web Worker for forbidden object detection (cell phone, book, laptop)
                if (self.config.allow_object_detection != 0) {
                    self.initObjectDetector();
                    self.objectLoopId = setInterval(function() {
                        self.detectObjectsLoop();
                    }, 5000);
                }

                setTimeout(function() {
                    self.takeSnapshot('snapshot');
                }, 3000);
            }

            // Submit the hidden form targeting the iframe (this includes the Moodle password!)
            var startForm = document.getElementById('nf-hidden-start-form');
            if (startForm) {
                startForm.submit();
            } else {
                // Fallback to direct load if form is missing
                document.getElementById('netrago-quiz-frame').src = self.config.attempt_url;
            }
            
            setTimeout(function() {
                self.unlockPage();
            }, 3000); // 3 seconds warning delay
        },

        initScreenCapture: function() {
            var self = this;
            if (self.isSEB) {
                // In Safe Exam Browser, desktop kiosk is already locked down at the OS level
                if (self.config.requirecamera == 1 && !self.stream) {
                    self.requestCameraForPreview();
                } else {
                    self.moveToStep3();
                }
                return;
            }

            if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                var warningText = document.getElementById('netrago-warning-text');
                if (warningText) {
                    warningText.innerText = "Screen sharing is not supported on this browser/device. Please use a desktop browser (Chrome/Edge/Firefox).";
                }
                return;
            }
            navigator.mediaDevices.getDisplayMedia({ 
                video: { displaySurface: "monitor" }, 
                audio: false 
            })
                .then(function(stream) {
                    var trackSettings = stream.getVideoTracks()[0].getSettings();
                    if (trackSettings.displaySurface && trackSettings.displaySurface !== 'monitor') {
                        stream.getTracks().forEach(track => track.stop());
                        var warningText = document.getElementById('netrago-warning-text');
                        var btn = document.getElementById('nf-btn-share-screen');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = "<i class='fa fa-desktop'></i> Allow Share Screen";
                        }
                        if (warningText) {
                            warningText.innerText = "You MUST share your 'Entire Screen'. Sharing a Window or Tab is prohibited.";
                        } else {
                            notification.alert('NetraGo Warning', 'You MUST share your "Entire Screen". Sharing a Window or Tab is prohibited.', 'Try Again');
                        }
                        return;
                    }

                    self.screenStream = stream;
                    
                    self.screenVideoElement = document.createElement('video');
                    self.screenVideoElement.autoplay = true;
                    self.screenVideoElement.style.display = 'none';
                    self.screenVideoElement.srcObject = stream;
                    document.body.appendChild(self.screenVideoElement);

                    self.screenCanvasElement = document.createElement('canvas');
                    self.screenCanvasElement.style.display = 'none';
                    document.body.appendChild(self.screenCanvasElement);

                    stream.getVideoTracks()[0].addEventListener('ended', () => {
                        self.handleViolation('Screen sharing was stopped.');
                    });

                    var previewScreen = document.getElementById('nf-preview-screen');
                    if (previewScreen) { previewScreen.srcObject = stream; }

                    if (self.config.requirecamera == 1) {
                        self.requestCameraForPreview();
                    } else {
                        self.moveToStep3();
                    }
                })
                .catch(function(err) {
                    console.error("Screen sharing error:", err);
                    var btnShare = document.getElementById('nf-btn-share-screen');
                    if (btnShare) {
                        btnShare.disabled = false;
                        btnShare.innerHTML = "<i class='fa fa-desktop'></i> Allow Share Screen";
                    }
                    notification.alert('NetraGo Error', 'Screen sharing permission denied. You must allow it to proceed.', 'OK');
                });
        },

        unlockPage: function() {
            var overlay = document.getElementById('netrago-preflight-container');
            if (overlay) {
                overlay.style.display = 'none';
            }
            var frame = document.getElementById('netrago-quiz-frame');
            if (frame) {
                frame.style.display = 'block';
                frame.focus();
            }
            this.proctoringStarted = true;

            if (this.config.requirefullscreen == 1 && !this.isSEB) {
                var isFS = document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
                if (!isFS) {
                    this.requestFullscreenDirect();
                }
            }

            if (this.config.requirecamera == 1 && this.stream) {
                this.createProctorHud();
            }
        },

        requestFullscreenDirect: function() {
            var docElm = document.documentElement;
            try {
                if (docElm.requestFullscreen) {
                    docElm.requestFullscreen().catch(function(e) { console.warn("Fullscreen request error:", e); });
                } else if (docElm.webkitRequestFullscreen) {
                    docElm.webkitRequestFullscreen();
                } else if (docElm.mozRequestFullScreen) {
                    docElm.mozRequestFullScreen();
                } else if (docElm.msRequestFullscreen) {
                    docElm.msRequestFullscreen();
                }
            } catch (err) {
                console.warn("Fullscreen trigger exception:", err);
            }
        },

        createProctorHud: function() {
            var self = this;
            if (document.getElementById('netrago-proctor-hud')) return;

            var hud = document.createElement('div');
            hud.id = 'netrago-proctor-hud';
            hud.style.cssText = 'position:fixed; bottom:20px; right:20px; width:215px; background:rgba(15, 23, 42, 0.94); border:1px solid rgba(255, 255, 255, 0.2); border-radius:12px; box-shadow:0 12px 30px rgba(0,0,0,0.5); z-index:2147483640; overflow:hidden; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; backdrop-filter:blur(8px); transition:border-color 0.3s ease; user-select:none;';

            hud.innerHTML = 
                '<div id="netrago-hud-header" style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:rgba(30, 41, 59, 0.85); border-bottom:1px solid rgba(255, 255, 255, 0.1); cursor:move;">' +
                    '<div style="display:flex; align-items:center; gap:6px;">' +
                        '<span id="netrago-hud-dot" style="width:8px; height:8px; border-radius:50%; background:#22c55e; display:inline-block; box-shadow:0 0 8px #22c55e;"></span>' +
                        '<span id="netrago-hud-title" style="font-size:10px; font-weight:700; color:#f8fafc; letter-spacing:0.4px; text-transform:uppercase;">AI Proctor Active</span>' +
                    '</div>' +
                    '<span id="netrago-hud-strikes-badge" style="font-size:10px; font-weight:700; background:rgba(239, 68, 68, 0.2); color:#fca5a5; padding:2px 6px; border-radius:10px; border:1px solid rgba(239, 68, 68, 0.4);">Strikes: ' + self.strikes + (self.config.maxstrikes > 0 ? ('/' + self.config.maxstrikes) : '') + '</span>' +
                '</div>' +
                '<div style="position:relative; width:215px; height:140px; background:#000; overflow:hidden;">' +
                    '<video id="netrago-hud-video" autoplay muted playsinline style="width:100%; height:100%; object-fit:cover; transform:scaleX(-1); display:block;"></video>' +
                    '<canvas id="netrago-hud-canvas" width="215" height="140" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></canvas>' +
                    '<div id="netrago-hud-warning-pill" style="position:absolute; bottom:6px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.8); color:#22c55e; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap; border:1px solid rgba(34, 197, 94, 0.4); pointer-events:none;">Face Verified</div>' +
                '</div>' +
                '<div style="display:flex; align-items:center; justify-content:space-between; padding:6px 12px; background:rgba(15, 23, 42, 0.95); font-size:10px; color:#94a3b8; border-top:1px solid rgba(255, 255, 255, 0.08);">' +
                    '<div style="display:flex; align-items:center; gap:5px;">' +
                        '<i class="fa fa-microphone" style="font-size:10px; color:#64748b;"></i>' +
                        '<div style="width:45px; height:4px; background:#334155; border-radius:2px; overflow:hidden;">' +
                            '<div id="netrago-hud-vu-bar" style="width:15%; height:100%; background:#22c55e; transition:width 0.1s ease;"></div>' +
                        '</div>' +
                    '</div>' +
                    '<span id="netrago-hud-dualcam-badge" style="font-size:9px; color:' + (self.mobileSideCamConnected ? '#4ade80' : '#94a3b8') + '; font-weight:600;">' + (self.mobileSideCamConnected ? 'Side-Cam: LIVE' : 'Single Cam') + '</span>' +
                '</div>';

            document.body.appendChild(hud);

            var hudVideo = document.getElementById('netrago-hud-video');
            if (hudVideo && self.stream) {
                hudVideo.srcObject = self.stream;
            }

            self.makeDraggable(hud, document.getElementById('netrago-hud-header'));
        },

        makeDraggable: function(element, handle) {
            if (!element || !handle) return;
            var pos1 = 0, pos2 = 0, pos3 = 0, pos4 = 0;
            handle.onmousedown = function(e) {
                e = e || window.event;
                e.preventDefault();
                pos3 = e.clientX;
                pos4 = e.clientY;
                document.onmouseup = function() {
                    document.onmouseup = null;
                    document.onmousemove = null;
                };
                document.onmousemove = function(ev) {
                    ev = ev || window.event;
                    ev.preventDefault();
                    pos1 = pos3 - ev.clientX;
                    pos2 = pos4 - ev.clientY;
                    pos3 = ev.clientX;
                    pos4 = ev.clientY;
                    element.style.top = (element.offsetTop - pos2) + "px";
                    element.style.left = (element.offsetLeft - pos1) + "px";
                    element.style.bottom = "auto";
                    element.style.right = "auto";
                };
            };
        },

        playAlertChime: function(isCritical) {
            try {
                var audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.type = isCritical ? 'sawtooth' : 'sine';
                var freq1 = isCritical ? 740 : 587.33;
                var freq2 = isCritical ? 440 : 880;
                osc.frequency.setValueAtTime(freq1, audioCtx.currentTime);
                osc.frequency.setValueAtTime(freq2, audioCtx.currentTime + 0.12);
                gain.gain.setValueAtTime(0.12, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.35);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.35);
            } catch (e) {}
        },

        initObjectDetector: function() {
            var self = this;
            if (self.objectWorker) return;

            try {
                var workerCode = [
                    'importScripts("https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js", "https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js");',
                    'var model = null;',
                    'cocoSsd.load({ base: "lite_mobilenet_v2" }).then(function(m) {',
                    '    model = m;',
                    '    self.postMessage({ type: "ready" });',
                    '}).catch(function(err) {',
                    '    self.postMessage({ type: "error", message: err && err.message ? err.message : "Model load failed" });',
                    '});',
                    'self.onmessage = function(e) {',
                    '    if (!model || !e.data || !e.data.imageData) return;',
                    '    model.detect(e.data.imageData).then(function(predictions) {',
                    '        var forbidden = predictions.filter(function(p) {',
                    '            return (p.class === "cell phone" || p.class === "book" || p.class === "laptop") && p.score >= 0.55;',
                    '        });',
                    '        self.postMessage({ type: "result", objects: forbidden });',
                    '    }).catch(function() {});',
                    '};'
                ].join('\n');

                var blob = new Blob([workerCode], { type: 'application/javascript' });
                var workerUrl = URL.createObjectURL(blob);
                self.objectWorker = new Worker(workerUrl);
                self.objectWorkerReady = false;

                self.objectWorker.onmessage = function(e) {
                    if (!e.data) return;
                    if (e.data.type === 'ready') {
                        self.objectWorkerReady = true;
                    } else if (e.data.type === 'result' && e.data.objects && e.data.objects.length > 0) {
                        var topObj = e.data.objects[0];
                        var objLabel = topObj.class.toUpperCase() + ' (' + Math.round(topObj.score * 100) + '%)';

                        // Draw red bounding box on HUD canvas
                        var hudCanvas = document.getElementById('netrago-hud-canvas');
                        if (hudCanvas && topObj.bbox) {
                            var ctx = hudCanvas.getContext('2d');
                            var scaleX = hudCanvas.width / 320;
                            var scaleY = hudCanvas.height / 240;
                            var bx = (320 - (topObj.bbox[0] + topObj.bbox[2])) * scaleX;
                            var by = topObj.bbox[1] * scaleY;
                            var bw = topObj.bbox[2] * scaleX;
                            var bh = topObj.bbox[3] * scaleY;
                            ctx.strokeStyle = '#ef4444';
                            ctx.lineWidth = 3;
                            ctx.strokeRect(bx, by, bw, bh);
                            ctx.fillStyle = '#ef4444';
                            ctx.font = 'bold 9px sans-serif';
                            ctx.fillText(objLabel, bx + 4, by > 15 ? by - 4 : by + 12);
                        }

                        var hudPill = document.getElementById('netrago-hud-warning-pill');
                        var hudDot = document.getElementById('netrago-hud-dot');
                        var hudTitle = document.getElementById('netrago-hud-title');
                        if (hudPill) {
                            hudPill.innerText = objLabel;
                            hudPill.style.color = '#ef4444';
                            hudPill.style.borderColor = '#ef4444';
                        }
                        if (hudDot) {
                            hudDot.style.background = '#ef4444';
                            hudDot.style.boxShadow = '0 0 8px #ef4444';
                        }
                        if (hudTitle) {
                            hudTitle.innerText = 'ALERT: OBJECT';
                        }

                        self.playAlertChime(true);
                        self.handleViolation('Prohibited object detected in camera frame: ' + topObj.class + '.');
                    }
                };
            } catch (err) {
                console.warn("NetraGo Object Detection Worker failed to initialize:", err);
            }
        },

        detectObjectsLoop: function() {
            if (!this.proctoringStarted || !this.videoElement || !this.stream || !this.objectWorker || !this.objectWorkerReady) return;
            if (this.videoElement.videoWidth === 0) return;

            try {
                var offCanvas = document.createElement('canvas');
                offCanvas.width = 320;
                offCanvas.height = 240;
                var ctx = offCanvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(this.videoElement, 0, 0, 320, 240);
                var imgData = ctx.getImageData(0, 0, 320, 240);
                this.objectWorker.postMessage({ imageData: imgData }, [imgData.data.buffer]);
            } catch (e) {}
        },

        initMobileSideCamMonitor: function() {
            var self = this;
            if (!self.config || !self.config.mobile_status_url) return;

            self.mobileSideCamConnected = false;
            self.mobileSideCamWasPaired = false;

            setInterval(function() {
                if (window.isSubmitting) return;
                fetch(self.config.mobile_status_url)
                    .then(function(res) { return res.json(); })
                    .then(function(data) {
                        var isConn = Boolean(data && data.connected);
                        self.mobileSideCamConnected = isConn;
                        if (isConn) {
                            self.mobileSideCamWasPaired = true;
                        }

                        var preflightBadge = document.getElementById('nf-dualcam-badge');
                        if (preflightBadge) {
                            if (isConn) {
                                preflightBadge.innerText = 'Connected (Live)';
                                preflightBadge.style.background = '#dcfce7';
                                preflightBadge.style.color = '#166534';
                                preflightBadge.style.borderColor = '#86efac';
                            } else {
                                preflightBadge.innerText = 'Not Paired';
                                preflightBadge.style.background = '#fef3c7';
                                preflightBadge.style.color = '#92400e';
                                preflightBadge.style.borderColor = '#fde68a';
                            }
                        }

                        var hudDualBadge = document.getElementById('netrago-hud-dualcam-badge');
                        if (hudDualBadge) {
                            if (isConn) {
                                hudDualBadge.innerText = 'Side-Cam: LIVE';
                                hudDualBadge.style.color = '#4ade80';
                            } else if (self.mobileSideCamWasPaired) {
                                hudDualBadge.innerText = 'Side-Cam: LOST';
                                hudDualBadge.style.color = '#ef4444';
                            } else {
                                hudDualBadge.innerText = 'Single Cam';
                                hudDualBadge.style.color = '#94a3b8';
                            }
                        }

                        // If phone was paired and disconnects during exam, trigger violation
                        if (self.proctoringStarted && self.mobileSideCamWasPaired && !isConn && data.age > 25) {
                            if (!self.sideCamLostLogged) {
                                self.sideCamLostLogged = true;
                                self.playAlertChime(true);
                                self.handleViolation('Mobile side-camera disconnected during exam.');
                                setTimeout(function() { self.sideCamLostLogged = false; }, 30000);
                            }
                        }
                    })
                    .catch(function() {});
            }, 5000);
        },

        blockKeyboardShortcuts: function() {
            var self = this;
            document.addEventListener('keydown', function(event) {
                if (!self.proctoringStarted) return;
                // Block F12
                if (event.keyCode === 123) {
                    event.preventDefault();
                    self.handleViolation('Developer tools shortcut (F12) detected.');
                }
                // Block Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C
                if (event.ctrlKey && event.shiftKey && (event.keyCode === 73 || event.keyCode === 74 || event.keyCode === 67)) {
                    event.preventDefault();
                    self.handleViolation('Developer tools shortcut detected.');
                }
                // Block Ctrl+P (Print)
                if (event.ctrlKey && event.keyCode === 80) {
                    event.preventDefault();
                    self.handleViolation('Printing shortcut detected.');
                }
            });
        },

        detectDevTools: function() {
            var self = this;
            setInterval(function() {
                if (!self.proctoringStarted) return;
                var threshold = 160;
                var widthDiff = window.outerWidth - window.innerWidth > threshold;
                var heightDiff = window.outerHeight - window.innerHeight > threshold;
                
                if (widthDiff || heightDiff) {
                    // Only log once per minute to avoid spamming if they keep it open
                    if (!self.devToolsLogged) {
                        self.devToolsLogged = true;
                        self.handleViolation('DevTools or Browser Console opened.');
                        setTimeout(() => { self.devToolsLogged = false; }, 60000);
                    }
                }
            }, 2000);
        },

        bindSubmitListener: function() {
            var self = this;
            var frame = document.getElementById('netrago-quiz-frame');
            if (frame) {
                frame.addEventListener('load', function() {
                    try {
                        var frameDoc = frame.contentDocument || frame.contentWindow.document;
                        var form = frameDoc.getElementById('responseform') || frameDoc.querySelector('form');
                        if (form) {
                            form.addEventListener('submit', function() {
                                window.isSubmitting = true;
                            });
                        }
                        self.applyIframeProtections(frameDoc);
                    } catch(e) {}
                });
            }
        },

        applyIframeProtections: function(frameDoc) {
            var self = this;
            if (!frameDoc || !frameDoc.body) return;
            
            if (self.config.disablecopypaste == 1) {
                frameDoc.addEventListener('contextmenu', e => e.preventDefault());
                frameDoc.addEventListener('copy', e => e.preventDefault());
                frameDoc.addEventListener('cut', e => e.preventDefault());
                frameDoc.addEventListener('paste', e => e.preventDefault());
                frameDoc.addEventListener('dragstart', e => e.preventDefault());
                frameDoc.addEventListener('selectstart', e => e.preventDefault());
                
                frameDoc.body.style.webkitUserSelect = 'none';
                frameDoc.body.style.mozUserSelect = 'none';
                frameDoc.body.style.msUserSelect = 'none';
                frameDoc.body.style.userSelect = 'none';
            }

            if (self.config.allow_devtools == 1) {
                frameDoc.addEventListener('keydown', function(event) {
                    if (!self.proctoringStarted) return;
                    if (event.keyCode === 123) {
                        event.preventDefault();
                        self.handleViolation('Developer tools shortcut (F12) detected inside quiz.');
                    }
                    if (event.ctrlKey && event.shiftKey && (event.keyCode === 73 || event.keyCode === 74 || event.keyCode === 67)) {
                        event.preventDefault();
                        self.handleViolation('Developer tools shortcut detected inside quiz.');
                    }
                    if (event.ctrlKey && event.keyCode === 80) {
                        event.preventDefault();
                        self.handleViolation('Printing shortcut detected inside quiz.');
                    }
                });
            }
            
            if (self.config.allow_focusloss == 1) {
                frameDoc.defaultView.addEventListener('blur', function() {
                    if (!self.proctoringStarted) return;
                    // Only trigger if the parent is also blurred, or if they clicked outside the browser entirely
                    // Wait 500ms to see if they just clicked between frame and parent
                    setTimeout(function() {
                        if (!document.hasFocus() && !frameDoc.hasFocus()) {
                            self.handleViolation('Focus lost (clicked outside quiz).');
                        }
                    }, 500);
                });
            }
        },

        monitorVisibility: function() {
            var self = this;
            document.addEventListener('visibilitychange', function() {
                if (!self.proctoringStarted) return;
                if (document.hidden) {
                    self.handleViolation('Tab switching (quiz tab hidden).');
                }
            });
        },

        monitorFocusLoss: function() {
            var self = this;
            window.addEventListener('blur', function() {
                if (!self.proctoringStarted || window.isSubmitting) return;

                // Debounce to distinguish focus shift into child quiz iframe from true focus loss
                setTimeout(function() {
                    var frame = document.getElementById('netrago-quiz-frame');
                    var isFrameActive = false;
                    try {
                        if (frame && (document.activeElement === frame || (frame.contentDocument && frame.contentDocument.hasFocus()))) {
                            isFrameActive = true;
                        }
                    } catch(e) {}

                    if (isFrameActive || document.hasFocus()) {
                        return; // False alarm: focus moved into the quiz iframe
                    }

                    // Inside SEB, the entire desktop is locked; transient system alerts or selects are safe
                    if (self.isSEB) {
                        return;
                    }

                    self.playAlertChime(true);
                    self.handleViolation('Focus lost (clicked outside exam window).');
                    setTimeout(function() {
                        if (self.config.requirecamera == 1 && self.videoElement) {
                            self.takeSnapshot('focus_loss_snapshot');
                        }
                        if (self.config.requirescreencapture == 1 && self.screenVideoElement) {
                            self.takeScreenSnapshot('focus_loss_snapshot');
                        }
                    }, 800);
                }, 400);
            });

            // Cursor leaving browser viewport detection
            document.documentElement.addEventListener('mouseleave', function() {
                if (!self.proctoringStarted || window.isSubmitting) return;
                var hudPill = document.getElementById('netrago-hud-warning-pill');
                if (hudPill) {
                    hudPill.innerText = 'Cursor Outside Window';
                    hudPill.style.color = '#f59e0b';
                    hudPill.style.borderColor = '#f59e0b';
                }
            });

            document.documentElement.addEventListener('mouseenter', function() {
                if (!self.proctoringStarted) return;
                var hudPill = document.getElementById('netrago-hud-warning-pill');
                if (hudPill && hudPill.innerText === 'Cursor Outside Window') {
                    hudPill.innerText = 'Face Verified';
                    hudPill.style.color = '#22c55e';
                    hudPill.style.borderColor = '#22c55e';
                }
            });
        },

        disableCopyPaste: function() {
            document.addEventListener('contextmenu', event => event.preventDefault());
            document.addEventListener('copy', event => event.preventDefault());
            document.addEventListener('cut', event => event.preventDefault());
            document.addEventListener('paste', event => event.preventDefault());
            document.addEventListener('selectstart', event => event.preventDefault());

            $('body').css({
                '-webkit-user-select': 'none',
                '-moz-user-select': 'none',
                '-ms-user-select': 'none',
                'user-select': 'none'
            });
        },

        enforceFullscreen: function() {
            var self = this;
            
            // In Safe Exam Browser, kiosk fullscreen is already enforced at OS level
            if (self.isSEB) {
                return;
            }

            var createFSOVerlay = function() {
                if (!self.proctoringStarted) return;

                var curtain = document.getElementById('netrago-fullscreen-lockout');
                if (!curtain) {
                    curtain = document.createElement('div');
                    curtain.id = 'netrago-fullscreen-lockout';
                    curtain.style.cssText = 'position:fixed; inset:0; width:100vw; height:100vh; background:rgba(15, 23, 42, 0.96); z-index:2147483647; display:flex; flex-direction:column; align-items:center; justify-content:center; color:white; text-align:center; padding:24px; box-sizing:border-box; backdrop-filter:blur(10px); font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;';
                    curtain.innerHTML = 
                        '<div style="background:#1e293b; border:1px solid #334155; border-radius:16px; padding:36px; max-width:520px; width:100%; box-shadow:0 25px 50px -12px rgba(0, 0, 0, 0.5);">' +
                            '<div style="width:68px; height:68px; border-radius:50%; background:rgba(239, 68, 68, 0.15); display:inline-flex; align-items:center; justify-content:center; margin-bottom:18px; color:#ef4444; font-size:28px;">' +
                                '<i class="fa fa-expand"></i>' +
                            '</div>' +
                            '<h2 style="font-size:22px; font-weight:700; margin:0 0 10px 0; color:#f8fafc;">EXAM LOCKED: Fullscreen Mode Exited</h2>' +
                            '<p style="font-size:14px; color:#94a3b8; line-height:1.6; margin:0 0 20px 0;">' +
                                'You have exited fullscreen mode. Exiting fullscreen during this proctored examination is strictly prohibited and has been recorded as a violation.' +
                            '</p>' +
                            '<div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.3); border-radius:8px; padding:10px 14px; margin-bottom:24px; font-size:13px; color:#fca5a5; font-weight:600;">' +
                                '<i class="fa fa-exclamation-circle" style="margin-right:6px;"></i> Recorded Strikes: <span id="netrago-lockout-strikes">' + self.strikes + '</span>' + (self.config.maxstrikes > 0 ? (' / ' + self.config.maxstrikes) : '') +
                            '</div>' +
                            '<button id="netrago-btn-resume-fs" style="background:#2563eb; color:white; border:none; padding:14px 24px; font-size:15px; font-weight:600; border-radius:8px; cursor:pointer; width:100%; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 12px rgba(37, 99, 235, 0.4);">' +
                                '<i class="fa fa-expand"></i> Re-enter Fullscreen &amp; Resume Exam' +
                            '</button>' +
                        '</div>';
                    document.body.appendChild(curtain);

                    var resumeBtn = document.getElementById('netrago-btn-resume-fs');
                    if (resumeBtn) {
                        resumeBtn.onclick = function() {
                            self.requestFullscreenDirect();
                            removeFSOVerlay();
                        };
                    }
                } else {
                    curtain.style.display = 'flex';
                    var lockoutStrikes = document.getElementById('netrago-lockout-strikes');
                    if (lockoutStrikes) {
                        lockoutStrikes.innerText = self.strikes;
                    }
                }

                // Blur and disable pointer events on the quiz frame while locked out
                var frame = document.getElementById('netrago-quiz-frame');
                if (frame) {
                    frame.style.filter = 'blur(8px)';
                    frame.style.pointerEvents = 'none';
                }
            };

            var removeFSOVerlay = function() {
                var curtain = document.getElementById('netrago-fullscreen-lockout');
                if (curtain) {
                    curtain.style.display = 'none';
                }
                var frame = document.getElementById('netrago-quiz-frame');
                if (frame) {
                    frame.style.filter = 'none';
                    frame.style.pointerEvents = 'auto';
                    frame.focus();
                }
            };

            var checkFullscreen = function() {
                var isFS = document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
                if (!isFS) {
                    if (window.isSubmitting) return; // Allow exit during submit
                    if (self.proctoringStarted) {
                        self.playAlertChime(true);
                        self.handleViolation('Fullscreen exit detected.');
                        if (self.config.requirecamera == 1 && self.videoElement) {
                            self.takeSnapshot('fullscreen_exit_snapshot');
                        }
                        if (self.config.requirescreencapture == 1 && self.screenVideoElement) {
                            self.takeScreenSnapshot('fullscreen_exit_snapshot');
                        }
                        createFSOVerlay();
                    }
                } else {
                    removeFSOVerlay();
                }
            };

            document.addEventListener('fullscreenchange', checkFullscreen);
            document.addEventListener('webkitfullscreenchange', checkFullscreen);
            document.addEventListener('mozfullscreenchange', checkFullscreen);
            document.addEventListener('MSFullscreenChange', checkFullscreen);
        },

        monitorTabSwitching: function() {
            var self = this;
            document.addEventListener("visibilitychange", function() {
                if (!self.proctoringStarted) return;
                if (document.visibilityState === 'hidden') {
                    if (window.isSubmitting) return; // Allow exit during submit
                    self.playAlertChime(true);
                    self.handleViolation('Tab switching or application backgrounding detected.');
                    
                    var hudPill = document.getElementById('netrago-hud-warning-pill');
                    if (hudPill) {
                        hudPill.innerText = 'Tab Switch Detected';
                        hudPill.style.color = '#ef4444';
                        hudPill.style.borderColor = '#ef4444';
                    }

                    // Delay screenshot by 1s to allow video stream to catch up to the new tab
                    setTimeout(function() {
                        if (self.config.requirecamera == 1 && self.videoElement) {
                            self.takeSnapshot('tab_switch_snapshot');
                        }
                        if (self.config.requirescreencapture == 1 && self.screenVideoElement) {
                            self.takeScreenSnapshot('tab_switch_snapshot');
                        }
                    }, 1000);
                }
            });
        },

        monitorMultipleDisplays: function() {
            var self = this;
            if (self.isSEB) return; // In SEB, multiple displays are prohibited by SEB kiosk natively

            setInterval(function() {
                if (!self.proctoringStarted) return;
                if (window.screen && window.screen.isExtended) {
                    if (!self.multipleDisplayLogged) {
                        self.multipleDisplayLogged = true;
                        self.handleViolation('Multiple displays connected during exam. Please unplug external monitors.');
                        // Reset flag after 1 minute to avoid infinite loops if they ignore it
                        setTimeout(() => { self.multipleDisplayLogged = false; }, 60000);
                    }
                }
            }, 5000);
        },

        monitorAudio: function() {
            if (!this.stream) return;
            var audioTracks = this.stream.getAudioTracks();
            if (audioTracks.length === 0) return;

            try {
                var audioContext = new (window.AudioContext || window.webkitAudioContext)();
                var analyser = audioContext.createAnalyser();
                var microphone = audioContext.createMediaStreamSource(this.stream);
                microphone.connect(analyser);
                
                analyser.smoothingTimeConstant = 0.8;
                analyser.fftSize = 256;
                
                var dataArray = new Uint8Array(analyser.frequencyBinCount);
                var self = this;
                var noiseFrames = 0;
                
                setInterval(function() {
                    if (!self.proctoringStarted) return;
                    analyser.getByteFrequencyData(dataArray);
                    var sum = 0;
                    for (var i = 0; i < dataArray.length; i++) {
                        sum += dataArray[i];
                    }
                    var average = sum / dataArray.length;
                    
                    // Update HUD VU-meter bar
                    var vuBar = document.getElementById('netrago-hud-vu-bar');
                    if (vuBar) {
                        var pct = Math.min(100, Math.round((average / 60) * 100));
                        vuBar.style.width = Math.max(10, pct) + '%';
                        vuBar.style.background = average > 40 ? '#ef4444' : '#22c55e';
                    }

                    // Decibel threshold heuristic (average > 40 is fairly loud talking/noise)
                    if (average > 40) {
                        noiseFrames++;
                    } else {
                        noiseFrames = 0;
                    }
                    
                    // 10 seconds of sustained noise (since setInterval runs every 1 sec, 10 frames = 10s)
                    if (noiseFrames >= 10) {
                        if (!self.audioViolationLogged) {
                            self.audioViolationLogged = true;
                            self.handleViolation('Suspicious Audio: Sustained speaking or loud noise detected.');
                            setTimeout(() => { self.audioViolationLogged = false; }, 30000);
                        }
                        noiseFrames = 0;
                    }
                }, 1000);
            } catch (e) {
                console.error("AudioContext not supported or failed to initialize", e);
            }
        },

        verifyFaceLoop: async function() {
            if (!this.videoElement || !this.stream || !this.modelsLoaded) return;

            var hudCanvas = document.getElementById('netrago-hud-canvas');
            var hudPill = document.getElementById('netrago-hud-warning-pill');
            var hudDot = document.getElementById('netrago-hud-dot');
            var hudTitle = document.getElementById('netrago-hud-title');

            var canvas = document.createElement('canvas');
            canvas.width = this.videoElement.videoWidth || 320;
            canvas.height = this.videoElement.videoHeight || 240;
            canvas.getContext('2d', { willReadFrequently: true }).drawImage(this.videoElement, 0, 0);

            var detections = await faceapi.detectAllFaces(canvas).withFaceLandmarks().withFaceDescriptors();
            
            var drawHudBox = function(box, color, label) {
                if (!hudCanvas) return;
                var ctx = hudCanvas.getContext('2d');
                ctx.clearRect(0, 0, hudCanvas.width, hudCanvas.height);
                if (!box) return;

                var scaleX = hudCanvas.width / (canvas.width || 320);
                var scaleY = hudCanvas.height / (canvas.height || 240);
                // Video is mirrored via scaleX(-1), so mirror X coordinate
                var x = (canvas.width - (box.x + box.width)) * scaleX;
                var y = box.y * scaleY;
                var w = box.width * scaleX;
                var h = box.height * scaleY;

                ctx.strokeStyle = color;
                ctx.lineWidth = 2.5;
                ctx.beginPath();
                if (ctx.roundRect) {
                    ctx.roundRect(x, y, w, h, 6);
                } else {
                    ctx.rect(x, y, w, h);
                }
                ctx.stroke();

                if (label) {
                    ctx.fillStyle = color;
                    ctx.font = 'bold 9px sans-serif';
                    ctx.fillText(label, x + 4, y > 15 ? y - 4 : y + 12);
                }
            };

            var setHudStatus = function(state, text, pillColor, dotColor) {
                if (hudPill) {
                    hudPill.innerText = text;
                    hudPill.style.color = pillColor;
                    hudPill.style.borderColor = pillColor;
                }
                if (hudDot) {
                    hudDot.style.background = dotColor;
                    hudDot.style.boxShadow = '0 0 8px ' + dotColor;
                }
                if (hudTitle) {
                    hudTitle.innerText = state;
                }
            };

            if (detections.length === 0) {
                drawHudBox(null);
                setHudStatus('ALERT: NO FACE', 'No Face Detected', '#ef4444', '#ef4444');
                this.playAlertChime(true);
                this.handleViolation('Face not found in camera frame.');
                return;
            }
            if (detections.length > 1) {
                drawHudBox(detections[0].detection.box, '#ef4444', 'Multiple Faces');
                setHudStatus('ALERT: MULTI FACE', 'Multiple Faces Detected', '#ef4444', '#ef4444');
                this.playAlertChime(true);
                this.handleViolation('Multiple faces detected in camera frame.');
                return;
            }

            var face = detections[0];
            var box = face.detection.box;

            // Compare face descriptor against KYC baseline if available
            if (this.baselineDescriptor) {
                var distance = faceapi.euclideanDistance(face.descriptor, this.baselineDescriptor);
                if (distance > 0.60) {
                    drawHudBox(box, '#ef4444', 'Mismatch');
                    setHudStatus('ALERT: MISMATCH', 'Unrecognized Face', '#ef4444', '#ef4444');
                    this.playAlertChime(true);
                    this.handleViolation('Unrecognized face detected. Does not match KYC identity.');
                    return; // Stop further checks if identity is wrong
                }
            }
            
            // Gaze & Head Pose Tracking Heuristics
            var landmarks = face.landmarks;
            if (landmarks) {
                var jaw = landmarks.getJawOutline();
                var nose = landmarks.getNose();
                
                var noseTip = nose[3];
                var jawLeft = jaw[0];
                var jawRight = jaw[16];
                var jawBottom = jaw[8];
                var noseBridge = nose[0];
                
                // 1. Yaw (Looking Left / Right)
                var distLeft = Math.sqrt(Math.pow(noseTip.x - jawLeft.x, 2) + Math.pow(noseTip.y - jawLeft.y, 2));
                var distRight = Math.sqrt(Math.pow(noseTip.x - jawRight.x, 2) + Math.pow(noseTip.y - jawRight.y, 2));
                var yawRatio = distLeft / distRight;
                
                if (yawRatio < 0.4 || yawRatio > 2.5) {
                    drawHudBox(box, '#f59e0b', 'Looking Away');
                    setHudStatus('WARNING: GAZE', 'Looking Away', '#f59e0b', '#f59e0b');
                    this.playAlertChime(false);
                    this.handleViolation('Suspicious Gaze: Looking far to the side.');
                    return;
                }
                
                // 2. Pitch (Looking Down at a phone)
                var distNoseToChin = Math.sqrt(Math.pow(noseTip.x - jawBottom.x, 2) + Math.pow(noseTip.y - jawBottom.y, 2));
                var distBridgeToNose = Math.sqrt(Math.pow(noseBridge.x - noseTip.x, 2) + Math.pow(noseBridge.y - noseTip.y, 2));
                var pitchRatio = distNoseToChin / distBridgeToNose;
                
                if (pitchRatio < 0.7) {
                    drawHudBox(box, '#f59e0b', 'Looking Down');
                    setHudStatus('WARNING: GAZE', 'Looking Down', '#f59e0b', '#f59e0b');
                    this.playAlertChime(false);
                    this.handleViolation('Suspicious Gaze: Looking down (possibly at a phone).');
                    return;
                }
            }

            // Face verified and centered
            drawHudBox(box, '#22c55e', 'Verified');
            setHudStatus('AI PROCTOR ACTIVE', 'Face Verified', '#22c55e', '#22c55e');
        },

        handleViolation: function(reason) {
            var self = this;
            
            var now = Date.now();
            if (this.lastViolationTime && (now - this.lastViolationTime < 14000)) {
                return; // Debounce violations to prevent spamming
            }
            this.lastViolationTime = now;
            
            this.strikes++;

            // Update HUD & Lockout strike badges
            var strikesBadge = document.getElementById('netrago-hud-strikes-badge');
            if (strikesBadge) {
                strikesBadge.innerText = 'Strikes: ' + this.strikes + (this.config.maxstrikes > 0 ? ('/' + this.config.maxstrikes) : '');
                strikesBadge.style.background = 'rgba(239, 68, 68, 0.4)';
                strikesBadge.style.color = '#fff';
            }
            var lockoutStrikes = document.getElementById('netrago-lockout-strikes');
            if (lockoutStrikes) {
                lockoutStrikes.innerText = this.strikes;
            }
            
            var reasonCode = 'violation_' + this.strikes;
            if (reason.indexOf('Tab switching') !== -1 || reason.indexOf('quiz tab') !== -1) reasonCode = 'tab_switch_violation_' + this.strikes;
            else if (reason.indexOf('Looking down') !== -1) reasonCode = 'gaze_down_violation_' + this.strikes;
            else if (reason.indexOf('Looking far') !== -1) reasonCode = 'gaze_side_violation_' + this.strikes;
            else if (reason.indexOf('Audio') !== -1) reasonCode = 'audio_noise_violation_' + this.strikes;
            else if (reason.indexOf('Multiple displays') !== -1) reasonCode = 'multi_display_violation_' + this.strikes;
            else if (reason.indexOf('DevTools') !== -1) reasonCode = 'devtools_violation_' + this.strikes;
            else if (reason.indexOf('Face not found') !== -1) reasonCode = 'face_not_found_violation_' + this.strikes;
            else if (reason.indexOf('Unrecognized face') !== -1) reasonCode = 'unrecognized_face_violation_' + this.strikes;
            else if (reason.indexOf('Multiple faces') !== -1) reasonCode = 'multiple_faces_violation_' + this.strikes;
            else if (reason.indexOf('Prohibited object') !== -1) reasonCode = 'prohibited_object_violation_' + this.strikes;
            else if (reason.indexOf('side-camera') !== -1) reasonCode = 'side_cam_disconnected_' + this.strikes;
            
            this.takeSnapshot(reasonCode);
            this.takeScreenSnapshot('screen_' + reasonCode);
            
            if (this.config.maxstrikes > 0 && this.strikes >= this.config.maxstrikes) {
                // INSTANTLY BLOCK UI to prevent any further interaction
                var blocker = document.createElement('div');
                blocker.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.95);z-index:999999999;color:red;display:flex;align-items:center;justify-content:center;font-size:2rem;text-align:center;font-weight:bold;flex-direction:column;';
                blocker.innerHTML = '<div><i class="fa fa-ban fa-3x mb-3"></i><br>FINAL WARNING EXCEEDED<br><span style="font-size:1.2rem;color:white;">' + reason + '</span><br><br><span style="font-size:1rem;color:#ccc;">Your attempt has been forcefully terminated.</span></div>';
                document.body.appendChild(blocker);
                
                // Kill all media streams before redirect
                if (self.stream) {
                    self.stream.getTracks().forEach(track => track.stop());
                }
                if (self.screenStream) {
                    self.screenStream.getTracks().forEach(track => track.stop());
                }

                var frame = document.getElementById('netrago-quiz-frame');
                var form = null;
                var frameDoc = null;
                try {
                    if (frame && frame.contentDocument) {
                        frameDoc = frame.contentDocument;
                        form = frameDoc.getElementById('responseform');
                    }
                } catch(e) {}

                if (form && frameDoc) {
                    var finishBtn = frameDoc.querySelector('input[name="finishattempt"], button[name="finishattempt"]');
                    window.onbeforeunload = null;
                    if (frame.contentWindow && frame.contentWindow.M && frame.contentWindow.M.core_formchangechecker) {
                        frame.contentWindow.M.core_formchangechecker.reset_form_dirty_state();
                    }
                    if (frame.contentWindow) {
                        frame.contentWindow.isSubmitting = true;
                    }
                    
                    sessionStorage.setItem('netrago_violation_termination', '1');
                    
                    var input1 = frameDoc.createElement('input');
                    input1.type = 'hidden';
                    input1.name = 'finishattempt';
                    input1.value = '1';
                    form.appendChild(input1);
                    
                    var input2 = frameDoc.createElement('input');
                    input2.type = 'hidden';
                    input2.name = 'timeup';
                    input2.value = '1';
                    form.appendChild(input2);
                    
                    form.submit();
                } else {
                    window.onbeforeunload = null;
                }
            } else {
                var warningText = 'WARNING: ' + reason + '<br>Please look at the camera immediately.';
                if (this.config.maxstrikes > 0) {
                    warningText = 'WARNING ' + this.strikes + '/' + this.config.maxstrikes + ': ' + reason + '<br>Please look at the camera immediately.';
                }
                notification.alert('NetraGo Warning', warningText, 'I Understand');
            }
        },

        takeSnapshot: async function(eventType) {
            if (!this.videoElement || !this.stream) return;
            
            if (!this.canvasElement) {
                this.canvasElement = document.createElement('canvas');
                this.canvasElement.width = 320;
                this.canvasElement.height = 240;
                this.canvasElement.style.display = 'none';
                document.body.appendChild(this.canvasElement);
            }

            var ctx = this.canvasElement.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(this.videoElement, 0, 0, this.canvasElement.width, this.canvasElement.height);
            
            var dataUrl = this.canvasElement.toDataURL('image/jpeg', 0.5);
            
            // Perform instant AI check on this spontaneous snapshot
            if (this.modelsLoaded && this.baselineDescriptor) {
                try {
                    var detections = await faceapi.detectAllFaces(this.canvasElement).withFaceLandmarks().withFaceDescriptors();
                    if (detections.length === 0) {
                        eventType += '_face_not_found';
                    } else if (detections.length > 1) {
                        eventType += '_multiple_faces';
                    } else {
                        var distance = faceapi.euclideanDistance(detections[0].descriptor, this.baselineDescriptor);
                        if (distance > 0.60) {
                            eventType += '_unrecognized_face';
                        }
                    }
                } catch(e) {}
            }
            
            this.logEvent(eventType, dataUrl);
        },

        takeScreenSnapshot: function(eventType) {
            if (!this.screenVideoElement || !this.screenStream) return;
            
            if (!this.screenCanvasElement) {
                this.screenCanvasElement = document.createElement('canvas');
                this.screenCanvasElement.style.display = 'none';
                document.body.appendChild(this.screenCanvasElement);
            }
            
            var video = this.screenVideoElement;
            var canvas = this.screenCanvasElement;
            
            if (video.videoWidth === 0 || video.videoHeight === 0) return;
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            var ctx = canvas.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            // Further reduce quality for screen captures to avoid massive payload sizes
            var dataUrl = canvas.toDataURL('image/jpeg', 0.3);
            this.logEvent(eventType + '_screen', dataUrl);
        },

        logEvent: function(eventType, imageData = '') {
            var self = this;
            var payload = {
                cmid: this.config.cmid,
                eventtype: eventType,
                imagedata: imageData,
                sesskey: M.cfg.sesskey
            };
            
            $.post(this.config.ajaxurl, payload).fail(function() {
                if (self.db) {
                    var tx = self.db.transaction('logs', 'readwrite');
                    var store = tx.objectStore('logs');
                    store.add({ payload: payload, timestamp: Date.now() });
                }
            });
        },
        
        initOfflineVault: function() {
            var self = this;
            var request = indexedDB.open("NetraGoVault", 1);
            request.onupgradeneeded = function(e) {
                var db = e.target.result;
                if (!db.objectStoreNames.contains('logs')) {
                    db.createObjectStore('logs', { keyPath: 'id', autoIncrement: true });
                }
            };
            request.onsuccess = function(e) {
                self.db = e.target.result;
            };
        },
        
        syncOfflineVault: function() {
            var self = this;
            setInterval(function() {
                if (!navigator.onLine || !self.db) return;
                
                var tx = self.db.transaction('logs', 'readonly');
                var store = tx.objectStore('logs');
                var request = store.getAll();
                
                request.onsuccess = function() {
                    var logs = request.result;
                    if (logs && logs.length > 0) {
                        logs.forEach(function(logItem) {
                            $.post(self.config.ajaxurl, logItem.payload).done(function() {
                                // If successfully synced, delete from vault
                                var delTx = self.db.transaction('logs', 'readwrite');
                                delTx.objectStore('logs').delete(logItem.id);
                            });
                        });
                    }
                };
            }, 10000);
        }
    };

    return NetraGoProctor;
});
