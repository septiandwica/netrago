define(['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {

    var NetraGoProctor = {
        config: null,
        videoElement: null,
        canvasElement: null,
        stream: null,
        intervalId: null,

        init: function(config) {
            this.config = config;

            if (this.config.disablecopypaste == 1) {
                this.disableCopyPaste();
            }

            if (this.config.requirefullscreen == 1) {
                this.enforceFullscreen();
            }

            this.monitorTabSwitching();
            this.monitorFocusLoss();
            this.blockKeyboardShortcuts();
            this.detectDevTools();

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
                    alert("Developer tools are disabled.");
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
                    alert("Printing is disabled.");
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
                    alert("NetraGo: You must remain in Fullscreen mode! Exiting fullscreen has been logged.");
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

        initCamera: function() {
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

            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    self.stream = stream;
                    self.videoElement.srcObject = stream;
                    
                    // Take a snapshot every 60 seconds
                    self.intervalId = setInterval(function() {
                        self.takeSnapshot('snapshot');
                    }, 60000);

                    // Take initial snapshot
                    setTimeout(function() {
                        self.takeSnapshot('snapshot');
                    }, 3000);

                    // Unlock the page since camera is granted
                    self.unlockPage();

                })
                .catch(function(err) {
                    alert("NetraGo: Camera access is required to proceed. " + err.message);
                });
        },

        takeSnapshot: function(eventType) {
            if (!this.videoElement || !this.stream) return;

            var ctx = this.canvasElement.getContext('2d');
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
