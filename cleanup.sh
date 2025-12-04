#!/bin/bash

echo "Cleaning up Docker containers and images..."

# Stop and remove all containers
docker-compose -f docker-compose.prod.yml down 2>/dev/null || true
docker stop $(docker ps -aq) 2>/dev/null || true
docker rm $(docker ps -aq) 2>/dev/null || true

# Remove all Docker images
docker rmi $(docker images -q) 2>/dev/null || true
docker system prune -a -f 2>/dev/null || true

# Remove Docker volumes
docker volume prune -f 2>/dev/null || true

echo "Removing Docker and Docker Compose..."
sudo apt remove -y docker.io docker-compose docker-ce docker-ce-cli containerd.io 2>/dev/null || true
sudo apt autoremove -y 2>/dev/null || true

echo "Cleaning up project files (keeping .env)..."
# Backup .env file
cp .env .env.backup 2>/dev/null || true

# Remove all files and directories except .env
find . -maxdepth 1 -not -name "." -not -name ".env" -not -name ".env.backup" -exec rm -rf {} + 2>/dev/null || true

# Restore .env if it was backed up
mv .env.backup .env 2>/dev/null || true

echo "Removing Redis if installed..."
sudo apt remove -y redis-server 2>/dev/null || true

echo "Cleanup complete! Only .env file remains."
