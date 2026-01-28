<p align="center">
  <img src="assets/images/logo.png" alt="CheckMate Logo" width="180">
</p>

<h1 align="center">CheckMate LMS</h1>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.x-777bb4?style=for-the-badge&logo=php">
  <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql">
  <img src="https://img.shields.io/badge/AI-Gemini%20Flash-orange?style=for-the-badge&logo=google">
  <img src="https://img.shields.io/badge/Security-Two--Phase%20QR-red?style=for-the-badge">
</p>

<p align="center">
  <strong>CheckMate</strong> is an AI-powered University Attendance and Learning Management System (LMS)  
  designed to ensure real physical presence in classrooms and provide intelligent academic insights  
  using <strong>Google Gemini AI</strong>.
</p>

---

## 🚀 Key Features

### 🔐 Two-Phase Smart Attendance System

Built to prevent proxy attendance and ensure students are physically present in the classroom.

**Phase 1 — Session Access**  
Students enter a static 8-digit session key provided by the instructor to join the class session.

**Phase 2 — Live Verification**  
A dynamic QR code and rotating verification key are displayed on the classroom screen and refreshed every 15 seconds.  
Students must scan the live code to complete attendance validation.

---

### 🤖 AI-Powered Academic Intelligence (Gemini API)

Powered by Google Gemini Flash to transform educational data into actionable insights.

- **AI Quiz Generator** – Automatically creates quizzes from uploaded course materials  
- **Automated Grading & Feedback** – Evaluates student answers and provides instant feedback  
- **Classroom Performance Analytics** – Detects difficult topics and suggests areas for re-teaching

---

### 📋 Administration & Classroom Management

- **Bulk User Import** – Add students and staff via CSV upload  
- **Communication Hub** – SMTP-based email notifications and internal messaging  
- **Assignment Management** – Secure file upload and distribution  
- **Absenteeism Monitoring** – Visual statistics and automated warning alerts

---

## 🛠️ Technology Stack

- **Backend:** PHP 8.x (OOP), MySQL (PDO)  
- **AI Engine:** Google Gemini Flash API  
- **Frontend:** Bootstrap 5, Vanilla JavaScript, FontAwesome 6  
- **Security:** CSRF & XSS Protection, Brute-force Protection, Secure File Upload  
- **Libraries:** PHPMailer, Endroid QR Code, PhpSpreadsheet, TCPDF

---

## 📁 Project Structure

```text
check-mate/
├── admin/        # Management portal (CSV import, SMTP, user management)
├── teacher/      # Faculty tools (attendance, AI quizzes, analytics)
├── student/      # Student portal (mobile-friendly attendance, assignments)
├── config/       # Environment and database configuration
├── includes/     # Core logic (auth, AI, mailer, database)
├── assets/       # UI assets and scripts
└── uploads/      # User files (ignored by Git)
````

---

## ⚙️ Configuration

* **Gemini API:** Add your API key to `.env` or `config.php`
* **SMTP:** Configure mail server settings in the Admin Panel
* **CSV Import:** Use the provided templates to bulk upload users

---

## 📜 License

This project is licensed under the MIT License.

---

## 👨‍💻 Author & Organization

**Author:** Ali Harun Daldallı
**Organization:** Ahd Akademi 
**Contact:** [info@ahdakade.com](mailto:info@ahdakade.com)

<p align="center">Built with ❤️ for Academic Excellence</p>
