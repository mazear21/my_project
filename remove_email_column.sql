-- Clean up students and graduated_students tables - Remove ALL unused columns
-- BACKUP YOUR DATA FIRST!

-- Remove unused columns from students table
ALTER TABLE public.students 
DROP COLUMN IF EXISTS email,
DROP COLUMN IF EXISTS department,
DROP COLUMN IF EXISTS department_id,
DROP COLUMN IF EXISTS leave_date,
DROP COLUMN IF EXISTS address,
DROP COLUMN IF EXISTS academic_year;

-- Remove unused columns from graduated_students table  
ALTER TABLE public.graduated_students 
DROP COLUMN IF EXISTS email;

-- Verify the changes
SELECT column_name, data_type 
FROM information_schema.columns 
WHERE table_name = 'students' 
ORDER BY ordinal_position;

SELECT column_name, data_type 
FROM information_schema.columns 
WHERE table_name = 'graduated_students' 
ORDER BY ordinal_position;
