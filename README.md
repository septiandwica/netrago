# NetraGo Universal Proctoring for Moodle

NetraGo is a self-hosted, universal Moodle proctoring plugin (`local_netrago`). Unlike standard quiz proctoring plugins, NetraGo can be attached to **any Moodle Activity** (Assignments, Quizzes, Forums, etc.) to prevent and monitor student cheating.

## Features
- **Webcam Tracking**: Captures a snapshot of the student every 60 seconds.
- **Suspicious Event Snapshots**: Automatically captures an instant snapshot when suspicious activity is detected.
- **Tab & Window Monitoring**: Detects when a student switches tabs or minimizes the browser.
- **Fullscreen Enforcement**: Forces students to keep the Moodle activity in fullscreen mode.
- **Anti Copy-Paste**: Disables text selection, right-click context menus, and copy/paste shortcuts (Ctrl+C, Ctrl+V).
- **Universal Teacher Reports**: A comprehensive visual timeline for teachers to review cheating events and snapshots for any enabled activity.

## Installation

1. Clone or download this repository.
2. Place the `netrago` folder inside your Moodle's `local` directory:
   `[moodle_directory]/local/netrago`
3. Log in to Moodle as an Administrator.
4. Moodle will automatically detect the plugin. Follow the on-screen prompts to upgrade the Moodle database.

## Usage

1. Create or edit any Moodle Activity (e.g. an Assignment or Quiz).
2. Scroll down the settings page until you find the **NetraGo Options** section.
3. Toggle the proctoring features you want to enable for this activity:
   - *Require webcam snapshots*
   - *Enforce fullscreen mode*
   - *Disable copy, paste, and text selection*
4. Click **Save and display**.
5. When students access the activity, NetraGo will automatically initialize the proctoring environment.
6. Teachers can view the logs by navigating to:
   `[moodle_url]/local/netrago/report.php?cmid=XXX` (where `XXX` is the Course Module ID of the activity).

## Privacy & Security
NetraGo is entirely **Self-Hosted**. All webcam snapshots and cheating logs are saved directly to your local Moodle database. No data is ever sent to third-party APIs or external SaaS providers.

## License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation.
