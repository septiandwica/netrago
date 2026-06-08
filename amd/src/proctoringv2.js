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
            
            this.initOfflineVault();
            
            if (window.screen && window.screen.isExtended === undefined) {
                notification.alert('Browser Recommendation', 'You are using a browser that does not fully support NetraGo Advanced Security (e.g., Safari or Firefox). For the best experience and to avoid false violation flags, we strongly recommend using Google Chrome or Microsoft Edge.', 'I Understand');
            }
            
            if (window.screen && window.screen.isExtended) {
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

            this.monitorTabSwitching();
            
            if (this.config.allow_focusloss == 1) {
                this.monitorFocusLoss();
            }

            if (this.config.allow_devtools == 1) {
                this.blockKeyboardShortcuts();
                this.detectDevTools();
            }
            
            this.monitorMultipleDisplays();
            
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
                if (self.config.requirescreencapture == 1) {
                    if (step2) step2.classList.add('active');
                } else {
                    if (step3) step3.classList.add('active');
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
                        if (self.config.requirescreencapture == 1) {
                            if (step2) step2.classList.add('active');
                        } else {
                            if (step3) step3.classList.add('active');
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
        },
        
        requestCameraForPreview: function() {
            var self = this;
            navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240 }, audio: self.config.requireaudio == 1 })
                .then(function(stream) {
                    var track = stream.getVideoTracks()[0];
                    var label = track.label.toLowerCase();
                    var forbidden = ['obs', 'virtual', 'manycam', 'splitcam', 'logicapture', 'xsplit', 'camtwist'];
                    
                    var isForbidden = forbidden.some(function(keyword) {
                        return label.indexOf(keyword) !== -1;
                    });
                    
                    if (isForbidden) {
                        stream.getTracks().forEach(t => t.stop());
                        notification.alert('NetraGo Security Warning', 'Virtual cameras (' + track.label + ') are strictly prohibited. Please use a real hardware webcam and reload the page.', 'I Understand');
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
                })
                .catch(function(err) {
                    notification.alert('NetraGo Error', 'Camera permission denied. You must allow it to proceed.', 'OK');
                    var btnShare = document.getElementById('nf-btn-share-screen');
                    if (btnShare) {
                        btnShare.disabled = false;
                        btnShare.innerHTML = "<i class='fa fa-desktop'></i> Allow Share Screen";
                    }
                });
        },
        
        startProctoringAndUnlock: function() {
            var self = this;
            
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

                if (this.baselineDescriptor) {
                    if (self.config.requireaudio == 1) {
                        self.monitorAudio();
                    }
                    self.faceLoopId = setInterval(function() {
                        self.verifyFaceLoop();
                    }, 15000);
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
                if (!self.proctoringStarted) return;
                self.handleViolation('Tab switching (focus loss).');
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
            
            var createFSOVerlay = function() {
                var banner = document.getElementById('netrago-fs-banner');
                if (!banner) {
                    banner = document.createElement('div');
                    banner.id = 'netrago-fs-banner';
                    banner.style.cssText = 'position:fixed; top:0; left:0; width:100vw; background:#dc3545; color:white; z-index:9999999; text-align:center; padding:10px; font-weight:bold; cursor:pointer; box-shadow: 0 2px 10px rgba(0,0,0,0.2);';
                    banner.innerHTML = '<i class="fa fa-expand mr-2"></i> Fullscreen exited. Click HERE to resume fullscreen mode and continue the activity.';
                    document.body.appendChild(banner);
                }

                var resumeFS = function() {
                    var docElm = document.documentElement;
                    try {
                        if (docElm.requestFullscreen) docElm.requestFullscreen();
                        else if (docElm.mozRequestFullScreen) docElm.mozRequestFullScreen();
                        else if (docElm.webkitRequestFullscreen) docElm.webkitRequestFullscreen();
                        else if (docElm.msRequestFullscreen) docElm.msRequestFullscreen();
                    } catch (e) {}
                };
                
                banner.addEventListener('click', resumeFS);
            };

            var removeFSOVerlay = function() {
                var banner = document.getElementById('netrago-fs-banner');
                if (banner) banner.remove();
            };

            var checkFullscreen = function() {
                var isFS = document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
                if (!isFS) {
                    if (window.isSubmitting) return; // Allow exit during submit
                    self.logEvent('fullscreen_exit');
                    if (self.config.requirecamera == 1 && self.videoElement) {
                        self.takeSnapshot('fullscreen_exit_snapshot');
                    }
                    if (self.config.requirescreencapture == 1 && self.screenVideoElement) {
                        self.takeScreenSnapshot('fullscreen_exit_snapshot');
                    }
                    createFSOVerlay();
                } else {
                    removeFSOVerlay();
                }
            };

            var isInitFS = document.fullscreenElement || document.mozFullScreenElement || document.webkitFullscreenElement || document.msFullscreenElement;
            if (!isInitFS) {
                createFSOVerlay();
            }

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
                    self.logEvent('tab_switch');
                    
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
            window.addEventListener('blur', function() {
                if (!self.proctoringStarted) return;
                if (window.isSubmitting) return; // Allow exit during submit
                self.logEvent('focus_loss');
                
                // Delay screenshot by 1s to capture what they are looking at
                setTimeout(function() {
                    if (self.config.requirecamera == 1 && self.videoElement) {
                        self.takeSnapshot('focus_loss_snapshot');
                    }
                    if (self.config.requirescreencapture == 1 && self.screenVideoElement) {
                        self.takeScreenSnapshot('focus_loss_snapshot');
                    }
                }, 1000);
            });
        },

        monitorMultipleDisplays: function() {
            var self = this;
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

            var canvas = document.createElement('canvas');
            canvas.width = this.videoElement.videoWidth;
            canvas.height = this.videoElement.videoHeight;
            canvas.getContext('2d', { willReadFrequently: true }).drawImage(this.videoElement, 0, 0);

            var detections = await faceapi.detectAllFaces(canvas).withFaceLandmarks().withFaceDescriptors();
            
            if (detections.length === 0) {
                this.handleViolation('Face not found in camera frame.');
                return;
            }
            if (detections.length > 1) {
                this.handleViolation('Multiple faces detected in camera frame.');
                return;
            }

            // Exactly 1 face, let's compare
            var distance = faceapi.euclideanDistance(detections[0].descriptor, this.baselineDescriptor);
            if (distance > 0.60) {
                this.handleViolation('Unrecognized face detected. Does not match KYC identity.');
                return; // Stop further checks if identity is wrong
            }
            
            // Gaze & Head Pose Tracking Heuristics
            var landmarks = detections[0].landmarks;
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
                    this.handleViolation('Suspicious Gaze: Looking far to the side.');
                    return;
                }
                
                // 2. Pitch (Looking Down at a phone)
                var distNoseToChin = Math.sqrt(Math.pow(noseTip.x - jawBottom.x, 2) + Math.pow(noseTip.y - jawBottom.y, 2));
                var distBridgeToNose = Math.sqrt(Math.pow(noseBridge.x - noseTip.x, 2) + Math.pow(noseBridge.y - noseTip.y, 2));
                var pitchRatio = distNoseToChin / distBridgeToNose;
                
                if (pitchRatio < 0.7) {
                    this.handleViolation('Suspicious Gaze: Looking down (possibly at a phone).');
                    return;
                }
            }
        },

        handleViolation: function(reason) {
            var self = this;
            this.strikes++;
            
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
