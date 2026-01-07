# CI/CD Setup Guide for SBA Reads

## Overview
This guide explains how to set up automated CI/CD pipelines for your Laravel application using GitHub Actions.

## Prerequisites

### 1. GitHub Repository Setup
- Ensure your code is pushed to a GitHub repository
- The repository should have `main` (production) and `develop`/`staging` branches

### 2. Server Requirements
- Ubuntu server with SSH access
- Nginx web server
- PHP 8.2 with FPM
- MySQL/PostgreSQL database
- Composer and Node.js installed
- Git installed on server

### 3. Required GitHub Secrets
Go to your GitHub repository → Settings → Secrets and variables → Actions and add:

#### Production Secrets:
- `EC2_HOST`: Your production server IP address
- `SSH_KEY`: Your private SSH key (use `ssh-keygen` to generate)

#### Optional Staging Secrets:
- `STAGING_HOST`: Your staging server IP address (if different)

## Setup Steps

### 1. Generate SSH Key Pair
```bash
# On your local machine
ssh-keygen -t rsa -b 4096 -C "github-actions"

# This creates:
# ~/.ssh/id_rsa (private key) - Add to GitHub secrets
# ~/.ssh/id_rsa.pub (public key) - Add to server
```

### 2. Add Public Key to Server
```bash
# On your server
sudo -u ubuntu mkdir -p /home/ubuntu/.ssh
echo "YOUR_PUBLIC_KEY_CONTENT" >> /home/ubuntu/.ssh/authorized_keys
chmod 600 /home/ubuntu/.ssh/authorized_keys
chmod 700 /home/ubuntu/.ssh
chown -R ubuntu:ubuntu /home/ubuntu/.ssh
```

### 3. Initialize Git Repository on Server
```bash
# On your server in /var/www/html
cd /var/www/html
git init
git remote add origin https://github.com/your-username/sba-reads.git
git pull origin main
```

### 4. Set Up Proper Permissions
```bash
# On your server
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
sudo chmod -R 775 /var/www/html/storage
sudo chmod -R 775 /var/www/html/bootstrap/cache
```

## Workflow Files

### Production Deployment (`.github/workflows/deploy-simple.yml`)
- Triggers on push to `main` branch
- Creates automatic backups before deployment
- Runs migrations and optimizations
- Sets proper web server permissions
- Restarts Nginx and PHP-FPM

### Staging Deployment (`.github/workflows/deploy-staging.yml`)
- Triggers on push to `develop` or `staging` branches
- Runs tests before deployment
- Deploys to staging environment
- Uses separate staging database

## Deployment Process

### Automatic Deployment
1. Push code to `main` branch → Triggers production deployment
2. Push code to `develop`/`staging` → Triggers staging deployment with tests

### Manual Deployment
- Go to GitHub repository → Actions tab
- Select the workflow and click "Run workflow"

## Features

### Production Workflow
- ✅ Automatic backups before deployment
- ✅ Zero-downtime deployment
- ✅ Laravel optimizations (config/route/view caching)
- ✅ Database migrations with safety
- ✅ Proper file permissions
- ✅ Service restart management

### Staging Workflow
- ✅ Automated testing
- ✅ Isolated staging environment
- ✅ Database migrations
- ✅ Configuration caching

## Monitoring and Rollback

### View Deployment Status
- GitHub Actions tab shows real-time deployment status
- Email notifications on success/failure

### Manual Rollback
```bash
# On server, if deployment fails
sudo mv /var/www/html_backup_TIMESTAMP /var/www/html
sudo systemctl reload nginx
sudo systemctl restart php8.2-fpm
```

## Troubleshooting

### Common Issues
1. **Permission Denied**: Check SSH key setup and user permissions
2. **Migration Failures**: Verify database connection and migration files
3. **Build Failures**: Check Node.js and PHP versions
4. **Service Restart Issues**: Verify Nginx and PHP-FPM configuration

### Debug Commands
```bash
# Check deployment logs in GitHub Actions
# Check server logs
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/php8.2-fpm.log

# Verify application status
cd /var/www/html
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## Security Considerations

- SSH keys should be read-only and secure
- Environment variables should not be committed to git
- Regular backup verification
- Monitor deployment logs for suspicious activity
- Use HTTPS for all communications

## Best Practices

1. **Test before deploy**: Always use staging environment first
2. **Backup strategy**: Regular automated backups
3. **Monitor deployments**: Set up alerts for failures
4. **Version control**: Tag releases for easy rollback
5. **Documentation**: Keep deployment procedures documented

## Support

For issues with:
- GitHub Actions: Check GitHub documentation
- Server configuration: Consult Ubuntu/Laravel docs
- Application errors: Check Laravel logs in `storage/logs/laravel.log`
