define('local_netrago/proctoring', ['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {

    var NetraGoProctor = {
        config: null,
        videoElement: null,
        canvasElement: null,
        stream: null,
        intervalId: null,
        faceLoopId: null,
        baselineDescriptor: null,
        strikes: 0,
        devToolsLogged: false,

        init: function(config) {
            this.config = config;
            
            if (this.config.descriptor) {
                this.baselineDescriptor = new Float32Array(JSON.parse(this.config.descriptor));
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

            var warningText = document.getElementById('netrago-warning-text');
            if (warningText) {
                warningText.innerText = "Initializing NetraGo Proctoring...";
            }

            if (this.config.requirecamera == 1) {
                this.initCamera();
            } else {
                this.unlockPage();
            }
        },

        unlockPage: function() {
            var styleNode = document.getElementById('netrago-anti-js-bypass');
            if (styleNode) styleNode.remove();
            
            var warningNode = document.getElementById('netrago-nojs-warning');
            if (warningNode) warningNode.remove();
        },

        blockKeyboardShortcuts: function() {
            var self = this;
            document.addEventListener('keydown', function(event) {
                // Block F12
                if (event.keyCode === 123) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                    notification.alert('NetraGo Warning', 'Developer tools are disabled.', 'I Understand');
                }
                // Block Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C
                if (event.ctrlKey && event.shiftKey && (event.keyCode === 73 || event.keyCode === 74 || event.keyCode === 67)) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                }
                // Block Ctrl+P (Print)
                if (event.ctrlKey && event.keyCode === 80) {
                    event.preventDefault();
                    self.takeSnapshot('blocked_key');
                    notification.alert('NetraGo Warning', 'Printing is disabled.', 'I Understand');
                }
            });
        },

        detectDevTools: function() {
            var self = this;
            setInterval(function() {
                var threshold = 160;
                var widthDiff = window.outerWidth - window.innerWidth > threshold;
                var heightDiff = window.outerHeight - window.innerHeight > threshold;
                
                if (widthDiff || heightDiff) {
                    // Only log once per minute to avoid spamming if they keep it open
                    if (!self.devToolsLogged) {
                        self.devToolsLogged = true;
                        self.takeSnapshot('devtools');
                        setTimeout(() => { self.devToolsLogged = false; }, 60000);
                    }
                }
            }, 2000);
        },

        monitorFocusLoss: function() {
            var self = this;
            window.addEventListener('blur', function() {
                self.takeSnapshot('focus_loss');
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
            
            // Wait for user interaction to request fullscreen (browser security)
            document.addEventListener('click', function requestFS() {
                if (!document.fullscreenElement) {
                    document.documentElement.requestFullscreen().catch(err => {
                        console.log("Error attempting to enable fullscreen:", err.message);
                    });
                }
                document.removeEventListener('click', requestFS);
            });

            document.addEventListener('fullscreenchange', function() {
                if (!document.fullscreenElement) {
                    // Log exit fullscreen
                    self.logEvent('fullscreen_exit');
                    notification.alert('NetraGo Warning', 'You must remain in Fullscreen mode! Exiting fullscreen has been logged.', 'I Understand');
                }
            });
        },

        monitorTabSwitching: function() {
            var self = this;
            document.addEventListener("visibilitychange", function() {
                if (document.visibilityState === 'hidden') {
                    self.logEvent('tab_switch');
                    
                    // If camera is enabled, take a snapshot immediately upon suspicious event
                    if (self.config.requirecamera == 1 && self.videoElement) {
                        self.takeSnapshot('tab_switch_snapshot');
                    }
                }
            });
        },

        initCamera: async function() {
            var self = this;

            this.videoElement = document.createElement('video');
            this.videoElement.autoplay = true;
            this.videoElement.style.display = 'none';
            document.body.appendChild(this.videoElement);

            this.canvasElement = document.createElement('canvas');
            this.canvasElement.width = 320;
            this.canvasElement.height = 240;
            this.canvasElement.style.display = 'none';
            document.body.appendChild(this.canvasElement);

            try {
                var modelPath = M.cfg.wwwroot + '/local/netrago/models';
                await faceapi.nets.ssdMobilenetv1.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
            } catch (err) {
                console.error("NetraGo AI Model Load Error:", err);
            }

            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    self.stream = stream;
                    self.videoElement.srcObject = stream;
                    
                    // Take a snapshot every 60 seconds
                    self.intervalId = setInterval(function() {
                        self.takeSnapshot('snapshot');
                    }, 60000);

                    // Start continuous face verification loop (every 5 seconds)
                    if (self.baselineDescriptor) {
                        self.faceLoopId = setInterval(function() {
                            self.verifyFaceLoop();
                        }, 15000);
                    }

                    // Take initial snapshot
                    setTimeout(function() {
                        self.takeSnapshot('snapshot');
                    }, 3000);

                    // Unlock the page since camera is granted
                    self.unlockPage();

                })
                .catch(function(err) {
                    var warningText = document.getElementById('netrago-warning-text');
                    if (warningText) {
                        warningText.innerText = "Camera access is denied or not available. Please allow camera access in your browser settings and refresh the page. (" + err.message + ")";
                    } else {
                        notification.alert('NetraGo Warning', 'Camera access is required to proceed. ' + err.message, 'OK');
                    }
                });
        },

        verifyFaceLoop: async function() {
            if (!this.videoElement || !this.stream) return;

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
            if (distance > 0.6) {
                this.handleViolation('Unrecognized face detected. Does not match KYC identity.');
            }
        },

        handleViolation: function(reason) {
            this.strikes++;
            this.takeSnapshot('face_violation_' + this.strikes);
            
            if (this.strikes >= 3) {
                notification.alert('NetraGo Proctoring', 'FINAL WARNING EXCEEDED: ' + reason + '<br>You have been kicked from the activity.', 'OK');
                
                setTimeout(function() {
                    var form = document.getElementById('responseform');
                    if (form) {
                        var input1 = document.createElement('input');
                        input1.type = 'hidden';
                        input1.name = 'finishattempt';
                        input1.value = '1';
                        form.appendChild(input1);
                        
                        var input2 = document.createElement('input');
                        input2.type = 'hidden';
                        input2.name = 'timeup';
                        input2.value = '1';
                        form.appendChild(input2);
                        
                        window.onbeforeunload = null;
                        form.submit();
                    } else {
                        window.onbeforeunload = null;
                        window.location.href = M.cfg.wwwroot + '/course/view.php?id=' + M.cfg.courseId;
                    }
                }, 3000);
            } else {
                // Obscure screen with blur
                document.body.style.filter = 'blur(10px)';
                notification.alert('NetraGo Warning', 'WARNING ' + this.strikes + '/3: ' + reason + '<br>Please look at the camera immediately.', 'I Understand');
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
