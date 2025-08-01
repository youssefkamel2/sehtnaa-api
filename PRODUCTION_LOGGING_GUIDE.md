# Production Logging Guide

## Overview
This guide explains the redesigned logging system for production deployment.

## New Logging Structure

### Log Files (No Daily Rotation)
- `laravel.log` - Main application logs
- `errors.log` - Error logs only
- `auth.log` - Authentication and security events
- `api.log` - API requests and responses
- `database.log` - Database operations
- `jobs.log` - Job processing and queues
- `notifications.log` - Notification and FCM logs
- `fcm_errors.log` - FCM errors only
- `firestore.log` - Firestore operations
- `providers.log` - Provider matching and operations
- `requests.log` - Request expansion and processing
- `scheduler.log` - Scheduler and cron jobs
- `debug.log` - Debug logs (development only)

## Environment Configuration

### Production (.env)
```env
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=single
LOG_LEVEL=info
LOG_DEPRECATIONS_CHANNEL=null
```

### Development (.env)
```env
APP_ENV=local
APP_DEBUG=true
LOG_CHANNEL=single
LOG_LEVEL=debug
LOG_DEPRECATIONS_CHANNEL=null
```

## LogService Usage

### Authentication Logs
```php
LogService::auth('info', 'User logged in', ['user_id' => 123]);
LogService::auth('warning', 'Failed login attempt', ['email' => 'user@example.com']);
```

### API Logs
```php
LogService::api('info', 'API request processed', ['endpoint' => '/api/users']);
```

### Error Logs (Production Safe)
```php
LogService::error('Database connection failed');
LogService::exception($exception, ['context' => 'user_creation']);
```

### FCM Logs
```php
LogService::notifications('info', 'FCM notification sent');
LogService::fcmErrors('FCM delivery failed');
```

## Production Safety Features

### 1. Sensitive Data Redaction
The LogService automatically redacts sensitive information:
- Passwords
- Tokens
- API keys
- Personal information (email, phone, address)
- IP addresses

### 2. Error Message Sanitization
In production, error messages are sanitized:
- Stack traces are hidden
- Sensitive file paths are masked
- Generic error messages are shown

### 3. Debug Logs Disabled
Debug logs are automatically disabled in production.

## Log Management Commands

### Clean Up Old Logs
```bash
# Clean logs older than 30 days
php artisan logs:cleanup --days=30

# Force cleanup without confirmation
php artisan logs:cleanup --days=30 --force

# Clean logs older than 7 days
php artisan logs:cleanup --days=7
```

### View Log Statistics
```bash
# Check log file sizes
ls -lh storage/logs/

# Monitor log growth
tail -f storage/logs/laravel.log
```

## Scheduled Tasks

The following tasks are automatically scheduled:

### Daily Tasks
- Activity Log pruning
- Export file cleanup
- Telescope pruning (if enabled)

### Weekly Tasks
- Log file cleanup (30 days retention)

### Hourly Tasks
- Failed job retry

### Every Minute
- Queue processing
- Request expansion processing

## Migration from Old System

### 1. Backup Current Logs
```bash
# Create backup of current logs
cp -r storage/logs storage/logs_backup_$(date +%Y%m%d)
```

### 2. Clear Old Log Files
```bash
# Remove old daily log files
rm storage/logs/*-2025-*.log
rm storage/logs/fcm_debug-*.log
rm storage/logs/fcm_errors-*.log
rm storage/logs/job_processing-*.log
```

### 3. Update Environment
```env
# Set production logging
APP_DEBUG=false
LOG_LEVEL=info
```

### 4. Clear Configuration Cache
```bash
php artisan config:clear
php artisan cache:clear
```

## Monitoring and Alerts

### Log File Monitoring
Monitor these key metrics:
- `errors.log` - Critical errors
- `auth.log` - Security events
- `fcm_errors.log` - Notification failures
- `jobs.log` - Queue processing issues

### Recommended Alerts
- Error log entries > 10 per hour
- Authentication failures > 5 per minute
- FCM error rate > 20%
- Queue job failures > 5 per hour

## Best Practices

### 1. Regular Maintenance
- Run log cleanup weekly
- Monitor log file sizes
- Archive old logs if needed

### 2. Security
- Never log sensitive data
- Use LogService for all logging
- Monitor auth logs for suspicious activity

### 3. Performance
- Keep log levels appropriate for environment
- Clean up old logs regularly
- Monitor log file growth

### 4. Troubleshooting
- Check `errors.log` for critical issues
- Use `debug.log` in development only
- Monitor queue processing in `jobs.log`

## Emergency Procedures

### If Logs Fill Up Disk
```bash
# Emergency log cleanup
php artisan logs:cleanup --days=1 --force

# Or manually truncate
> storage/logs/laravel.log
> storage/logs/errors.log
```

### If Debug Mode is Accidentally Enabled
```bash
# Disable debug mode
php artisan config:clear
# Set APP_DEBUG=false in .env
```

## Support

For logging issues:
1. Check `errors.log` for system errors
2. Review `scheduler.log` for cron job issues
3. Monitor `jobs.log` for queue problems
4. Contact system administrator for critical issues 