-- Update teachers table to add degree and salary columns, and remove status column

-- Add degree column if it doesn't exist
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name='teachers' AND column_name='degree') THEN
        ALTER TABLE teachers ADD COLUMN degree VARCHAR(100);
    END IF;
END $$;

-- Add salary column if it doesn't exist
DO $$ 
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns 
                   WHERE table_name='teachers' AND column_name='salary') THEN
        ALTER TABLE teachers ADD COLUMN salary INTEGER;
    END IF;
END $$;

-- Remove status column if it exists
DO $$ 
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns 
               WHERE table_name='teachers' AND column_name='status') THEN
        ALTER TABLE teachers DROP COLUMN status;
    END IF;
END $$;

-- Update existing teachers to have default degree if NULL
UPDATE teachers SET degree = 'Bachelor''s Degree' WHERE degree IS NULL;

-- Display success message
SELECT 'Teachers table schema updated successfully!' as message;
