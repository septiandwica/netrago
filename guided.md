# NetraGo AI Proctoring System - Comprehensive Guide

Welcome to the **NetraGo AI Proctoring System**, an advanced security module integrated into **PRESOLA (President Online Learning Academy)**. NetraGo ensures academic integrity during online assessments through AI-powered face tracking, audio monitoring, and strict browser lockdown mechanisms.

This guide provides full details on how the system works for both **Students** taking the exam and **Teachers** configuring the rules.

---

## Part 1: Guide for Students

As a student, NetraGo will monitor your environment and computer activity while you take your exam. The system is designed to be fair but strict.

> [!IMPORTANT]
> **Technical Requirements (Must Read):** 
> - **Device:** You MUST use a desktop or laptop computer. Mobile phones and tablets are not officially supported for full proctoring.
> - **Browser:** You MUST use the latest version of **Google Chrome** or **Microsoft Edge**. Safari or Firefox may cause compatibility issues with Screen Sharing or AI loading.
> - **Connection:** A stable internet connection is required to load the AI models at the beginning of the exam.

### 1.1 The Exam Flow (Step-by-Step)

When you click on a quiz that has NetraGo enabled, you will not enter the quiz immediately. You will go through a rigid verification sequence:

1. **Step 1: Rules & Setup (Welcome Screen)**
   - The system displays the rules set by your teacher (e.g., Camera Required, Audio Monitored, Fullscreen Enforced).
   - Read this carefully to know what is prohibited.
   - Click **Start Setup** to begin hardware initialization.

2. **Step 2: KYC Identity Verification (If Enabled)**
   - **Selfie Verification:** You must allow camera permissions. The system will load the AI and wait until it detects exactly ONE face looking at the camera. Once detected, click **Take Selfie**.
   - **ID Card Scan:** Hold your KTP, KTM, or SIM clearly in front of the camera. Click **Capture & Verify**. The AI will instantly compare your live face against the photo on your ID card.

3. **Step 3: Screen Share (If Enabled)**
   - You will be asked to share your screen. 
   - **CRITICAL NOTE:** You MUST select the **"Entire Screen"** tab and click on the picture of your monitor. Sharing a specific "Window" or "Chrome Tab" is **strictly prohibited** and the system will reject it and block you from proceeding.

4. **Step 4: Consent & Exam Start**
   - You will see a final preview of your camera and screen. 
   - Check the consent checkbox to agree to proctoring, then click **Start attempt**. 
   - Your browser will automatically enter **Fullscreen Mode**, and the exam timer will begin.

### 1.2 Do's and Don'ts During the Exam

> [!WARNING]
> NetraGo operates on a strict "Strike" (Max Violations) system. If your teacher sets a maximum of 3 violations, and you receive your 3rd warning, your exam will be **forcefully terminated, locked, and submitted immediately**. There is no undoing this.

#### ✅ DO:
- **Do** take the exam in a well-lit room so the AI can clearly track your face at all times.
- **Do** ensure your environment is quiet. Tell your family or roommates not to disturb you.
- **Do** keep your face fully within the camera frame.
- **Do** look directly at your monitor. 
- **Do** close all background applications before starting (WhatsApp, Discord, Spotify, etc.).

#### 🚫 DON'T (Violations):
- **Don't** use Virtual Cameras (OBS, ManyCam, XSplit). NetraGo automatically detects and blocks software-emulated webcams.
- **Don't** switch tabs or minimize the browser. Doing so triggers an **INSTANT violation strike**.
- **Don't** exit Fullscreen mode. Pressing ESC triggers an **INSTANT violation strike** and forces a screen-blocking overlay until you return to fullscreen.
- **Don't** use dual monitors. Unplug any secondary monitors before starting, or the system will flag an instant violation.
- **Don't** look down at your lap/desk frequently. The AI analyzes your facial "pitch and yaw" and will flag you for looking at a hidden phone.
- **Don't** have other people in the room. The AI will flag "Multiple faces detected".
- **Don't** talk aloud or play music if Audio Monitoring is active. Sustained noise over 10 seconds will trigger a violation.

### 1.3 How Violations Work
When the system detects a violation, a red warning modal will appear blocking your screen. 
- **Snapshots:** The system silently captures a snapshot of your webcam and your screen the exact millisecond a violation occurs. This is securely saved for your teacher to review.
- **Debounce Mechanism:** To prevent unfair rapid-fire strikes (e.g., exiting fullscreen and switching tabs simultaneously), the system has a **14-second cooldown**. After receiving a warning, you have up to 14 seconds to correct your behavior before another strike can be counted.

---

## Part 2: Guide for Teachers & Administrators

NetraGo is deeply integrated into the Moodle Quiz engine, giving you granular control over exam strictness.

> [!IMPORTANT]
> **Server Note for Administrators:** NetraGo heavily relies on the browser's `getUserMedia` API for camera and screen sharing. This API **requires a secure HTTPS connection**. NetraGo will fail completely if your Moodle site is running on plain HTTP.

### 2.1 Configuring NetraGo for a Quiz

When creating or editing a Quiz, expand the **Extra restrictions on attempts** section. 

#### Available Settings & Technical Behaviors:
1. **Enable NetraGo Proctoring:** The master switch.
2. **Require KYC (Identity Verification):** Forces the 2-step Selfie + ID Card matching process *before* the student sees any quiz questions.
3. **Require Camera (Face Tracking):** 
   - *Technical Behavior:* The AI scans the video feed every **10 seconds**. If the student's face is missing, unrecognized, or looking away (Pitch/Yaw anomaly), a strike is issued.
4. **Require Audio:** 
   - *Technical Behavior:* Uses the Web Audio API to measure decibel levels. A sustained noise spike (average > 40dB) for 10 consecutive seconds triggers a strike.
5. **Require Screen Capture:** 
   - *Technical Behavior:* Forces the student to share their entire monitor. It explicitly rejects Window or Tab sharing.
6. **Enforce Fullscreen Mode:** 
   - *Technical Behavior:* Any attempt to exit fullscreen triggers an **instant** strike and blocks the UI.
7. **Disable Copy, Paste & Selection:** Blocks right-click context menus, dragging, highlighting, and clipboard events via JavaScript.
8. **Enable Focus Loss Detection:** 
   - *Technical Behavior:* Any `blur` or `visibilitychange` event (e.g., clicking on the Windows taskbar, switching Chrome tabs) triggers an **instant** strike.
9. **Enable DevTools & Keyboard Blocking:** Blocks F12, Ctrl+Shift+I, and Ctrl+P.
10. **Maximum Violations (Strikes):** Set the tolerance level. If set to `3`, the 3rd violation instantly triggers a hidden form submission that forces the quiz to finish, marking the attempt as "Terminated by Proctoring".

### 2.2 Best Practices for Teachers

1. **Use 0 Strikes for Practice Exams:** If you set Max Violations to `0`, NetraGo will still show warning modals and capture snapshots when students cheat, but it will *never* forcefully terminate their exam. This is great for mock exams to let students test their hardware.
2. **Warn Students About Lighting:** The #1 cause of "Face Not Found" violations is students sitting with their backs to a bright window (backlighting). Remind them to have the light source in front of them.
3. **Handle Terminated Attempts:** If a student's attempt is terminated due to violations, you will see their attempt as "Finished" in the Moodle grading interface. You can review the captured snapshots on the server to determine if the cheating was genuine or a false positive before deciding whether to allow them a retake.
