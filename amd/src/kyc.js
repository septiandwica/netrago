define('local_netrago/kyc', ['jquery', 'core/ajax'], function($) {

    var NetraGoKYC = {
        config: null,
        videoElement: null,
        selfieDescriptor: null,
        selfieDataUrl: null,
        idCardDescriptor: null,
        idCardDataUrl: null,

        init: function(config) {
            this.config = config;
            this.videoElement = document.getElementById('webcam');
            this.loadModels();
            this.bindEvents();
        },

        loadModels: async function() {
            try {
                var modelPath = M.cfg.wwwroot + '/local/netrago/models';
                await faceapi.nets.ssdMobilenetv1.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                await faceapi.nets.faceRecognitionNet.loadFromUri(modelPath);
                
                this.startCamera();
            } catch (err) {
                $('#kyc-status').text('Failed to load AI models. Please check your connection.');
                console.error(err);
            }
        },

        startCamera: function() {
            var self = this;
            navigator.mediaDevices.getUserMedia({ video: {} })
                .then(function(stream) {
                    self.videoElement.srcObject = stream;
                    $('#video-container').show();
                    self.showStep('step-selfie');
                    $('#kyc-status').text('Camera ready. Please proceed.');
                })
                .catch(function(err) {
                    $('#kyc-status').text("Camera access is required. " + err.message);
                });
        },

        showStep: function(stepId) {
            $('.step').removeClass('active');
            $('#' + stepId).addClass('active');
        },

        bindEvents: function() {
            var self = this;

            $('#btn-selfie').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Detecting face...');
                
                var canvas = document.createElement('canvas');
                canvas.width = self.videoElement.videoWidth;
                canvas.height = self.videoElement.videoHeight;
                canvas.getContext('2d', { willReadFrequently: true }).drawImage(self.videoElement, 0, 0);
                
                var detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();
                
                if (detection) {
                    self.selfieDescriptor = detection.descriptor;
                    self.selfieDataUrl = canvas.toDataURL('image/jpeg', 0.6);
                    self.showStep('step-idcard');
                } else {
                    alert("Face not detected! Please ensure you are in a well-lit area and looking at the camera.");
                    btn.prop('disabled', false).text('Capture Selfie');
                }
            });

            $('#btn-idcard').on('click', async function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Verifying ID Card...');
                
                var canvas = document.createElement('canvas');
                canvas.width = self.videoElement.videoWidth;
                canvas.height = self.videoElement.videoHeight;
                canvas.getContext('2d', { willReadFrequently: true }).drawImage(self.videoElement, 0, 0);
                
                var detection = await faceapi.detectSingleFace(canvas).withFaceLandmarks().withFaceDescriptor();
                
                if (detection) {
                    self.idCardDescriptor = detection.descriptor;
                    self.idCardDataUrl = canvas.toDataURL('image/jpeg', 0.6);
                    self.verifyMatch();
                } else {
                    alert("Face on ID Card not detected! Please hold it closer to the camera and ensure there is no glare.");
                    btn.prop('disabled', false).text('Capture ID & Verify');
                }
            });

            $('#btn-retry').on('click', function() {
                self.showStep('step-selfie');
                $('#btn-selfie').prop('disabled', false).text('Capture Selfie');
                $('#btn-idcard').prop('disabled', false).text('Capture ID & Verify');
                $('#btn-retry').hide();
                $('#btn-continue').hide();
            });
        },

        verifyMatch: function() {
            this.showStep('step-result');
            $('#result-title').text('Analyzing Faces...');
            $('#result-desc').text('Comparing selfie with ID card...');
            
            // Calculate Euclidean distance
            var distance = faceapi.euclideanDistance(this.selfieDescriptor, this.idCardDescriptor);
            var threshold = 0.6; // face-api.js default threshold
            
            if (distance < threshold) {
                // Match successful
                this.saveKYCResult('success', Array.from(this.selfieDescriptor));
            } else {
                // Mismatch
                this.saveKYCResult('failed', []);
            }
        },

        saveKYCResult: function(status, descriptorArray) {
            var self = this;
            
            $.post(this.config.ajaxurl, {
                cmid: this.config.cmid,
                status: status,
                selfiedata: status === 'success' ? self.selfieDataUrl : '',
                ktpdata: status === 'success' ? self.idCardDataUrl : '',
                descriptor: JSON.stringify(descriptorArray),
                sesskey: M.cfg.sesskey
            }).done(function(response) {
                var res = typeof response === 'string' ? JSON.parse(response) : response;
                if (res.success) {
                    if (status === 'success') {
                        $('#result-title').text('Verification Successful!');
                        $('#result-desc').text('Your identity has been verified. You may now start the activity.');
                        $('#btn-continue').show();
                    } else {
                        $('#result-title').text('Verification Failed').addClass('text-danger');
                        $('#result-desc').text('The face on the ID card does not match the selfie. Please try again.');
                        $('#btn-retry').show();
                    }
                } else if (res.locked) {
                    $('#result-title').text('Locked Out').addClass('text-danger');
                    $('#result-desc').text(res.message);
                } else {
                    $('#result-title').text('Error').addClass('text-danger');
                    $('#result-desc').text(res.message);
                    $('#btn-retry').show();
                }
            }).fail(function() {
                $('#result-title').text('Network Error').addClass('text-danger');
                $('#result-desc').text('Failed to communicate with server.');
                $('#btn-retry').show();
            });
        }
    };

    return NetraGoKYC;
});
