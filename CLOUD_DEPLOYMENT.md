# 🚀 Deploy Your App to Railway.app (Free Cloud Hosting)

## Why Railway.app?
- ✅ **Free Tier** - No credit card required
- ✅ **Works in Iraq** - Accessible worldwide
- ✅ **Easy Setup** - Deploy in 5 minutes
- ✅ **PostgreSQL Included** - Free database
- ✅ **Auto SSL** - HTTPS automatically
- ✅ **Team Access** - Share URL with 5+ people

---

## 📋 Quick Deployment Steps

### Step 1: Prepare Your Project (2 minutes)

First, let's make sure your project is ready for deployment.

**A. Create `.gitignore` file** (if not exists):
```bash
# In PowerShell, run:
cd C:\xampp\htdocs\my_project
New-Item -ItemType File -Name .gitignore -Force
```

Add this content to `.gitignore`:
```
.env
*.log
tmp/*
vendor/*
node_modules/*
```

**B. Update `db.php` for environment variables:**

Your current `db.php` probably looks like:
```php
$conn = pg_connect("host=localhost dbname=student_management user=postgres password=yourpass");
```

Change it to support environment variables:
```php
<?php
// Check if we're on Railway (production) or local
$host = getenv('PGHOST') ?: 'localhost';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'student_management';
$user = getenv('PGUSER') ?: 'postgres';
$password = getenv('PGPASSWORD') ?: 'your_local_password';

$conn = pg_connect("host=$host port=$port dbname=$dbname user=$user password=$password");

if (!$conn) {
    die("Database connection failed: " . pg_last_error());
}
?>
```

---

### Step 2: Push to GitHub (3 minutes)

**A. Initialize Git** (if not already done):
```bash
cd C:\xampp\htdocs\my_project
git init
git add .
git commit -m "Prepare for Railway deployment"
```

**B. Create GitHub Repository:**
1. Go to https://github.com
2. Click "New repository"
3. Name it: `my_project`
4. **Don't** initialize with README (your code already has files)
5. Click "Create repository"

**C. Push your code:**
```bash
git remote add origin https://github.com/YOUR_USERNAME/my_project.git
git branch -M main
git push -u origin main
```

---

### Step 3: Deploy to Railway (5 minutes)

**A. Sign up for Railway:**
1. Go to https://railway.app
2. Click "Start a New Project"
3. Sign up with GitHub (easiest)
4. Authorize Railway to access your repositories

**B. Create New Project:**
1. Click "New Project"
2. Select "Deploy from GitHub repo"
3. Choose your `my_project` repository
4. Railway will start deploying automatically!

**C. Add PostgreSQL Database:**
1. In your Railway project dashboard
2. Click "New" → "Database" → "Add PostgreSQL"
3. Railway automatically creates database and sets environment variables
4. **Done!** Your database is ready

**D. Configure PHP Settings:**
1. Click on your service (web app)
2. Go to "Settings" tab
3. Under "Root Directory", leave it as `/` (or set to `/` if different)
4. Under "Start Command", it should auto-detect PHP
5. Click "Deploy" if not already deploying

---

### Step 4: Get Your Public URL (1 minute)

**A. Generate Domain:**
1. In your Railway project
2. Click on your service (web app)
3. Go to "Settings" tab
4. Scroll to "Domains" section
5. Click "Generate Domain"
6. You'll get a URL like: `https://your-app.up.railway.app`

**B. Share with Your Team:**
```
Your App URL: https://your-app.up.railway.app
```

Send this URL to your 5 team members - they can access it immediately!

---

## 🎯 Alternative: Render.com (Also Free)

If Railway doesn't work, try Render.com:

### Deploy to Render.com:

**Step 1: Sign Up**
1. Go to https://render.com
2. Sign up with GitHub
3. Authorize Render

**Step 2: Create Web Service**
1. Click "New +" → "Web Service"
2. Connect your GitHub repository
3. Configure:
   - **Name**: `student-management`
   - **Environment**: `PHP`
   - **Build Command**: (leave empty)
   - **Start Command**: (leave empty)
   - Click "Create Web Service"

**Step 3: Add PostgreSQL**
1. Go to Dashboard → "New +" → "PostgreSQL"
2. Name it: `student-db`
3. Free tier is fine
4. Click "Create Database"

**Step 4: Link Database**
1. Go back to your Web Service
2. Click "Environment" tab
3. Add environment variables from PostgreSQL:
   - Copy connection details from PostgreSQL dashboard
   - Add: `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`

**Step 5: Get URL**
- Your app will be at: `https://student-management.onrender.com`

---

## 💾 Import Your Existing Database

After deployment, you need to import your data:

### Method 1: Using phpPgAdmin (Easiest)

