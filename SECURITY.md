# Security Considerations for AviationWX.org

## ⚠️ Important: Sensitive Data

This project handles sensitive data that **MUST NOT** be committed to a public repository.

## Sensitive Data in Configuration

The `airports.json` file contains:

1. **API Keys**
   - Tempest Weather API keys
   - Ambient Weather API keys
   - These provide access to paid/proprietary weather data services

2. **Webcam Credentials**
   - Usernames and passwords for authenticated webcam feeds
   - Potentially allows access to private camera networks

## What to Do Before Making Public

### ✅ What's Safe to Commit (Public Repo)
- `airport-template.php`
- `index.php`
- `weather.php`
- `webcam.php`
- `fetch-webcam-safe.php`
- `styles.css`
- `homepage.php`
- `404.php`
- `airports.json.example` (template with placeholders)
- `CONFIGURATION.md`
- `README.md`
- `SECURITY.md`
- `.gitignore`
- `SETUP.md`
- `LOCAL_COMMANDS.md`
- All documentation files

### ❌ NEVER Commit (Private or Local Only)
- `airports.json` (contains real API keys and passwords)
- `cache/` directory (may contain cached sensitive data)
- Any file with actual API keys or passwords

### 🔧 Recommended Setup

1. **Local Development**
   ```bash
   # Copy the example file
   cp airports.json.example airports.json
   
   # Edit with your actual credentials
   # This file is in .gitignore and won't be committed
   ```

2. **Production (Bluehost)**
   - Upload `airports.json` directly to the server (not via Git)
   - Use SFTP or File Manager in cPanel
   - Set proper file permissions (600 or 640)
   - Never commit production credentials to any repository

3. **Separate Private Repo (Optional)**
   - If managing multiple environments, create a private repo
   - Store actual `airports.json` there
   - Update .gitignore to exclude it

## Security Best Practices

### For Contributors

1. **Never submit pull requests with real API keys or passwords**
2. **Use placeholder values** like `YOUR_API_KEY` in examples
3. **Review changes** before committing to ensure no secrets are included
4. **Rotate credentials** if accidentally exposed

### For Deployment

1. **File Permissions**
   ```bash
   chmod 600 airports.json  # Owner read/write only
   chmod 755 cache/          # Directory executable
   chmod 644 *.php           # Web files readable
   ```

2. **Server Security**
   - Keep PHP updated to latest stable version
   - Use HTTPS only (SSL certificate required)
   - Restrict `.json` file access via .htaccess if needed
   - Regularly rotate API keys and passwords

3. **API Key Management**
   - Generate new API keys specifically for this project
   - Use minimum required permissions
   - Monitor API usage for unusual activity
   - Have a plan to revoke/reissue keys if compromised

### Detection and Prevention

- **Pre-commit Hooks**: Consider adding a pre-commit hook that scans for API keys
- **Git Secrets**: Use tools like `git-secrets` to detect secrets in commits
- **Code Scanning**: Enable GitHub's secret scanning alerts
- **Regular Audits**: Periodically review commits for exposed credentials

## What's Already Protected

✅ `airports.json` is in `.gitignore` - will not be committed
✅ `cache/` directory is in `.gitignore` - contains only generated files
✅ API calls use HTTPS
✅ Credentials are not exposed in frontend JavaScript
✅ Server-side only: sensitive logic runs in PHP, not client-side

## If You Accidentally Committed Secrets

1. **Immediately rotate all exposed credentials**
2. **Remove the sensitive file** from the repository:
   ```bash
   git rm airports.json
   # Add to .gitignore (already done)
   git commit -m "Remove sensitive configuration"
   ```
3. **Clean repository history** if sensitive data was in previous commits:
   - Consider using `git filter-repo` or `BFG Repo-Cleaner`
   - Or create a fresh repository and migrate code only
4. **Monitor** for any unauthorized usage of exposed credentials
5. **Update** all affected services with new credentials

## Questions?

If you have questions about security best practices for this project, please:
1. Open a GitHub issue (without exposing credentials)
2. Contact the project maintainer privately
3. Review existing security documentation

