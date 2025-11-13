-- Check the current structure of students table
SELECT column_name, data_type, character_maximum_length, is_nullable
FROM information_schema.columns 
WHERE table_name = 'students' 
ORDER BY ordinal_position;

-- Check if email column still exists
SELECT column_name 
FROM information_schema.columns 
WHERE table_name = 'students' 
AND column_name = 'email';

-- Check sample data
SELECT * FROM students LIMIT 5;
