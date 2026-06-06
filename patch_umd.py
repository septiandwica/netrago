import re

files_to_patch = ['kyc.php', 'proctor.php']

for file in files_to_patch:
    with open(file, 'r') as f:
        content = f.read()
    
    old_code = "$PAGE->requires->js(new moodle_url('/local/netrago/amd/src/face-api.min.js'));"
    
    new_code = """$faceapi_url = new moodle_url('/local/netrago/amd/src/face-api.min.js');
$js_injection = "
<script>var _temp_define = window.define; window.define = undefined;</script>
<script src=\\"{$faceapi_url}\\"></script>
<script>window.define = _temp_define;</script>
";
$CFG->additionalhtmlhead .= $js_injection;"""

    if old_code in content:
        content = content.replace(old_code, new_code)
        with open(file, 'w') as f:
            f.write(content)
        print(f"Patched {file}")
    else:
        print(f"Already patched or code not found in {file}")
