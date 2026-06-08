# NetraGo Universal AI Proctoring for Moodle

NetraGo is a self-hosted, universal Moodle proctoring plugin (`local_netrago`). Unlike standard quiz proctoring plugins, NetraGo can be attached to **any Moodle Activity** (Assignments, Quizzes, Forums, etc.) to prevent and monitor student cheating using Bank-Grade AI Security directly in the browser.

## Key Features

### 1. Dual-Layered Configuration
NetraGo provides strict, hierarchical control over proctoring features:
- **Global Settings (Site Admin)**: Administrators can globally enable or disable specific features (e.g., disabling the Screen Tracking feature for the entire university).
- **Local Settings (Teacher/Lecturer)**: When creating a Quiz or Activity, teachers can selectively enforce features that the Admin has globally permitted. Features include:
  - Require Camera (Face Tracking)
  - Require Screen Capture
  - Enforce Fullscreen
  - Disable Tab Switching / Focus Loss
  - Disable Copy-Paste
  - Disable Developer Tools (F12, Inspect Element)

### 2. AI Identity Verification (KYC Onboarding)
Before a student can view the quiz questions, they are intercepted by the NetraGo Pre-flight system:
- **Live Selfie**: The student takes a real-time photo. AI extracts their facial structure (Face Descriptor).
- **Official ID Card Scan**: The student scans their ID Card (KTP/KTM).
- **AI Biometric Matching**: NetraGo uses `face-api.js` to calculate the Euclidean distance between the selfie and the ID card. If it matches, the selfie is locked into the database as their **Master Face**.
- **Teacher Review**: KYC photos are saved permanently. Teachers can manually review these photos in the Proctoring Report and click **"Reset KYC Verification"** to revoke the identity if they suspect a spoofing attempt (like scanning a photo instead of a real ID).

### 3. Continuous & Instant AI Face Tracking
Once the quiz starts, the activity is securely encapsulated in an iframe, and NetraGo takes over the browser environment:
- **Routine Background Tracking**: Every 15 seconds, the AI quietly compares the student's live face against their **Master Face**. If it detects a mismatch, multiple faces, or no face, a `Face Violation` is recorded.
- **Instant Spontaneous Checks**: If a student performs a sudden behavioral violation (e.g., switching tabs), the system instantly takes a spontaneous snapshot and runs a real-time AI face verification. If a different person is in the frame at that exact moment, it instantly flags the image with an `unrecognized_face` red badge.

### 4. Behavior Tracking & Auto-Submit Enforcement (Max Strikes)
Students accumulate "Strikes" for every violation (Face mismatch, Tab switch, exiting Fullscreen).
- **Mild Warnings**: The screen momentarily blurs for 3 seconds with a stark warning to look at the camera.
- **The "Auto-Submit" Protocol**: If the maximum strike limit (e.g., 3 strikes) is reached:
  1. The screen instantly goes black, displaying a "FINAL WARNING EXCEEDED" lockout screen.
  2. Camera and screen streams are forcefully terminated.
  3. NetraGo **seizes control of the Moodle Quiz form and forcefully auto-submits it**.
  4. The student's attempt is permanently locked, and they are kicked out to the review page with a termination alert.

### 5. Comprehensive Visual Reporting
Teachers are equipped with a powerful dashboard to review exams:
- **Gradebook Badges**: Any student with violations automatically receives a red `⚠️ X Violations` badge directly in the Moodle Gradebook.
- **Interactive Timelines**: Clicking the badge opens the NetraGo Report, displaying chronological, side-by-side timelines of Camera Tracking and Screen Tracking.
- **Smart Highlighting**: Normal snapshots are displayed with grey borders. Verified violations (Tab Switches on the screen, Unrecognized Faces on the camera) are **highlighted with thick red borders** for rapid review.

## Installation

1. Clone or download this repository.
2. Place the `netrago` folder inside your Moodle's `local` directory:
   `[moodle_directory]/local/netrago`
3. Log in to Moodle as an Administrator.
4. Moodle will automatically detect the plugin. Follow the on-screen prompts to upgrade the Moodle database.

## Privacy & Security

NetraGo is entirely **Self-Hosted** and **Serverless AI**. The AI facial recognition models (`face-api.js`) run directly in the student's browser. All KYC data, webcam snapshots, and cheating logs are saved directly to your local Moodle database. No biometric data is ever sent to third-party APIs or external SaaS providers.

## License

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation.

## Development & Support

Developed and maintained by [Tateta](https://samastanuswantara.com) and [Septian Dwi Cahyo](https://github.com/septiandwica).
