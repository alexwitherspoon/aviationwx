# GitHub Actions Setup

## Quick Start

1. **Add Secrets to Your Repository:**
   - Go to your GitHub repository
   - Click **Settings** → **Secrets and variables** → **Actions**
   - Click **New repository secret**

2. **Add These Secrets:**

   **Secret 1:** `BLUEHOST_FTP_HOST`
   - Value: Your FTP hostname (e.g., `aviationwx.org`)

   **Secret 2:** `BLUEHOST_FTP_USER`
   - Value: Your Bluehost FTP username

   **Secret 3:** `BLUEHOST_FTP_PASSWORD`
   - Value: Your Bluehost FTP password

3. **Push to GitHub:**
   ```bash
   git push origin main
   ```

The workflows will automatically:
- ✅ Test your code on push/PR
- ✅ Deploy to Bluehost when pushing to `main`

## Viewing Workflow Runs

1. Click **Actions** tab in your GitHub repository
2. See real-time logs of tests and deployments
3. Green checkmark = success, red X = failure

## Troubleshooting

### Tests are failing

- Check the **Actions** tab for error details
- Run tests locally: `php -l *.php`
- See `.github/workflows/test.yml` for test commands

### Deployment failed

- Verify FTP credentials are correct
- Check Bluehost file permissions
- See `.github/workflows/deploy.yml` for deployment details

### "No secrets found"

GitHub needs the secrets configured before deployment works.

