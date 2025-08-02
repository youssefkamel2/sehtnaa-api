# Production Queue Worker Setup Guide

## 🚨 **Current Issue: Queue Workers Not Running**

### **Problem Analysis**
- **Symptom**: Jobs stuck in "pending" status
- **Root Cause**: Queue workers are not running persistently
- **Evidence**: 
  - `schedule:list` shows tasks are scheduled
  - But queue workers are not staying running
  - Jobs accumulate in the queue without being processed

### **Why This Happens**
1. **Scheduler vs Queue Workers**: Scheduler runs tasks periodically, but queue workers need to run continuously
2. **Command Execution**: `queue:work` is a long-running command that should stay active
3. **Process Management**: Queue workers need to be managed as background processes
4. **Shared Hosting Restrictions**: `shell_exec` is often disabled on shared hosting

---

## 🔧 **Solutions**

### **Solution 1: Database-Based Health Check (Current Implementation)**

The scheduler now monitors queue health without using shell commands:

```php
// In app/Console/Kernel.php
$schedule->call(function () {
    // Check if there are any jobs stuck in the queue for too long
    $stuckJobs = \DB::table('jobs')
        ->where('created_at', '<', now()->subMinutes(5))
        ->count();

    if ($stuckJobs > 0) {
        LogService::scheduler('warning', 'Jobs stuck in queue detected', [
            'stuck_jobs_count' => $stuckJobs
        ]);
    }
    
    // Log health check completion
    $totalJobs = \DB::table('jobs')->count();
    $failedJobs = \DB::table('failed_jobs')->count();
    
    LogService::scheduler('info', 'Queue workers health check completed', [
        'total_jobs' => $totalJobs,
        'failed_jobs' => $failedJobs,
        'stuck_jobs' => $stuckJobs
    ]);
})->everyMinute()->name('queue-workers-health-check')->withoutOverlapping();
```

**Benefits**:
- ✅ **No Shell Commands**: Works on shared hosting
- ✅ **Database Monitoring**: Tracks stuck jobs
- ✅ **Health Logging**: Detailed monitoring information
- ✅ **Shared Hosting Compatible**: No system-level commands

---

### **Solution 2: Manual Queue Workers (Immediate Fix)**

Run these commands on your production server:

```bash
# Kill any existing queue workers (if possible)
pkill -f "queue:work" 2>/dev/null || true

# Start notifications queue worker
nohup php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20 > /dev/null 2>&1 &

# Start default queue worker
nohup php artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10 > /dev/null 2>&1 &

# Check if they're running
ps aux | grep "queue:work"
```

**Expected Output**:
```
u540953431  1234  0.0  0.1  12345  6789 ?  S  14:30  0:00 php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20
u540953431  1235  0.0  0.1  12345  6789 ?  S  14:30  0:00 php artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10
```

---

### **Solution 3: Custom Artisan Command**

Use the new custom command to start workers:

```bash
# Start queue workers using custom command
php artisan queue:start-workers

# Or run in daemon mode
php artisan queue:start-workers --daemon
```

---

### **Solution 4: Cron-Based Queue Workers (Recommended for Shared Hosting)**

Create a cron job that starts queue workers every few minutes:

```bash
# Add to your crontab
* * * * * cd /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com && php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20 --stop-when-empty > /dev/null 2>&1
* * * * * cd /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com && php artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10 --stop-when-empty > /dev/null 2>&1
```

**Benefits**:
- ✅ **Shared Hosting Compatible**: No supervisor needed
- ✅ **Automatic Processing**: Jobs processed every minute
- ✅ **Resource Efficient**: Workers stop when no jobs
- ✅ **Reliable**: Cron ensures workers run regularly

---

## 📊 **Monitoring & Verification**

### **1. Check Queue Worker Status**
```bash
# Check if workers are running
ps aux | grep "queue:work"

# Check queue statistics
php artisan tinker --execute="echo 'Jobs in notifications queue: ' . \DB::table('jobs')->where('queue', 'notifications')->count(); echo PHP_EOL; echo 'Total jobs: ' . \DB::table('jobs')->count();"
```

### **2. Monitor Logs**
```bash
# Check scheduler logs
tail -f storage/logs/scheduler.log

# Check Laravel logs for errors
tail -f storage/logs/laravel.log
```

### **3. Test Queue Processing**
```bash
# Create a test job
php artisan tinker --execute="dispatch(new \App\Jobs\SendNotificationCampaign('test_campaign', 1, 'Test', 'Test notification'));"

# Check if it gets processed
php artisan tinker --execute="echo 'Jobs in queue: ' . \DB::table('jobs')->count();"
```

---

## 🔍 **Troubleshooting**

### **1. Shell Commands Disabled**
**Error**: `Call to undefined function shell_exec()`

**Solution**: Use database-based monitoring instead of shell commands.

### **2. Queue Workers Not Starting**
```bash
# Check permissions
ls -la /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/artisan

# Check PHP path
which php

# Test command manually
php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20
```

### **3. Jobs Still Pending**
```bash
# Check if workers are actually processing
ps aux | grep "queue:work"

# Check failed jobs
php artisan queue:failed

# Check job details
php artisan tinker --execute="print_r(\DB::table('jobs')->first());"
```

---

## 📋 **Implementation Steps**

### **Immediate Fix (Now)**
1. **Run Manual Commands**:
   ```bash
   pkill -f "queue:work" 2>/dev/null || true
   nohup php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20 > /dev/null 2>&1 &
   nohup php artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10 > /dev/null 2>&1 &
   ```

2. **Verify Workers Are Running**:
   ```bash
   ps aux | grep "queue:work"
   ```

3. **Test Job Processing**:
   ```bash
   php artisan tinker --execute="echo 'Jobs in queue: ' . \DB::table('jobs')->count();"
   ```

### **Production Setup (Recommended)**
1. **Add Cron Jobs** for queue processing
2. **Monitor Scheduler Logs** for health checks
3. **Set up Alerts** for stuck jobs
4. **Regular Maintenance** of failed jobs

---

## 🎯 **Expected Results**

### **After Implementation**
- ✅ **Queue Workers**: Running continuously (via cron or manual)
- ✅ **Job Processing**: Real-time processing
- ✅ **Health Monitoring**: Database-based monitoring
- ✅ **Logging**: Detailed logs for monitoring
- ✅ **Reliability**: Stable queue processing

### **Monitoring Points**
- **Scheduler Logs**: Should show health check messages every minute
- **Queue Statistics**: Jobs should be processed quickly
- **Worker Processes**: Should show running queue workers
- **Failed Jobs**: Should be minimal or zero

---

## 🚀 **Current Status**

### **✅ Fixed Issues**
- **Shell Command Error**: Replaced with database-based monitoring
- **Scheduler Configuration**: Updated to work on shared hosting
- **Health Check**: Automatic detection of stuck jobs
- **Logging**: Better monitoring and debugging information

### **📊 Performance**
- **Job Processing**: Should be real-time now
- **Worker Management**: Cron-based or manual management
- **Monitoring**: Comprehensive logging and status checks
- **Shared Hosting Compatible**: No system-level dependencies

**Your queue system should now work on shared hosting!** 🎉 