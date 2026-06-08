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
                
                // Wait for user gesture before accessing camera to prevent browser hang
                if (!window.netragoKycCompleted && this.config.requirecamera) {
                    var self = this;
                    $('#nf-loading-spinner').hide();
                    $('#nf-loading-text').text('Environment Ready');
                    $('#nf-loading-desc').text('Please click the button below to start the camera and begin identity verification.');
                    $('#nf-btn-start-setup').show().on('click', function() {
                        $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Starting camera...');
                        self.startCamera();
                    });
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
            $('.netrago-step').removeClass('active').hide();
            $('#' + stepId).addClass('active').show();
        },

        bindEvents: function() {
            var self = this;

            // btn-agree-intro removed, starts automatically after models load.

            $('#btn-selfie').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Detecting face...');
                
                if (self.videoElement.videoWidth === 0 || self.videoElement.videoHeight === 0) {
                    notification.alert('NetraGo Warning', 'Camera is still initializing. Please wait a moment and try again.', 'OK');
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
                    notification.alert('NetraGo Error', 'An error occurred during face detection. Please try again.', 'OK');
                    btn.prop('disabled', false).text('Capture Selfie');
                    return;
                }
                
                if (detection) {
                    var faceArea = detection.detection.box.width * detection.detection.box.height;
                    var canvasArea = canvas.width * canvas.height;
                    var ratio = faceArea / canvasArea;

                    if (ratio < 0.05) {
                        self.videoElement.play(); // Unfreeze
                        notification.alert('NetraGo Warning', 'Face is too small. Do not use an ID Card, please ensure your real face is close to the camera.', 'Try Again');
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
                        self.showStep('step-idcard');
                    }
                } else {
                    self.videoElement.play(); // Unfreeze
                    notification.alert('NetraGo Warning', 'Face not detected! Please ensure you are in a well-lit area and looking at the camera.', 'Try Again');
                    btn.prop('disabled', false).text('Capture Selfie');
                }
            });

            $('#btn-idcard').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Detecting face...');
                
                if (self.videoElement.videoWidth === 0 || self.videoElement.videoHeight === 0) {
                    notification.alert('NetraGo Warning', 'Camera is still initializing. Please wait a moment and try again.', 'OK');
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
                    notification.alert('NetraGo Error', 'An error occurred during face detection. Please try again.', 'OK');
                    btn.prop('disabled', false).text('Capture ID & Verify');
                    return;
                }
                
                if (detection) {
                    var faceArea = detection.detection.box.width * detection.detection.box.height;
                    var canvasArea = canvas.width * canvas.height;
                    var ratio = faceArea / canvasArea;

                    if (ratio > 0.15) {
                        self.videoElement.play(); // Unfreeze
                        notification.alert('NetraGo Warning', 'Face in camera is too large. Please show your Official ID Card, NOT your actual face.', 'Try Again');
                        btn.prop('disabled', false).text('Capture ID & Verify');
                        return;
                    }

                    self.idCardDescriptor = detection.descriptor;
                    self.idCardDataUrl = canvas.toDataURL('image/jpeg', 0.6);
                    self.verifyMatch();
                } else {
                    self.videoElement.play(); // Unfreeze
                    notification.alert('NetraGo Warning', 'Face on ID Card not detected! Please hold it closer to the camera and ensure there is no glare.', 'Try Again');
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
            
            if ($('#step-result').length === 0) {
                console.error("CRITICAL: #step-result is missing from the DOM!");
                notification.alert('NetraGo Error', 'Verification step is missing from the page. Please hard refresh (Cmd+Shift+R).', 'OK');
                self.videoElement.play();
                $('#btn-selfie').prop('disabled', false).text('Capture Selfie');
                return;
            }
            
            $('#result-icon').attr('class', 'fa fa-spinner fa-spin step-icon');
            $('#result-title').text('Verifying Identity...');
            $('#btn-retry').hide();
            
            try {
                var distance = faceapi.euclideanDistance(this.selfieDescriptor, this.idCardDescriptor);
                
                // 0.60 is standard threshold for ssdMobilenetv1
                if (distance < 0.60) {
                    // Match successful
                    $('#result-icon').attr('class', 'fa fa-check-circle step-icon text-success');
                    this.saveKYCResult('success', Array.from(this.selfieDescriptor));
                } else {
                    // Match failed
                    $('#result-icon').attr('class', 'fa fa-times-circle step-icon text-danger');
                    $('#result-title').text('Verification Failed');
                    $('#result-desc').text('Faces do not match. Please ensure the ID card belongs to you and is clearly visible.');
                    $('#btn-retry').show();
                    this.saveKYCResult('failed', null);
                }
            } catch (e) {
                console.error("Error calculating distance:", e);
                $('#result-icon').attr('class', 'fa fa-times-circle step-icon text-danger');
                $('#result-title').text('Verification Error');
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
                var res = typeof response === 'string' ? JSON.parse(response) : response;
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
                $('#result-icon').attr('class', 'fa fa-times-circle step-icon text-danger');
                $('#result-title').text('Network Error').addClass('text-danger');
                $('#result-desc').text('Failed to contact server: ' + textStatus + '. Please try again.');
                $('#btn-retry').show();
            });
        }
    };

    return NetraGoKYC;
});
