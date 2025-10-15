# 🎓 Student Management System

A comprehensive web-based student management system built with PHP and PostgreSQL.

## 📋 Overview

This system manages student information, grades, subjects, and graduation processes for a two-year academic program.

## ✨ Features

### Core Functionality

- 👥 **Student Management** - Add, edit, delete, and track students
- 📚 **Subject Management** - Manage courses with credit weighting
- 📊 **Grading System** - Credit-weighted final grade calculation
- 🎓 **Graduation System** - Two-year program (Year 1 + Year 2 = 100 points)
- 📈 **Dashboard & Reports** - KPIs, charts, and analytics
- 🌍 **Multi-language** - English, Arabic (RTL), Kurdish (RTL)
- 📅 **Class Schedules** - Weekly timetables for all classes

### Grading System

- **Mark Components:**
  - Final Exam (max 60 points)
  - Midterm Exam (max 20 points)
  - Quizzes (max 10 points)
  - Daily Activities (max 10 points)
- **Credit-Weighted Calculation:**
  ```
  Final Grade = Total Mark × (Credits ÷ 100)
  ```

### Academic Structure

- **Year 1 Subjects** (50 credits total)
- **Year 2 Subjects** (50 credits total)
- **Graduation** = Year 1 Final Grade + Year 2 Final Grade (max 100 points)

## 🛠️ Technology Stack

- **Backend:** PHP 8.0+
- **Database:** PostgreSQL
- **Frontend:** HTML5, CSS3, JavaScript
- **Charts:** Chart.js
- **Animations:** AOS (Animate On Scroll)

## 📦 Installation

### Prerequisites

- XAMPP (or similar) with Apache
- PostgreSQL 13+
- PHP 8.0+

### Setup Steps

1. **Clone the repository:**

   ```bash
   git clone https://github.com/yourusername/my_project.git
   cd my_project
   ```

2. **Enable PostgreSQL in PHP:**

   - Open `C:\xampp\php\php.ini`
   - Uncomment these lines:
     ```ini
     extension=pdo_pgsql
     extension=pgsql
     ```
   - Restart Apache

3. **Create the database:**

   ```sql
   CREATE DATABASE student_db;
   ```

4. **Configure database connection:**

   - Edit `db.php` with your credentials:
     ```php
     $conn = pg_connect("host=localhost dbname=student_db user=postgres password=yourpassword");
     ```

5. **Import database structure:**

   ```bash
   psql -U postgres student_db < setup_database.php
   ```

6. **Access the application:**
   ```
   http://localhost/my_project/
   ```

## 📊 Database Schema

### Tables

- `students` - Student information
- `subjects` - Course catalog
- `marks` - Student grades
- `graduated_students` - Graduation records
- `promotion_history` - Year transition tracking

## 🎯 Usage

### Dashboard

View key performance indicators and charts

### Students Page

- Add new students
- Edit student information
- Promote students (Year 1 → Year 2)
- Graduate students (Year 2 → Graduated)

### Subjects Page

- Manage course catalog
- Set credit values
- Assign to Year 1 or Year 2

### Marks Page

- Enter student grades
- Automatic final grade calculation
- Filter by student, subject, or year

### Graduated Page

- View graduated students
- Calculate graduation grades
- Export graduation records

## 💾 Backup

### Web-based Backup

```
http://localhost/my_project/quick_backup.php
```

### Command Line Backup

```bash
pg_dump -U postgres student_db > backup.sql
```

### Automated Daily Backup

Run `DAILY_BACKUP.bat` (Windows)

## 📚 Documentation

- `START_HERE.md` - Quick start guide
- `PROJECT_RECOVERY_GUIDE.md` - Complete project overview
- `REBUILD_CHECKLIST.md` - Feature tracking
- `FINAL_GRADE_IMPLEMENTATION.md` - Grading system details
- `GRADUATION_SYSTEM_COMPLETE.md` - Graduation process

## 🔒 Security Notes

- Change default database password in `db.php`
- Implement user authentication (recommended)
- Use HTTPS in production
- Regular database backups

## 🚀 Future Enhancements

- [ ] User authentication system
- [ ] Export to Excel/PDF
- [ ] Attendance tracking
- [ ] Email notifications
- [ ] Parent portal
- [ ] Mobile app
- [ ] Advanced analytics

## 📝 License

This project is for educational purposes.

## 🤝 Contributing

Contributions, issues, and feature requests are welcome!

## 📞 Support

For issues or questions, please open an issue in the GitHub repository.

## 🙏 Acknowledgments

Built with dedication to improve educational management.

---

**Version:** 1.0 (Recovery Checkpoint)  
**Last Updated:** October 14, 2025  
**Status:** Active Development
