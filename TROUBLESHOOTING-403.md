# Troubleshooting 403 Forbidden Errors

## Quick Diagnostic Steps

### Step 1: Test with Diagnostic Tool
1. Upload all files to your server
2. Visit: `https://reset2humanity.com/sprint-estimator-php/test-upload.html`
3. Click "Check Server Config" to see server settings
4. Try uploading a small CSV file using "Test Upload"
5. Review the diagnostic output for clues

### Step 2: Check Common Causes

#### A. ModSecurity Blocking (Most Common)
**Symptoms:**
- 403 Forbidden error on POST requests
- GET requests work fine
- File uploads specifically fail

**Solutions:**
1. ✅ **Already implemented** in `.htaccess` and `api/.htaccess`
2. If still blocked, contact your hosting provider to whitelist:
   - `/api/import.php`
   - `/api/test-upload.php`

**For cPanel Users:**
```bash
# Navigate to Security → ModSecurity
# Add exception for: /sprint-estimator-php/api/
```

#### B. File Upload Disabled
**Check:**
```php
// Visit test-upload.html and check if:
file_uploads_enabled: "No"
```

**Solution:**
Contact hosting provider to enable `file_uploads = On` in php.ini

#### C. Wrong File Permissions
**Check on server:**
```bash
cd /path/to/sprint-estimator-php
ls -la api/
```

**Expected permissions:**
- Directories: `755` (drwxr-xr-x)
- PHP files: `644` (-rw-r--r--)

**Fix:**
```bash
chmod 755 api/
chmod 644 api/*.php
```

#### D. Web Application Firewall (WAF)
**Symptoms:**
- Only happens on production server
- Works locally or on other servers
- Specific to file uploads

**Solutions:**
1. Check hosting control panel for WAF settings
2. Add `api/import.php` to WAF whitelist
3. Contact hosting support to disable WAF for upload endpoints

#### E. Apache mod_security2 Rules
**Check server logs:**
```bash
tail -f /var/log/apache2/error.log
# or
tail -f /var/log/httpd/error_log
```

**Look for:**
```
ModSecurity: Access denied with code 403
```

**Solution:**
Ask hosting provider to add these rules to their ModSecurity config:
```apache
SecRuleRemoveById 950907
SecRuleRemoveById 960010
SecRule REQUEST_URI "@streq /sprint-estimator-php/api/import.php" "id:1,phase:1,pass,nolog,ctl:ruleEngine=Off"
```

#### F. Hosting Provider Restrictions
Some shared hosting providers block file uploads by default.

**Providers known to block uploads:**
- Some Hostinger plans
- Some GoDaddy shared hosting
- Some Bluehost basic plans

**Solution:**
Contact support and request:
> "Please enable file uploads for my PHP application at /sprint-estimator-php/api/import.php. I need to upload CSV files for data import functionality."

### Step 3: Server-Side Debugging

#### Enable PHP Error Logging
Add to `api/import.php` temporarily (line 4):
```php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);
```

#### Check PHP Error Log
```bash
# Find PHP error log location
php -i | grep error_log

# View recent errors
tail -f /path/to/php_error.log
```

### Step 4: Alternative Solutions

#### Option A: Use Different Upload Method
If ModSecurity can't be disabled, try:
1. Base64 encoding the file content
2. Sending as JSON instead of FormData
3. Using chunked uploads

#### Option B: Request Hosting Support
**Email template:**
```
Subject: Enable File Uploads for Application

Hello,

I'm experiencing 403 Forbidden errors when trying to upload CSV files to:
https://reset2humanity.com/sprint-estimator-php/api/import.php

The application needs to upload CSV files (max 50MB) for data import.

Could you please:
1. Verify file_uploads is enabled in php.ini
2. Whitelist /sprint-estimator-php/api/ in ModSecurity
3. Check if WAF is blocking POST requests to this endpoint
4. Confirm upload_max_filesize is at least 50M

Thank you!
```

#### Option C: Move to VPS/Cloud Hosting
If shared hosting restrictions can't be resolved:
- **DigitalOcean** - $6/month droplet
- **AWS Lightsail** - $3.50/month
- **Linode** - $5/month
- **Vultr** - $5/month

Full control over server configuration.

## Diagnostic Output Interpretation

### If you see this in test-upload.html:
```json
{
  "file_uploads_enabled": "No"
}
```
➡️ **Solution:** Contact hosting to enable file uploads

### If you see:
```json
{
  "success": false,
  "message": "No file received in $_FILES"
}
```
➡️ **Problem:** File not reaching PHP
➡️ **Cause:** ModSecurity or WAF blocking
➡️ **Solution:** Whitelist the endpoint

### If you see HTTP 403 before reaching PHP:
➡️ **Problem:** Server-level blocking
➡️ **Cause:** Apache/Nginx rules or ModSecurity
➡️ **Solution:** Contact hosting support

### If test-upload.html works but import.php doesn't:
➡️ **Problem:** Specific to import.php code or file size
➡️ **Solution:** Check CSV file size and format

## Files to Share with Hosting Support

If contacting support, provide:
1. URL: `https://reset2humanity.com/sprint-estimator-php/test-upload.html`
2. This file: `TROUBLESHOOTING-403.md`
3. Screenshot of browser console showing 403 error
4. Output from test-upload.html diagnostic

## Contact Information

If all else fails, you may need to:
1. Switch to a different hosting plan
2. Move to VPS hosting
3. Use a third-party upload service (AWS S3, Cloudinary)
