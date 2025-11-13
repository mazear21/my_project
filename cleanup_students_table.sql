-- Clean up students table - Remove unused columns
-- BACKUP YOUR DATA FIRST!

-- Remove unused columns from students table
ALTER TABLE public.students 
DROP COLUMN IF EXISTS email,
DROP COLUMN IF EXISTS department,
DROP COLUMN IF EXISTS department_id,
DROP COLUMN IF EXISTS leave_date,
DROP COLUMN IF EXISTS address,
DROP COLUMN IF EXISTS academic_year;

-- Verify the cleaned structure
SELECT column_name, data_type, column_default
FROM information_schema.columns 
WHERE table_name = 'students' 
ORDER BY ordinal_position;

-- The final structure should have these columns:
-- id, name, join_date, age, gender, class_level, phone, graduation_status, year, status
