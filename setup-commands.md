# Database Connection Commands for SBA Reads

# 1. Connect to EC2 instance
ssh -i "sba-reads.pem" ubuntu@ec2-13-49-145-42.eu-north-1.compute.amazonaws.com

# 2. Once connected, install PostgreSQL client
sudo apt update
sudo apt install -y postgresql-client

# 3. Test database connectivity
nc -zv database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com 5432

# 4. Connect to database (replace password)
psql -h database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com -U postgres -d postgres

# 5. Create Laravel database
CREATE DATABASE sba_reads;

# 6. Show databases
\l

# 7. Exit database
\q

# 8. Clone the repository (if not done)
git clone https://github.com/SBAREADS-Organisation/SBA-Reads-Backend.git
cd SBA-Reads-Backend

# 9. Copy and edit environment file
cp .env.example .env
nano .env

# 10. Install Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sudo sh get-docker.sh
sudo usermod -aG docker ubuntu

# 11. Install Docker Compose
sudo curl -L "https://github.com/docker/compose/releases/latest/download/docker-compose-$(uname -s)-$(uname -m)" -o /usr/local/bin/docker-compose
sudo chmod +x /usr/local/bin/docker-compose

# 12. Build and run containers
docker-compose up -d --build

# 13. Run Laravel migrations
docker-compose exec app php artisan migrate --force

# 14. Create storage link
docker-compose exec app php artisan storage:link

# 15. Cache Laravel configs
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
docker-compose exec app php artisan view:cache
