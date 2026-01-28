<p align="center"><img src="assets/images/logo.png" alt="CheckMate Logo" width="180"></p>

<h1 align="center">CheckMate LMS</h1>

<p align="center">
<img src="https://img.shields.io/badge/PHP-7.4+-777bb4.svg?style=for-the-badge&logo=php" alt="PHP Version">
<img src="https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?style=for-the-badge&logo=mysql" alt="MySQL Version">
</p>

<p align="center">
<b>CheckMate</b> is an AI-powered, professional University Attendance and Learning Management System (LMS).
Beyond traditional tracking, it utilizes <b>Two-Phase Dynamic Authentication</b> to guarantee physical presence and leverages <b>Google Gemini AI</b> for automated academic success analysis.
</p>

🚀 Key Features

🔐 Two-Phase Smart Attendance

Designed to eliminate attendance fraud (proxy attendance):

Phase 1 (Pre-Entry): Students enter an 8-digit static key provided by the instructor to access the session.

Phase 2 (Live Verification): During the lecture, a Dynamic QR Code and a secondary key rotate every 15 seconds on the screen. Students must scan this code or enter the key in real-time to be marked as "Present."

🤖 AI-Powered Academic Assistant (Gemini AI)

The system integrates Google Gemini API to automate and enhance the learning process:

AI Quiz Generator: Automatically creates quizzes based on uploaded course materials.

Automated Grading: AI evaluates student answers, providing instant feedback and scoring.

Pedagogical Reporting: Analyzes class-wide performance. It identifies specific questions where students struggled and suggests topics the instructor should revisit.

👥 Comprehensive Management Panels

Admin Dashboard:

Bulk Import: Seamlessly add thousands of students and teachers via CSV upload.

System Control: Manage SMTP settings, faculty/department structures, and site-wide announcements.

Teacher Portal:

Start dynamic attendance sessions.

Upload course materials and manage assignments.

Send mass notifications to students.

Access AI-generated performance reports.

Student Hub:

Mobile-optimized interface for scanning QR codes.

Track attendance limits with visual charts (25% absenteeism warning).

Submit assignments and participate in AI-driven exams.

📊 Communication & Alerts

Messaging System: Real-time communication channel between teachers and students.

Automated Notifications: System-wide alerts and email notifications powered by SMTP.

Reporting: Export attendance and grade lists in PDF or Excel format.

🛠️ Tech Stack & Libraries

Backend: PHP 8.x (OOP Architecture), MySQL (PDO)

AI Engine: Google Gemini Flash API

Frontend: Bootstrap 5, Vanilla JavaScript, FontAwesome 6

Email & Export: PHPMailer, PhpSpreadsheet, TCPDF

Security: CSRF/XSS protection, Brute Force protection, and MIME-type validation for secure file uploads.

📁 Project Structure

check-mate/
├── admin/       # Administrative portal (CSV Import, SMTP Setup, User Management)
├── teacher/     # Instructor tools (Attendance, AI Quiz, Reporting)
├── student/     # Student portal (QR Scan, Assignment Submission)
├── includes/    # Core logic (AI Manager, Auth, Database, Mailer classes)
├── config/      # Environment variables and system configurations
├── assets/      # UI components, CSS/JS, and branding
└── uploads/     # User-generated content (Ignored by Git for security)


⚙️ Installation

Clone & Install:

git clone [https://github.com/aliharundaldalli/Check_Mate.git](https://github.com/aliharundaldalli/Check_Mate.git)
composer install


Environment: Update .env with your GEMINI_API_KEY and Database credentials.

SMTP Configuration: Navigate to the Admin Panel -> Settings to configure your mail server for notifications.

Permissions: Ensure uploads/ and logs/ directories are writable.

📜 License & Author

Distributed under the MIT License.

Author: Ali Harun Daldallı

Organization: Ahd Akademi 

Contact: info@ahdakade.com

<p align="center">Built with ❤️ for Academic Excellence.</p>
