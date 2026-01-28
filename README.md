# <p align="center"><img src="assets/images/logo.png" alt="CheckMate Logo" width="180"></p>

<h1 align="center">CheckMate LMS</h1>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-7.4+-777bb4.svg?style=for-the-badge&logo=php" alt="PHP Version">
  <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1.svg?style=for-the-badge&logo=mysql" alt="MySQL Version">
  <img src="https://img.shields.io/badge/License-MIT-green.svg?style=for-the-badge" alt="License">
  <img src="https://img.shields.io/badge/Maintained%3F-yes-brightgreen.svg?style=for-the-badge" alt="Maintained">
</p>

---

<p align="center">
  <b>CheckMate</b> is a professional, secure, and user-friendly university attendance management system. 
  Leveraging dynamic QR codes and two-phase security, it ensures physical presence while providing powerful tools for course management, assignments, and exams.
</p>

---

![Open Graph Image](assets/images/og-image.png)

## ✨ Features

### 🔐 Two-Phase Attendance Security
*   **Phase 1**: Static 8-digit key provided by the teacher.
*   **Phase 2**: Dynamic QR code/Secondary key that rotates every 15 seconds.
*   *Guarantees students are physically present in the classroom.*

### 👥 Multi-Role Support
*   **Admin Dashboard**: Statistics, user management (Teacher/Student), faculty/department setup, and site-wide announcements.
*   **Teacher Panel**: Start dynamic sessions, manage courses, create quizzes with real-time reporting, and handle timed assignments.
*   **Student Hub**: Mobile-optimized interface for attendance, submission tracking, exam participation, and material access.

### 📊 Advanced Reporting & Alerts
*   Detailed absenteeism tracking with visual charts (%25 limit warnings).
*   Bulk export options (Excel, PDF) for attendance lists.
*   Automated email notifications via SMTP.

### 🔒 Security First
*   **CSRF & XSS Protection**: All forms are secured against common web attacks.
*   **Secure Multi-File Upload**: MIME-type validation and strictly enforced file extensions for assignments and materials.
*   **Brute Force Protection**: Login attempt limiting and rate control.

---

## 🚀 Getting Started

### Prerequisites
*   **PHP**: 7.4 or higher
*   **Database**: MySQL 5.7+ / 8.0+
*   **Dependency Manager**: Composer
*   **Web Server**: Apache or Nginx

### Installation

1.  **Clone the Repository**
    ```bash
    git clone https://github.com/aliharundaldalli/Check_Mate.git
    cd Check_Mate
    ```

2.  **Install Dependencies**
    ```bash
    composer install
    ```

3.  **Configuration**
    *   **Environment**: Copy the example environment file.
        ```bash
        cp .env.example .env
        ```
        Update `.env` with your database credentials and `GEMINI_API_KEY`.
    *   **App Config**: Copy the example config file.
        ```bash
        cp config/config.example.php config/config.php
        ```
        Set your `SITE_URL` and verify database settings in `config.php`.

4.  **Database Setup**
    *   Create a MySQL database (e.g., `check_mate_db`).
    *   Import the provided SQL schema (if available) or create tables using the provided logic.

5.  **Permissions**
    ```bash
    chmod -R 755 uploads logs
    ```

---

## 🛠️ Tech Stack & Librariers

*   **Backend**: PHP (OOP Architecture), MySQL (PDO), PHPMailer.
*   **Frontend**: Bootstrap 5, FontAwesome 6, Vanilla JS.
*   **Reporting**: PhpSpreadsheet, TCPDF.
*   **Tools**: Endroid QR Code.

---

## 📁 Project Structure

```text
check-mate/
├── admin/       # Administrative management portal
├── teacher/     # Faculty & Course management tools
├── student/     # Student portal (Attendance & Submissions)
├── config/      # System configuration & environment loaders
├── includes/    # Core logic, classes (Auth, DB, Mail), and components
├── assets/      # Public assets (UI, Brand, JS/CSS)
├── uploads/     # User-submitted content (Ignored by Git)
└── vendor/      # Third-party libraries (Composer)
```

---

## 📜 License & Author

Distributed under the **MIT License**. See `LICENSE` for more information.

**Author**: [Ali Harun Daldallı](https://github.com/aliharundaldalli)  
**Organization**: AhdaKade Team  
**Contact**: info@ahdakade.com

---
<p align="center">Made with ❤️ for Academic Excellence.</p>