**A. Export from Local:**
```bash
# In PowerShell:
pg_dump -U postgres -h localhost student_management > backup.sql
```

**B. Import to Railway:**
1. Get your Railway database connection string:
   - Railway Dashboard → PostgreSQL → "Connect"
   - Copy the connection URL
2. Use command:
```bash
psql "postgresql://USER:PASS@HOST:PORT/DATABASE" < backup.sql
```

### Method 2: Using Railway CLI (Recommended)

**A. Install Railway CLI:**
```bash
# In PowerShell:
npm install -g @railway/cli
# OR download from: https://docs.railway.app/develop/cli
```

**B. Login and Link:**
```bash
railway login
cd C:\xampp\htdocs\my_project
railway link
```

**C. Import Database:**
```bash
railway run psql < backup.sql
```

---

## 👥 Team Access Guide

### Share with Your Team:

**1. Send them the URL:**
```
App URL: https://your-app.up.railway.app
Username: [provide login credentials]
Password: [provide login credentials]
```

**2. Team Roles:**
- **Admin**: Full access (you)
- **Data Entry Team**: Login credentials for each person
- **Total**: 5 people can work simultaneously

**3. Create Accounts for Team:**
Update your database or create login page for each team member:
```sql
-- Create team member accounts
INSERT INTO teachers (name, email, password) VALUES
('Team Member 1', 'member1@email.com', 'hashed_password'),
('Team Member 2', 'member2@email.com', 'hashed_password'),
('Team Member 3', 'member3@email.com', 'hashed_password'),
('Team Member 4', 'member4@email.com', 'hashed_password'),
('Team Member 5', 'member5@email.com', 'hashed_password');
```

---

## 🔧 Troubleshooting

### Issue: "Application Error"
**Solution:** Check Railway logs:
1. Go to Railway Dashboard
2. Click on your service
3. Click "Deployments" tab
4. Click latest deployment
5. Check logs for errors

### Issue: Database Connection Failed
**Solution:** Verify environment variables:
1. Railway Dashboard → Your Service → "Variables"
2. Ensure all PGHOST, PGPORT, etc. are set
3. Redeploy if needed

### Issue: Page Not Loading
**Solution:** 
1. Check if PHP extensions are enabled
2. Verify `index.php` is in root directory
3. Check deployment logs

### Issue: Can't Access from Iraq
**Solution:**
- Railway works globally, including Iraq
- If blocked, try using a VPN temporarily
- Alternative: Use Render.com or Vercel

---

## 📊 Free Tier Limits

### Railway.app Free Tier:
- ✅ 500 hours/month (more than enough)
- ✅ 1GB RAM
- ✅ 1GB Storage
- ✅ PostgreSQL database included
- ✅ SSL certificate (HTTPS)
- ✅ Unlimited team members
- ✅ No credit card required

### Render.com Free Tier:
- ✅ 750 hours/month
- ✅ 512MB RAM
- ✅ 1GB PostgreSQL storage
- ✅ SSL included
- ✅ Auto-sleep after 15 min inactivity (wakes up when accessed)

---

## 🎉 Success Checklist

After deployment, verify:

- [ ] App loads at your Railway URL
- [ ] Database connection works
- [ ] Can login successfully
- [ ] Can add students/teachers/subjects
- [ ] Can enter marks
- [ ] Language switching works (EN/AR/KU)
- [ ] Responsive on mobile devices
- [ ] All 5 team members can access
- [ ] Data persists after page refresh

---

## 🚀 Next Steps After Deployment

1. **Share URL with team** - Send them the link and login credentials
2. **Assign roles** - Decide who enters what data (students, marks, etc.)
3. **Set up backup** - Download database backup weekly
4. **Monitor usage** - Check Railway dashboard for traffic
5. **Scale if needed** - Upgrade to paid plan if you exceed free tier

---

## 💡 Pro Tips

1. **Keep Local Copy**: Always keep your local XAMPP version as backup
2. **Regular Backups**: Export database weekly from Railway
3. **Team Coordination**: Use a shared document to track who's entering what data
4. **Test First**: Have all 5 members test access before starting data entry
5. **Monitor Limits**: Check Railway dashboard to ensure you don't exceed free tier

---

## 📞 Need Help?

If you encounter any issues during deployment:

1. **Railway Docs**: https://docs.railway.app
2. **Railway Discord**: https://discord.gg/railway
3. **Render Docs**: https://render.com/docs
4. **Community Support**: Check Railway community forum

---

## ⏱️ Estimated Total Time: 15 Minutes

- GitHub setup: 3 minutes
- Railway deployment: 5 minutes
- Database import: 5 minutes
- Testing: 2 minutes

---

**Ready to deploy? Start with Step 1 above! 🚀**

Your team will be able to access the app from anywhere in the world, including Iraq!
