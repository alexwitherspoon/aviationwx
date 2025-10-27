# Deployment Guide for AviationWX.org

## GitHub Actions Setup

### Required Secrets

You need to add these secrets to your GitHub repository:

1. Go to your repository on GitHub
2. Click **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**

Add these three secrets:

| Secret Name | Description | Example Value |
|-------------|-------------|---------------|
| `BLUEHOST_FTP_HOST` | FTP hostname | `aviationwx.org` or `ip-address` |
| `BLUEHOST_FTP_USER` | FTP username | Your Bluehost FTP username |
| `BLUEHOST_FTP_PASSWORD` | FTP password | Your Bluehost FTP password |

### How to Get Your FTP Credentials

1. **Log into Bluehost cPanel**
2. Navigate to **FTP Accounts** or **File Manager**
3. Find your FTP hostname (usually your domain)
4. Create or use existing FTP user credentials
5. Note: FTP password is different from your cPanel password

### Workflows

The repository includes two GitHub Actions workflows:

#### 1. Test Workflow (`.github/workflows/test.yml`)

**Triggers:**
- Runs on every push to `main` or `develop`
- Runs on pull requests

**What it does:**
- ✅ Validates PHP syntax
- ✅ Validates JSON configuration
- ✅ Checks for accidental secrets in code
- ✅ Validates file structure

#### 2. Deploy Workflow (`.github/workflows/deploy.yml`)

**Triggers:**
- Runs on every push to `main` (production)
- Skips if only markdown files changed

**What it does:**
- ✅ Runs tests first
- ✅ Creates deployment package
- ✅ Deploys to Bluehost via FTP
- ✅ Verifies deployment

## Pre-Deployment Checklist

Before deploying to Bluehost:

- [ ] All secrets configured in GitHub
- [ ] `airports.json` uploaded manually to Bluehost (never in Git)
- [ ] DNS configured with wildcard subdomain `*.aviationwx.org`
- [ ] SSL certificate installed on Bluehost
- [ ] Test locally with `php -S localhost:8000`

## Manual Deployment Steps

If you prefer to deploy manually:

### 1. Upload Files

Using File Manager or FTP client:

**Upload:**
- `index.php`
- `airport-template.php`
- `weather.php`
- `webcam.php`
- `fetch-webcam-safe.php`
- `homepage.php`
- `404.php`
- `styles.css`
- `.htaccess`
- All documentation files

**Create directories:**
- `cache/` (with write permissions: 755)
- `cache/webcams/` (with write permissions: 755)

### 2. Create `airports.json`

On Bluehost, create `airports.json` in your root directory (not via Git):
- Copy `airports.json.example` to `airports.json`
- Add your actual API keys and credentials
- Set file permissions to 600 or 640

### 3. Set Up Cron Jobs

In Bluehost cPanel → Cron Jobs, add:

```bash
*/1 * * * * curl -s https://aviationwx.org/fetch-webcam-safe.php > /dev/null 2>&1
```

This refreshes webcam images every minute.

### 4. Configure Subdomain Routing

Ensure `.htaccess` is in your root directory with the routing rules.

Test it works:
- Visit `https://aviationwx.org/` → Should show homepage
- Visit `https://kspb.aviationwx.org/` → Should show KSPB airport

## Post-Deployment Verification

After deployment:

1. **Check homepage:** `https://aviationwx.org/`
2. **Check airport page:** `https://kspb.aviationwx.org/`
3. **Check weather API:** `https://aviationwx.org/weather.php?airport=kspb`
4. **Check webcam API:** `https://aviationwx.org/webcam.php?id=kspb&cam=0`

All should return expected content.

## Troubleshooting

### Issue: 404 on subdomain

**Solution:** 
- Verify DNS wildcard `*.aviationwx.org` is set up
- Wait 24-48 hours for DNS propagation
- Check `.htaccess` is uploaded

### Issue: Weather data not loading

**Solution:**
- Check `airports.json` exists on server
- Verify API keys are correct
- Check PHP error logs in cPanel

### Issue: Webcam images not updating

**Solution:**
- Set up the cron job
- Check `cache/` directory has write permissions (755)
- Run `fetch-webcam-safe.php` manually to test

### Issue: Syntax errors

**Solution:**
- Test locally first
- Check PHP version on Bluehost (needs PHP 7.4+)
- Verify all files uploaded completely

## Bluehost-Specific Notes

### PHP Version
- Ensure PHP 7.4 or higher is selected in cPanel
- Bluehost usually defaults to PHP 8.0+

### File Permissions
```bash
php files: 644
directories: 755
airports.json: 600 (secure)
cache/: 755
cache/webcams/: 755
```

### Recommended Settings
- **PHP Version:** 8.0 or higher
- **Error Reporting:** Disabled for production
- **Display Errors:** Off
- **Memory Limit:** 256M or higher

## Security Reminders

⚠️ **Before pushing to GitHub:**
- Never commit `airports.json` (it's in `.gitignore`)
- Verify no API keys are in code
- Test locally to ensure no secrets in files

✅ **On Bluehost:**
- Set `airports.json` permissions to 600
- Use strong FTP passwords
- Keep PHP updated
- Enable HTTPS only

## Need Help?

- Check deployment logs in GitHub Actions
- Review Bluehost error logs in cPanel
- Test locally with `php -S localhost:8000` first
- See SETUP.md for local development guide

