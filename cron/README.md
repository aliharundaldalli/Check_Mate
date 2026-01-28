# Attendance Session Status Update Cron Job

## Overview
This cron job automatically updates attendance session statuses based on current time and session schedules.

## Setup Instructions

### 1. Make the script executable
```bash
chmod +x update_session_status.php
```

### 2. Add to crontab
Open your crontab for editing:
```bash
crontab -e
```

Add the following line to run the script every minute:
```bash
* * * * * /usr/bin/php /Applications/XAMPP/xamppfiles/htdocs/ahdakade_checkmate/cron/update_session_status.php >> /Applications/XAMPP/xamppfiles/htdocs/ahdakade_checkmate/cron/logs/update_session_status.log 2>&1
```

### 3. Create logs directory
```bash
mkdir -p /Applications/XAMPP/xamppfiles/htdocs/ahdakade_checkmate/cron/logs
```

### 4. Verify cron is running
```bash
# Check if cron service is running
sudo launchctl list | grep cron

# View cron logs
tail -f /Applications/XAMPP/xamppfiles/htdocs/ahdakade_checkmate/cron/logs/update_session_status.log
```

## What the script does

1. **Updates expired sessions**: Changes status from 'active' or 'inactive' to 'expired' when the session time ends
2. **Activates future sessions**: Updates 'future' sessions to 'inactive' or 'active' based on teacher activation
3. **Cleans up old data**: Removes expired second phase keys older than 1 hour

## Testing

To test the script manually:
```bash
php /Applications/XAMPP/xamppfiles/htdocs/ahdakade_checkmate/cron/update_session_status.php
```

## Troubleshooting

- Check that PHP path is correct: `which php`
- Ensure database credentials are accessible from the cron environment
- Check file permissions
- Review error logs in the logs directory
