# GitHub Secrets Setup Guide

## Required Repository Secrets

Add these to your GitHub repository settings → Secrets and variables → Actions:

### Infrastructure Secrets
```
EC2_HOST=ec2-13-49-145-42.eu-north-1.compute.amazonaws.com
SSH_KEY=-----BEGIN OPENSSH PRIVATE KEY-----
[your private key content from sba-reads.pem]
-----END OPENSSH PRIVATE KEY-----
```

### Database Secrets
```
DB_HOST=database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com
DB_DATABASE=sba_reads
DB_USERNAME=postgres
DB_PASSWORD=your_rds_password_here
```

### Application Secrets
```
APP_URL=https://your-domain.com
STRIPE_KEY=sk_live_your_stripe_key
STRIPE_SECRET=sk_live_your_stripe_secret
PAYSTACK_KEY=live_your_paystack_key
PAYSTACK_SECRET=sk_live_your_paystack_secret
CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_API_KEY=your_api_key
CLOUDINARY_API_SECRET=your_api_secret
```

## Setup Steps

### 1. Get SSH Key Content
```bash
# On your local machine
cat sba-reads.pem
```
Copy the entire content and add as SSH_KEY secret.

### 2. Add Secrets to GitHub
1. Go to your GitHub repository
2. Settings → Secrets and variables → Actions
3. Click "New repository secret"
4. Add each secret from the list above

### 3. Initial Server Setup
Run these commands on your EC2:

```bash
# Clone repository
git clone https://github.com/SBAREADS-Organisation/SBA-Reads-Backend.git
cd SBA-Reads-Backend

# Create environment file
cp .env.example .env
nano .env  # Edit with production values

# Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker ubuntu

# Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# Create SSL directory
mkdir -p ssl

# Initial deployment
docker-compose -f docker-compose.prod.yml up -d --build
```

### 4. SSL Certificate Setup
```bash
# Install Certbot
sudo apt update
sudo apt install -y certbot python3-certbot-nginx

# Get SSL certificate (replace with your domain)
sudo certbot certonly --standalone -d your-domain.com

# Copy certificates to project
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem ./ssl/cert.pem
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem ./ssl/key.pem
sudo chown ubuntu:ubuntu ./ssl/*
```

### 5. Test Deployment
Push to main branch to trigger GitHub Actions deployment.

## Monitoring Deployment

Check deployment status:
```bash
# On EC2
docker-compose -f docker-compose.prod.yml ps
docker-compose -f docker-compose.prod.yml logs -f app
```

## Troubleshooting

If deployment fails:
1. Check GitHub Actions logs
2. Verify all secrets are correctly set
3. Ensure EC2 security group allows HTTP/HTTPS
4. Check database connectivity
5. Verify Docker containers are running
