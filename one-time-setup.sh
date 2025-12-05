#!/bin/bash

echo "Running one-time server setup..."

# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP 8.2 and extensions
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-pgsql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath php8.2-gd php8.2-redis php8.2-cli composer nginx

# Install Node.js
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# Clone repository
git clone https://${{ secrets.PAT }}@github.com/SBAREADS-Organisation/SBA-Reads-Backend.git /home/ubuntu/SBA-Reads-Backend

# Setup Nginx
sudo tee /etc/nginx/sites-available/sba-reads > /dev/null << 'EOF'
server {
    listen 80;
    server_name _;
    root /home/ubuntu/SBA-Reads-Backend/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

# Enable site
sudo ln -sf /etc/nginx/sites-available/sba-reads /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx

# Setup permissions
sudo chown -R ubuntu:ubuntu /home/ubuntu/SBA-Reads-Backend
sudo chmod -R 755 /home/ubuntu/SBA-Reads-Backend

echo "Setup complete! Now use the simple deploy workflow."
