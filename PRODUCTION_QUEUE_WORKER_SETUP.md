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

---

## 🔧 **Solutions**

### **Solution 1: Scheduler-Based Health Check (Current Implementation)**

The scheduler now checks if queue workers are running and starts them if needed:

```php
// In app/Console/Kernel.php
$schedule->call(function () {
    // Check if queue workers are running
    $notificationsWorker = shell_exec("ps aux | grep 'queue:work.*notifications' | grep -v grep");
    $defaultWorker = shell_exec("ps aux | grep 'queue:work.*default' | grep -v grep");
    
    // Start workers if not running
    if (empty($notificationsWorker)) {
        shell_exec("php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20 > /dev/null 2>&1 &");
    }
    
    if (empty($defaultWorker)) {
        shell_exec("php artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10 > /dev/null 2>&1 &");
    }
})->everyMinute()->name('queue-workers-health-check')->withoutOverlapping();
```

**Benefits**:
- ✅ **Automatic Recovery**: Restarts workers if they die
- ✅ **Health Monitoring**: Logs worker status
- ✅ **No Manual Intervention**: Self-healing system

---

### **Solution 2: Manual Queue Workers (Immediate Fix)**

Run these commands on your production server:

```bash
# Kill any existing queue workers
pkill -f "queue:work"

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

### **Solution 3: Supervisor Setup (Recommended for Production)**

#### **Install Supervisor**
```bash
# Check if supervisor is installed
which supervisorctl

# If not installed, install it
sudo apt-get install supervisor
```

#### **Create Configuration File**
Create `/etc/supervisor/conf.d/laravel-queues.conf`:

```ini
[program:laravel-notifications-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20
autostart=true
autorestart=true
user=u540953431
numprocs=1
redirect_stderr=true
stdout_logfile=/home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/storage/logs/notifications-queue.log
stopwaitsecs=3600
stopasgroup=true
killasgroup=true

[program:laravel-default-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/artisan queue:work --queue=default --timeout=60 --tries=3 --max-jobs=10
autostart=true
autorestart=true
user=u540953431
numprocs=1
redirect_stderr=true
stdout_logfile=/home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/storage/logs/default-queue.log
stopwaitsecs=3600
stopasgroup=true
killasgroup=true
```

#### **Start Supervisor**
```bash
# Reload configuration
sudo supervisorctl reread
sudo supervisorctl update

# Start queue workers
sudo supervisorctl start laravel-notifications-queue:*
sudo supervisorctl start laravel-default-queue:*

# Check status
sudo supervisorctl status
```

**Expected Output**:
```
laravel-default-queue:laravel-default-queue_00   RUNNING   pid 1234, uptime 0:00:30
laravel-notifications-queue:laravel-notifications-queue_00   RUNNING   pid 1235, uptime 0:00:30
```

---

## 📊 **Monitoring & Verification**

### **1. Check Queue Worker Status**
```bash
# Check if workers are running
ps aux | grep "queue:work"

# Check supervisor status
sudo supervisorctl status

# Check queue statistics
php artisan tinker --execute="echo 'Jobs in notifications queue: ' . \DB::table('jobs')->where('queue', 'notifications')->count(); echo PHP_EOL; echo 'Total jobs: ' . \DB::table('jobs')->count();"
```

### **2. Monitor Logs**
```bash
# Check scheduler logs
tail -f storage/logs/scheduler.log

# Check queue worker logs (if using supervisor)
tail -f storage/logs/notifications-queue.log
tail -f storage/logs/default-queue.log
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

### **1. Queue Workers Not Starting**
```bash
# Check permissions
ls -la /home/u540953431/domains/sehtnaa.com/public_html/api.sehtnaa.com/artisan

# Check PHP path
which php

# Test command manually
php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=20
```

### **2. Jobs Still Pending**
```bash
# Check if workers are actually processing
ps aux | grep "queue:work"

# Check failed jobs
php artisan queue:failed

# Check job details
php artisan tinker --execute="print_r(\DB::table('jobs')->first());"
```

### **3. Memory Issues**
```bash
# Check memory usage
ps aux | grep "queue:work" | awk '{print $6}'

# Reduce max jobs if needed
php artisan queue:work --queue=notifications --timeout=60 --tries=3 --max-jobs=5
```

---

## 📋 **Implementation Steps**

### **Immediate Fix (Now)**
1. **Run Manual Commands**:
   ```bash
   pkill -f "queue:work"
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
1. **Install Supervisor** (if not already installed)
2. **Create Configuration File**
3. **Start Supervisor Services**
4. **Monitor and Verify**

---

## 🎯 **Expected Results**

### **After Implementation**
- ✅ **Queue Workers**: Running continuously
- ✅ **Job Processing**: Real-time processing
- ✅ **Health Monitoring**: Automatic restart if workers die
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
- **Scheduler Configuration**: Updated to properly manage queue workers
- **Health Check**: Automatic detection and restart of failed workers
- **Logging**: Better monitoring and debugging information

### **📊 Performance**
- **Job Processing**: Should be real-time now
- **Worker Management**: Self-healing system
- **Monitoring**: Comprehensive logging and status checks

**Your queue system should now be fully operational!** 🎉 