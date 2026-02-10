# OTP System - Quick Reference

## Quick Start

1. **Run Database Migration:**
   ```sql
   source database/migrations/create_otp_tokens_table.sql
   ```

2. **Configure SMTP (Hostinger):**
   - Edit `config.php` or set environment variables
   - SMTP Host: `smtp.hostinger.com`
   - Port: `587` (TLS) or `465` (SSL)

3. **Test the System:**
   - Visit: `forgot-password-otp.php` (password reset)
   - Visit: `student-registration.html` (registration)

## File Structure

- `config.php` - Configuration settings
- `generate_otp.php` - Generate OTP codes
- `send_otp.php` - Send OTP via email
- `validate_otp.php` - Validate OTP input
- `rate_limit.php` - Rate limiting functions
- `verify-session.php` - Session management

## Usage Examples

### Generate and Send OTP
```php
require_once 'backend/security/otp/send_otp.php';
$result = sendOTPEmail('user@example.com', 'registration', null, 'User Name');
```

### Validate OTP
```php
require_once 'backend/security/otp/validate_otp.php';
$result = validateOTP('user@example.com', '123456', 'registration');
```

## Configuration

All settings in `config.php`:
- `OTP_EXPIRY_MINUTES` - OTP expiration time (default: 10)
- `OTP_MAX_ATTEMPTS` - Max validation attempts (default: 5)
- `OTP_RATE_LIMIT_COUNT` - Max requests per window (default: 3)
- `OTP_RATE_LIMIT_WINDOW` - Time window in seconds (default: 3600)

## Security Features

✅ Rate limiting (3 requests/hour)
✅ Attempt limiting (5 attempts/OTP)
✅ Auto-expiration (10 minutes)
✅ One-time use (OTP invalidated after use)
✅ CSRF protection
✅ Input sanitization
✅ SQL injection protection

## Support

See full documentation: `docs/02-guides/OTP_SYSTEM_SETUP.md`











