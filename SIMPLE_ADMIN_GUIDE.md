# Simple Admin Management Guide

## ✅ Auto-Hash Login System - ACTIVE

### How It Works Now

The system now **automatically detects and converts plain text passwords** to hashed passwords on first login!

---

## Adding New Admins - Super Simple!

### Step 1: Add in pgAdmin with Plain Text Password

```sql
INSERT INTO admin_users (username, password, full_name, email, role, is_active)
VALUES ('newadmin', 'mypassword123', 'New Administrator', 'admin@school.edu', 'admin', true);
```

**That's it!** Just use a plain text password like `mypassword123`

### Step 2: Login for First Time

1. Go to login page: `http://localhost/my_project/login.php`
2. Enter username: `newadmin`
3. Enter password: `mypassword123`
4. Click **Sign In**

**The system automatically:**

- ✅ Detects it's a plain text password
- ✅ Verifies it matches
- ✅ Immediately hashes it with bcrypt
- ✅ Updates the database with hashed password
- ✅ Logs you in successfully

### Step 3: Done!

From now on, the password is securely hashed in the database. Next login will use the hashed version automatically.

---

## Your Current Admin

Your existing admin with username `admin` and password `admin123`:

**First Login:** Just login normally with `admin123` - the system will auto-hash it!

After first login, the password will be converted from plain text `admin123` to a secure bcrypt hash like:

```
$2y$10$abcdefg....(60 characters)
```

---

## Quick Examples

### Example 1: Add Admin via pgAdmin

```sql
-- Just use plain text password!
INSERT INTO admin_users (username, password, full_name, email, is_active)
VALUES ('john.doe', 'Welcome2024', 'John Doe', 'john@school.edu', true);
```

**Login:** username: `john.doe`, password: `Welcome2024`  
**Result:** Password auto-hashed on first login ✓

### Example 2: Add Another Admin

```sql
INSERT INTO admin_users (username, password, full_name, email, is_active)
VALUES ('superadmin', 'Super@Pass123', 'Super Admin', 'super@school.edu', true);
```

**Login:** username: `superadmin`, password: `Super@Pass123`  
**Result:** Password auto-hashed on first login ✓

### Example 3: Update Existing Admin Password

```sql
-- Reset to plain text password
UPDATE admin_users SET password = 'NewPassword456' WHERE username = 'admin';
```

**Login:** username: `admin`, password: `NewPassword456`  
**Result:** Password auto-hashed on first login ✓

---

## How the System Detects Password Type

The login system tries two methods in order:

1. **Try Bcrypt Verification** (for already hashed passwords)

   ```php
   if (password_verify($password, $stored_password))
   ```

2. **Try Plain Text Match** (for newly added passwords)
   ```php
   if ($password === $stored_password)
   ```

If method #2 matches:

- Login succeeds
- Password is immediately hashed: `password_hash($password, PASSWORD_DEFAULT)`
- Database is updated with hashed password
- Next login uses method #1 (bcrypt)

---

## Security Notes

✅ **Secure:** Plain text passwords are only in database temporarily (until first login)  
✅ **Automatic:** No manual hashing needed  
✅ **Backward Compatible:** Works with both hashed and plain text passwords  
✅ **Safe:** Uses bcrypt algorithm (industry standard)  
✅ **Audit Trail:** All logins are logged in `audit_log` table

---

## Teacher Accounts - Same System!

This auto-hash system also works for teachers:

```sql
INSERT INTO teachers (name, email, username, password, specialization, degree, is_active)
VALUES ('Jane Smith', 'jane@school.edu', 'jane.smith', 'Teacher123', 'Mathematics', 'PhD', true);
```

**Login:** username: `jane.smith`, password: `Teacher123`  
**Result:** Password auto-hashed on first login ✓

---

## Troubleshooting

### "Invalid username or password" Error

**Check:**

1. Username is correct (case-sensitive)
2. User is marked as `is_active = true` in database
3. Password matches exactly (case-sensitive)

### Verify in pgAdmin:

```sql
SELECT id, username, is_active, LENGTH(password) as pass_length
FROM admin_users
WHERE username = 'your_username';
```

**If password length:**

- Less than 20 characters = Plain text (will auto-hash on login)
- 60 characters = Already hashed with bcrypt (use original password)

---

## Summary - What Changed

### Before (Complex):

1. Add admin in pgAdmin with plain text password
2. Open hash_password.php tool
3. Generate bcrypt hash
4. Copy hash back to pgAdmin
5. Update admin record
6. Then login

### Now (Simple):

1. Add admin in pgAdmin with plain text password
2. Login
3. Done! (auto-hashed)

---

**No more tools needed! No more complex steps! Just add and login!** 🎉

---

**Created:** November 13, 2025  
**Status:** ✅ Active and Ready to Use
