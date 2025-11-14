@echo off
REM ============================================
REM Database Backup Script for Railway Deployment
REM ============================================

echo.
echo ========================================
echo Creating Database Backup...
echo ========================================
echo.

REM Set backup filename with timestamp
set timestamp=%date:~-4%%date:~3,2%%date:~0,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set timestamp=%timestamp: =0%
set filename=railway_backup_%timestamp%.sql

echo Exporting database to: %filename%
echo.

REM Export database (adjust path if PostgreSQL is in different location)
"C:\Program Files\PostgreSQL\15\bin\pg_dump.exe" -U postgres -h localhost student_db > %filename%

if %errorlevel% equ 0 (
    echo.
    echo ========================================
    echo SUCCESS! Database backed up to:
    echo %filename%
    echo ========================================
    echo.
    echo You can now upload this file to Railway
    echo File location: %cd%\%filename%
    echo.
) else (
    echo.
    echo ========================================
    echo ERROR! Backup failed.
    echo ========================================
    echo.
    echo Please check:
    echo 1. PostgreSQL is running
    echo 2. Database name is correct: student_db
    echo 3. PostgreSQL path is correct
    echo.
)

pause
