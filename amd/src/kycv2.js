define(['jquery', 'core/ajax', 'core/notification'], function($, ajax, notification) {

    var NetraGoKYC = {
        config: null,
        videoElement: null,
        selfieDescriptor: null,
        selfieDataUrl: null,
        idCardDescriptor: null,
        idCardDataUrl: null,

        modelsReady: false,
        startRequested: false,

        init: function(config) {
            this.config = config;
            
            if (window.screen && window.screen.isExtended) {
                $('#nf-loading-spinner').hide();
                $('#nf-loading-text').text('Multiple Displays Detected');
                $('#nf-loading-desc').html('<span class="text-danger">You must disconnect all external monitors to proceed with the exam. Dual-monitor setups are prohibited.</span>');
                notification.alert('NetraGo Security Warning', 'Multiple displays detected! You must disconnect all external monitors to proceed.', 'I Understand');
                return;
            }
            
            this.videoElement = document.getElementById('webcam');
            this.bindEvents();
            
            // Preload models in the background immediately when page loads
            this.preloadModels();
        },

        preloadModels: async function() {
            try {
                var modelPath = M.cfg.wwwroot + '/local/netrago/models';
                await faceapi.nets.ssdMobilenetv1.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
                
                // If models loaded successfully, update text
                $('#nf-loading-spinner').hide();
                $('#nf-loading-text').html('<i class="fa fa-check-circle" style="color: #28a745;"></i> Environment Ready');
                $('#nf-loading-desc').text('AI Models loaded successfully. Click Start Setup if you haven\'t already.');
                
                if (window.netragoKycCompleted || !this.config.requirecamera) {
                    $('#nf-loading-spinner').hide();
                }
            } catch (err) {
                $('#nf-loading-text').text('Failed to load AI models. Please check your connection.');
                console.error(err);
                notification.alert('NetraGo Error', 'Failed to load AI models. Please ensure your internet connection is stable.', 'OK');
            }
        },

        startCamera: function() {
            var self = this;
            navigator.mediaDevices.getUserMedia({ video: {} })
                .then(function(stream) {
                    var track = stream.getVideoTracks()[0];
                    var label = track.label.toLowerCase();
                    var forbidden = ['obs', 'virtual', 'manycam', 'splitcam', 'logicapture', 'xsplit', 'camtwist'];
                    
                    var isForbidden = forbidden.some(function(keyword) {
                        return label.indexOf(keyword) !== -1;
                    });
                    
                    if (isForbidden) {
                        stream.getTracks().forEach(t => t.stop());
                        $('#nf-loading-text').text("Virtual Cameras are prohibited. (" + track.label + ")");
                        notification.alert('NetraGo Security Warning', 'Virtual cameras (' + track.label + ') are strictly prohibited. Please use a real hardware webcam and reload the page.', 'I Understand');
                        return;
                    }
                    
                    self.videoElement.srcObject = stream;
                    $('#kyc-video-container').show();
                    self.showStep('nf-step-kyc-selfie');
                    $('#kyc-status').text('Camera ready. Please proceed.');
                })
                .catch(function(err) {
                    $('#nf-loading-text').text("Camera access is required. " + err.message);
                    notification.alert('NetraGo Error', 'Camera access is required for identity verification. Please allow camera access in your browser settings and reload the page.', 'OK');
                });
        },

        showStep: function(stepId) {
            $('.netrago-step').removeClass('active');
            $('#' + stepId).addClass('active');
        },

        bindEvents: function() {
            var self = this;

            document.addEventListener('netrago_start_clicked', function() {
                self.startCamera();
            });

            // btn-agree-intro removed, starts automatically after models load.

            $('#btn-selfie').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Detecting face...');
                $('#selfie-error').hide();
                
                if (self.videoElement.videoWidth === 0 || self.videoElement.videoHeight === 0) {
                    $('#selfie-error').text('Camera is still initializing. Please wait a moment and try again.').show();
                    btn.prop('disabled', false).text('Capture Selfie');
                    return;
                }
                
                self.videoElement.pause(); // Freeze video
                
                var canvas = document.createElement('canvas');
                canvas.width = self.videoElement.videoWidth;
                canvas.height = self.videoElement.videoHeight;
                canvas.getContext('2d', { willReadFrequently: true }).drawImage(self.videoElement, 0, 0);
                
                var detection = null;
                try {
                    detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();
                } catch (e) {
                    console.error("Face detection error:", e);
                    self.videoElement.play(); // Unfreeze
                    $('#selfie-error').text('An error occurred during face detection. Please try again.').show();
                    btn.prop('disabled', false).text('Capture Selfie');
                    return;
                }
                
                if (detection) {
                    var faceArea = detection.detection.box.width * detection.detection.box.height;
                    var canvasArea = canvas.width * canvas.height;
                    var ratio = faceArea / canvasArea;

                    if (ratio < 0.05) {
                        self.videoElement.play(); // Unfreeze
                        $('#selfie-error').text('Face is too small. Do not use an ID Card, please ensure your real face is close to the camera.').show();
                        btn.prop('disabled', false).text('Capture Selfie');
                        return;
                    }

                    self.selfieDescriptor = detection.descriptor;
                    self.selfieDataUrl = canvas.toDataURL('image/jpeg', 0.6);
                    
                    if (self.config.has_master_face && self.config.master_descriptor) {
                        // Master face exists, verify directly
                        try {
                            self.idCardDescriptor = new Float32Array(JSON.parse(self.config.master_descriptor));
                            self.verifyMatch();
                        } catch (e) {
                            console.error("Invalid master descriptor data:", e);
                            self.videoElement.play(); // Unfreeze
                            notification.alert('NetraGo Error', 'Stored identity data is corrupted. Please contact your instructor to reset your KYC.', 'OK');
                            btn.prop('disabled', false).text('Capture Selfie');
                        }
                    } else {
                        // No master face, proceed to ID card capture
                        self.videoElement.play(); // Unfreeze for ID
                        self.showStep('nf-step-kyc-idcard');
                    }
                } else {
                    self.videoElement.play(); // Unfreeze
                    $('#selfie-error').text('Face not detected! Please ensure you are in a well-lit area and looking at the camera.').show();
                    btn.prop('disabled', false).text('Capture Selfie');
                }
            });

            $('#btn-idcard').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Detecting face...');
                $('#idcard-error').hide();
                
                if (self.videoElement.videoWidth === 0 || self.videoElement.videoHeight === 0) {
                    $('#idcard-error').text('Camera is still initializing. Please wait a moment and try again.').show();
                    btn.prop('disabled', false).text('Capture ID & Verify');
                    return;
                }
                
                self.videoElement.pause(); // Freeze video
                
                var canvas = document.createElement('canvas');
                canvas.width = self.videoElement.videoWidth;
                canvas.height = self.videoElement.videoHeight;
                canvas.getContext('2d', { willReadFrequently: true }).drawImage(self.videoElement, 0, 0);
                
                var detection = null;
                try {
                    detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();
                } catch (e) {
                    console.error("Face detection error:", e);
                    self.videoElement.play(); // Unfreeze
                    $('#idcard-error').text('An error occurred during face detection. Please try again.').show();
                    btn.prop('disabled', false).text('Capture ID & Verify');
                    return;
                }
                
                if (detection) {
                    var faceArea = detection.detection.box.width * detection.detection.box.height;
                    var canvasArea = canvas.width * canvas.height;
                    var ratio = faceArea / canvasArea;

                    if (ratio > 0.15) {
                        self.videoElement.play(); // Unfreeze
                        $('#idcard-error').text('Face in camera is too large. Please show your Official ID Card, NOT your actual face.').show();
                        btn.prop('disabled', false).text('Capture ID & Verify');
                        return;
                    }

                    self.idCardDescriptor = detection.descriptor;
                    self.idCardDataUrl = canvas.toDataURL('image/jpeg', 0.6);
                    self.verifyMatch();
                } else {
                    self.videoElement.play(); // Unfreeze
                    $('#idcard-error').text('Face on ID Card not detected! Please hold it closer to the camera and ensure there is no glare.').show();
                    btn.prop('disabled', false).text('Capture ID & Verify');
                }
            });

            $('#btn-retry').on('click', function() {
                $('#kyc-video-container').show();
                self.showStep('nf-step-kyc-selfie');
                self.videoElement.play(); // unfreeze
                $('#btn-retry').hide();
                $('#btn-selfie').prop('disabled', false).text('Capture Selfie');
                $('#btn-idcard').prop('disabled', false).text('Capture & Verify');
            });
        },

        verifyMatch: function() {
            var self = this;
            
            self.showStep('nf-step-kyc-result');
            
            if ($('#nf-step-kyc-result').length === 0) {
                console.error("CRITICAL: #nf-step-kyc-result is missing from the DOM!");
                notification.alert('NetraGo Error', 'Verification step is missing from the page. Please hard refresh (Cmd+Shift+R).', 'OK');
                self.videoElement.play();
                $('#btn-selfie').prop('disabled', false).text('Capture Selfie');
                return;
            }
            
            $('#kyc-result-spinner').show();
            $('#result-title').text('Verifying Identity...').removeClass('text-success text-danger');
            $('#btn-retry').hide();
            
            try {
                var distance = faceapi.euclideanDistance(this.selfieDescriptor, this.idCardDescriptor);
                
                // 0.60 is standard threshold for ssdMobilenetv1
                if (distance < 0.60) {
                    // Match successful
                    $('#kyc-result-spinner').hide();
                    this.saveKYCResult('success', Array.from(this.selfieDescriptor));
                } else {
                    // Match failed
                    $('#kyc-result-spinner').hide();
                    $('#result-title').text('Verification Failed').addClass('text-danger');
                    $('#result-desc').text('Faces do not match. Please ensure the ID card belongs to you and is clearly visible.');
                    $('#btn-retry').show();
                    this.saveKYCResult('failed', null);
                }
            } catch (e) {
                console.error("Error calculating distance:", e);
                $('#kyc-result-spinner').hide();
                $('#result-title').text('Verification Error').addClass('text-danger');
                $('#result-desc').text('An error occurred while comparing faces. The identity data might be corrupted.');
                $('#btn-retry').show();
            }
        },

        saveKYCResult: function(status, descriptorArray) {
            var self = this;
            
            $.post(this.config.ajaxurl, {
                cmid: this.config.cmid,
                status: status,
                selfiedata: status === 'success' ? self.selfieDataUrl : '',
                ktpdata: status === 'success' && !self.config.has_master_face ? self.idCardDataUrl : '',
                descriptor: JSON.stringify(descriptorArray),
                sesskey: M.cfg.sesskey
            }).done(function(response) {
                var res;
                try {
                    res = typeof response === 'string' ? JSON.parse(response) : response;
                } catch (e) {
                    console.error("Raw Server Response:", response);
                    $('#kyc-result-spinner').hide();
                    $('#result-title').text('Server Error').addClass('text-danger');
                    
                    // Extract title from HTML if it's a Moodle error page
                    var errorMatch = response.match(/<title>(.*?)<\/title>/);
                    var errorMsg = errorMatch ? errorMatch[1] : response.substring(0, 150) + '...';
                    
                    $('#result-desc').text('The server returned an invalid response (HTML instead of JSON). This usually means a database table is missing or a PHP fatal error occurred. Response snippet: ' + errorMsg);
                    $('#btn-retry').show();
                    return;
                }

                if (res.success) {
                    if (status === 'success') {
                        $('#result-title').text('Verification Successful!');
                        $('#result-desc').text('Your identity has been verified. Moving to next step...');
                        $('#kyc-video-container').hide();
                        
                        // Hand off to Proctoring Step 1 (Password)
                        setTimeout(function() {
                            window.netragoKycCompleted = true;
                            // Trigger event so proctoring.js knows to proceed if it was waiting
                            $(document).trigger('netrago_kyc_done');
                        }, 1500);
                    } else {
                        $('#result-title').text('Verification Failed').addClass('text-danger');
                        $('#result-desc').text('The face on the ID card does not match the selfie. Please try again.');
                        $('#btn-retry').show();
                    }
                } else if (res.locked) {
                    $('#result-title').text('Locked Out').addClass('text-danger');
                    $('#result-desc').text(res.message);
                } else {
                    $('#result-title').text('Server Error').addClass('text-danger');
                    $('#result-desc').text(res.error || 'Failed to save KYC verification data.');
                    $('#btn-retry').show();
                }
            }).fail(function(jqXHR, textStatus, errorThrown) {
                $('#kyc-result-spinner').hide();
                $('#result-title').text('Network Error').addClass('text-danger');
                $('#result-desc').text('Failed to contact server: ' + textStatus + '. Please try again.');
                $('#btn-retry').show();
            });
        }
    };

    return NetraGoKYC;
});
