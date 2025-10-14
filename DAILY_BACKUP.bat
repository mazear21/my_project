@echo off
REM ========================================
REM Student Management System - Daily Backup
REM ========================================

echo.
echo ========================================
echo   STUDENT MANAGEMENT SYSTEM
echo   Daily Backup Script
echo ========================================
echo.

REM Set variables
set BACKUP_DIR=C:\xampp\htdocs\my_project\backups
set DATE_STAMP=%date:~-4,4%%date:~-7,2%%date:~-10,2%_%time:~0,2%%time:~3,2%%time:~6,2%
set DATE_STAMP=%DATE_STAMP: =0%

REM Create backup directory if it doesn't exist
if not exist "%BACKUP_DIR%" (
    echo Creating backup directory...
    mkdir "%BACKUP_DIR%"
)

echo Current Date/Time: %DATE_STAMP%
echo Backup Directory: %BACKUP_DIR%
echo.

REM ========================================
REM 1. DATABASE BACKUP
REM ========================================
echo [1/3] Backing up PostgreSQL database...
set DB_BACKUP=%BACKUP_DIR%\database_backup_%DATE_STAMP%.sql

REM Find PostgreSQL bin directory
set PG_BIN=C:\Program Files\PostgreSQL\16\bin
if not exist "%PG_BIN%" (
    set PG_BIN=C:\Program Files\PostgreSQL\15\bin
)
if not exist "%PG_BIN%" (
    set PG_BIN=C:\Program Files\PostgreSQL\14\bin
)

if exist "%PG_BIN%" (
    "%PG_BIN%\pg_dump.exe" -U postgres -d student_db -f "%DB_BACKUP%"
    if errorlevel 1 (
        echo ERROR: Database backup failed!
    ) else (
        echo SUCCESS: Database backed up to %DB_BACKUP%
    )
) else (
    echo WARNING: PostgreSQL bin directory not found!
    echo Please backup database manually using:
    echo pg_dump -U postgres student_db ^> "%DB_BACKUP%"
)

echo.

REM ========================================
REM 2. GIT COMMIT (if in git repo)
REM ========================================
echo [2/3] Committing to Git...
cd /d C:\xampp\htdocs\my_project

git status >nul 2>&1
if errorlevel 1 (
    echo WARNING: Not a git repository or git not installed
) else (
    echo Enter commit message (or press Enter for default):
    set /p COMMIT_MSG="Commit message: "
    
    if "%COMMIT_MSG%"=="" (
        set COMMIT_MSG=Daily backup - %DATE_STAMP%
    )
    
    git add .
    git commit -m "%COMMIT_MSG%"
    
    echo.
    echo Push to GitHub? (Y/N)
    set /p PUSH_CHOICE="Choice: "
    
    if /i "%PUSH_CHOICE%"=="Y" (
        git push origin main
        echo SUCCESS: Changes pushed to GitHub
    ) else (
        echo Skipped pushing to GitHub
    )
)

echo.

REM ========================================
REM 3. FILE BACKUP (ZIP)
REM ========================================
echo [3/3] Creating file archive...

REM Check if 7-Zip is installed
set ZIP_TOOL=
if exist "C:\Program Files\7-Zip\7z.exe" set ZIP_TOOL="C:\Program Files\7-Zip\7z.exe"
if exist "C:\Program Files (x86)\7-Zip\7z.exe" set ZIP_TOOL="C:\Program Files (x86)\7-Zip\7z.exe"

if defined ZIP_TOOL (
    set FILE_BACKUP=%BACKUP_DIR%\files_backup_%DATE_STAMP%.7z
    %ZIP_TOOL% a -t7z "%FILE_BACKUP%" "C:\xampp\htdocs\my_project\*" -xr!backups -xr!.git -xr!node_modules
    echo SUCCESS: Files archived to %FILE_BACKUP%
) else (
    echo WARNING: 7-Zip not found. Install 7-Zip for automatic file archiving.
    echo Or manually copy the my_project folder to a safe location.
)

echo.

REM ========================================
REM CLEANUP OLD BACKUPS (Optional)
REM ========================================
echo.
echo Delete backups older than 30 days? (Y/N)
set /p CLEANUP_CHOICE="Choice: "

if /i "%CLEANUP_CHOICE%"=="Y" (
    echo Cleaning up old backups...
    forfiles /p "%BACKUP_DIR%" /s /m *.* /d -30 /c "cmd /c del @path" 2>nul
    echo Done!
)

REM ========================================
REM SUMMARY
REM ========================================
echo.
echo ========================================
echo   BACKUP COMPLETE!
echo ========================================
echo.
echo Backup Location: %BACKUP_DIR%
echo.
echo Please verify:
echo 1. Database backup file exists
echo 2. Git commit was successful
echo 3. File archive was created
echo.
echo ========================================

pause
