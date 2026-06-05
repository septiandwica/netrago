module.exports = function(grunt) {
    grunt.initConfig({
        uglify: {
            dist: {
                files: {
                    'amd/build/kyc.min.js': ['amd/src/kyc.js'],
                    'amd/build/proctoring.min.js': ['amd/src/proctoring.js']
                }
            }
        },
        watch: {
            js: {
                files: ['amd/src/**/*.js'],
                tasks: ['uglify']
            }
        }
    });

    grunt.loadNpmTasks('grunt-contrib-uglify');
    grunt.loadNpmTasks('grunt-contrib-watch');

    grunt.registerTask('amd', ['uglify']);
    grunt.registerTask('default', ['uglify']);
};
