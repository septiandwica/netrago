# NetraGo Universal AI Proctoring for Moodle

NetraGo is a self-hosted, universal Moodle proctoring plugin (`local_netrago`). Unlike standard quiz proctoring plugins, NetraGo can be attached to **any Moodle Activity** (Assignments, Quizzes, Forums, etc.) to prevent and monitor student cheating using Bank-Grade AI Security directly in the browser.

## Features

### 1. AI Identity Verification (KYC Onboarding)
Before a student can access a proctored activity, they must pass a 2-step verification process:
- **Live Selfie**: The student takes a real-time photo of their face.
- **ID Card Scan**: The student shows their ID Card (KTP/KTM).
- **AI Matching**: NetraGo uses `face-api.js` (running 100% locally in the browser) to verify that the person in the selfie matches the ID card.
- **Rate Limiting**: To prevent brute-force attacks, students are locked out for 30 minutes if they fail KYC 5 times in a row.

### 2. Continuous AI Face Tracking (3-Strikes Rule)
Once the quiz starts, the webcam continuously monitors the student every 5 seconds.
- **Face Not Found**: Triggers a warning if the student leaves the frame or looks away for too long.
- **Multiple Faces**: Triggers a warning if a second person (e.g. a helper) appears in the frame.
- **Face Mismatch**: Triggers a warning if the current face does not match the verified KYC baseline.
- **Auto-Kick**: If a student accumulates 3 warnings, their exam is instantly locked and they are automatically kicked out of the activity.

### 3. Bank-Grade Browser Security
- **No-JS Fallback**: If a student intentionally disables JavaScript, the Moodle activity is completely hidden behind a red warning screen. They cannot read the questions.
- **Focus Loss Detection**: Automatically detects and logs if the student clicks outside the browser or uses a dual-monitor setup to cheat.
- **DevTools Detection**: Periodically measures window dimensions to detect if the student opens Developer Tools (F12) to inspect the HTML or modify scripts.
- **Keyboard Shortcut Blocking**: Disables F12, Ctrl+Shift+I, and Print shortcuts (Ctrl+P).
- **Anti Copy-Paste**: Disables text selection, right-click context menus, and copy/paste shortcuts (Ctrl+C, Ctrl+V).

### 4. Universal Teacher Reports
A comprehensive visual timeline for teachers to review cheating events, KYC snapshots, and violation photos for any enabled activity.

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
   - *Require webcam snapshots* (Enables Face Verification)
   - *Enforce fullscreen mode*
   - *Disable copy, paste, and text selection*
4. Click **Save and display**.

## Privacy & Security
NetraGo is entirely **Self-Hosted** and **Serverless AI**. The AI facial recognition models (`face-api.js`) run directly in the student's browser. All KYC data, webcam snapshots, and cheating logs are saved directly to your local Moodle database. No data is ever sent to third-party APIs or external SaaS providers.

## License
This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation.
