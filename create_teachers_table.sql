-- ============================================
-- TEACHER MANAGEMENT SYSTEM - DATABASE SETUP
-- ============================================
-- Phase 1: Teacher CRUD and Assignment
-- Phase 2 Ready: Email column for future authentication
-- ============================================

-- Create Teachers Table
CREATE TABLE IF NOT EXISTS teachers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    phone VARCHAR(50),
    specialization VARCHAR(255),
    status VARCHAR(20) DEFAULT 'Active' CHECK (status IN ('Active', 'Inactive')),
    join_date DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create Teacher-Subject Assignment Table
CREATE TABLE IF NOT EXISTS teacher_subjects (
    id SERIAL PRIMARY KEY,
    teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
    subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
    year INTEGER NOT NULL CHECK (year IN (1, 2)),
    class_level VARCHAR(10) NOT NULL CHECK (class_level IN ('A', 'B', 'C')),
    assigned_date DATE DEFAULT CURRENT_DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(teacher_id, subject_id, year, class_level)
);

-- Add teacher_id column to subjects table (optional reference)
ALTER TABLE subjects 
ADD COLUMN IF NOT EXISTS teacher_id INTEGER REFERENCES teachers(id) ON DELETE SET NULL;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS idx_teachers_email ON teachers(email);
CREATE INDEX IF NOT EXISTS idx_teachers_status ON teachers(status);
CREATE INDEX IF NOT EXISTS idx_teacher_subjects_teacher ON teacher_subjects(teacher_id);
CREATE INDEX IF NOT EXISTS idx_teacher_subjects_subject ON teacher_subjects(subject_id);
CREATE INDEX IF NOT EXISTS idx_subjects_teacher ON subjects(teacher_id);

-- Insert sample teachers (optional - for testing)
INSERT INTO teachers (name, email, phone, specialization, status, join_date) VALUES
('John Smith', 'john.smith@school.edu', '0750123456', 'Mathematics', 'Active', '2024-01-15'),
('Sarah Ahmed', 'sarah.ahmed@school.edu', '0770234567', 'English Language', 'Active', '2024-02-20'),
('Ahmed Ali', 'ahmed.ali@school.edu', '0780345678', 'Physics', 'Active', '2024-03-10'),
('Fatima Hassan', 'fatima.hassan@school.edu', '0760456789', 'Chemistry', 'Active', '2024-01-25'),
('Omar Ibrahim', 'omar.ibrahim@school.edu', '0750567890', 'Biology', 'Active', '2024-04-05')
ON CONFLICT (email) DO NOTHING;

-- Success message
SELECT 'Teachers tables created successfully!' as message;
