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
            this.config = config;
            this.proctoringStarted = false;
            
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
                    } else {
                        console.error("KYC Descriptor is not an array:", parsed);
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

            this.bindSubmitListener();

            // Show step 1 OR wait for KYC
            if (window.netragoKycCompleted || !this.config.requirecamera) {
                document.getElementById('nf-step-loading').classList.remove('active');
                document.getElementById('nf-step-1').classList.add('active');
            } else {
                // kyc.js will handle the UI until KYC is done
                var self = this;
                $(document).on('netrago_kyc_done', function() {
                    $('.netrago-step').removeClass('active').css('display', '');
                    document.getElementById('nf-step-1').classList.add('active');
                });
            }

            this.bindPreflightEvents();
        },

        bindPreflightEvents: function() {
            var self = this;
            
            // Step 1 -> Step 2
            var btnNext1 = document.getElementById('nf-btn-next-1');
            if (btnNext1) {
                btnNext1.addEventListener('click', function() {
                    document.getElementById('nf-step-1').classList.remove('active');
                    if (self.config.requirescreencapture == 1) {
                        document.getElementById('nf-step-2').classList.add('active');
                    } else {
                        // Skip screen share if not required
                        document.getElementById('nf-step-3').classList.add('active');
                    }
                });
            }

            // Step 2 Screen Share
            var btnShare = document.getElementById('nf-btn-share-screen');
            if (btnShare) {
                btnShare.addEventListener('click', function() {
                    btnShare.disabled = true;
                    btnShare.innerText = "Requesting Screen Share...";
                    self.initScreenCapture();
                });
            }

            // Step 3 Consent
            var consentCheckbox = document.getElementById('nf-consent-checkbox');
            var btnStart = document.getElementById('nf-btn-start-attempt');
            if (consentCheckbox && btnStart) {
                consentCheckbox.addEventListener('change', function() {
                    btnStart.disabled = !this.checked;
                });

                btnStart.addEventListener('click', function() {
                    document.getElementById('nf-step-3').classList.remove('active');
                    document.getElementById('nf-step-warning').classList.add('active');
                    
                    // Populate hidden password field
                    var pwdInput = document.getElementById('nf-quiz-password');
                    var hiddenPwd = document.getElementById('nf-hidden-password');
                    if (pwdInput && hiddenPwd) {
                        hiddenPwd.value = pwdInput.value;
                    }
                    
                    // Init camera if required
                    self.startProctoringAndUnlock();
                });
            }
        },

        moveToStep3: function() {
            document.getElementById('nf-step-2').classList.remove('active');
            document.getElementById('nf-step-3').classList.add('active');
        },
        
        requestCameraForPreview: function() {
            var self = this;
            navigator.mediaDevices.getUserMedia({ video: { width: 320, height: 240 } })
                .then(function(stream) {
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
                        btnShare.innerText = "Allow Share Screen";
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
                    this.faceLoopId = setInterval(function() {
                        self.verifyFaceLoop();
                    }, 15000);
                }

                setTimeout(function() {
                    self.takeSnapshot('snapshot');
                }, 3000);
            }

            // Submit the hidden form targeting the iframe
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
                        var btn = document.getElementById('netrago-start-btn');
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = "<i class='fa fa-desktop'></i> Start Activity & Share Screen";
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
                // Block F12
                if (event.keyCode === 123) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                    self.takeScreenSnapshot('blocked_key');
                    notification.alert('NetraGo Warning', 'Developer tools are disabled.', 'I Understand');
                }
                // Block Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C
                if (event.ctrlKey && event.shiftKey && (event.keyCode === 73 || event.keyCode === 74 || event.keyCode === 67)) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                    self.takeScreenSnapshot('blocked_key');
                }
                // Block Ctrl+P (Print)
                if (event.ctrlKey && event.keyCode === 80) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                    self.takeScreenSnapshot('blocked_key');
                    notification.alert('NetraGo Warning', 'Printing is disabled.', 'I Understand');
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
                        self.takeSnapshot('devtools');
                        self.takeScreenSnapshot('devtools');
                        setTimeout(() => { self.devToolsLogged = false; }, 60000);
                    }
                }
            }, 2000);
        },

        bindSubmitListener: function() {
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
                    } catch(e) {}
                });
            }
        },

        monitorVisibility: function() {
            var self = this;
            document.addEventListener('visibilitychange', function() {
                if (!self.proctoringStarted) return;
                if (document.hidden) {
                    self.takeSnapshot('tab_switch');
                    // Delay screen capture to allow the new tab/window to render on screen
                    setTimeout(function() {
                        self.takeScreenSnapshot('tab_switch');
                    }, 1000);
                }
            });
        },

        monitorFocusLoss: function() {
            var self = this;
            window.addEventListener('blur', function() {
                if (!self.proctoringStarted) return;
                self.takeSnapshot('focus_loss');
                setTimeout(function() {
                    self.takeScreenSnapshot('focus_loss');
                }, 1000);
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
            }
        },

        handleViolation: function(reason) {
            var self = this;
            this.strikes++;
            this.takeSnapshot('face_violation_' + this.strikes);
            this.takeScreenSnapshot('face_violation_' + this.strikes);
            
            if (this.config.maxstrikes > 0 && this.strikes >= this.config.maxstrikes) {
                // INSTANTLY BLOCK UI to prevent any further interaction
                var blocker = document.createElement('div');
                blocker.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.95);z-index:999999999;color:red;display:flex;align-items:center;justify-content:center;font-size:2rem;text-align:center;font-weight:bold;flex-direction:column;';
                var courseUrl = M.cfg.wwwroot + '/course/view.php?id=' + this.config.courseid;
                blocker.innerHTML = '<div><i class="fa fa-ban fa-3x mb-3"></i><br>FINAL WARNING EXCEEDED<br><span style="font-size:1.2rem;color:white;">' + reason + '</span><br><br><span style="font-size:1rem;color:#ccc;">Your attempt has been forcefully terminated.</span><br><br><a href="' + courseUrl + '" style="background:#dc3545;color:white;padding:15px 30px;text-decoration:none;border-radius:5px;font-size:1.2rem;display:inline-block;margin-top:20px;">Return to Course</a></div>';
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
                    
                    if (finishBtn) {
                        finishBtn.click();
                    } else {
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
                    }
                } else {
                    window.onbeforeunload = null;
                }
            } else {
                // Obscure screen with blur
                document.body.style.filter = 'blur(10px)';
                var warningText = 'WARNING: ' + reason + '<br>Please look at the camera immediately.';
                if (this.config.maxstrikes > 0) {
                    warningText = 'WARNING ' + this.strikes + '/' + this.config.maxstrikes + ': ' + reason + '<br>Please look at the camera immediately.';
                }
                notification.alert('NetraGo Warning', warningText, 'I Understand');
                setTimeout(function() {
                    document.body.style.filter = 'none';
                }, 3000);
            }
        },

        takeSnapshot: function(eventType) {
            if (!this.videoElement || !this.stream) return;

            var ctx = this.canvasElement.getContext('2d', { willReadFrequently: true });
            ctx.drawImage(this.videoElement, 0, 0, this.canvasElement.width, this.canvasElement.height);
            
            // Reduce quality to save space
            var dataUrl = this.canvasElement.toDataURL('image/jpeg', 0.5);
            
            this.logEvent(eventType, dataUrl);
        },

        takeScreenSnapshot: function(eventType) {
            if (!this.screenVideoElement || !this.screenStream) return;
            
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
            $.post(this.config.ajaxurl, {
                cmid: this.config.cmid,
                eventtype: eventType,
                imagedata: imageData,
                sesskey: M.cfg.sesskey
            });
        }
    };

    return NetraGoProctor;
});
