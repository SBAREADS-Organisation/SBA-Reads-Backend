#!/bin/bash

# Database Connection Test Script
DB_HOST="database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com"
DB_PORT="5432"
DB_NAME="postgres"  # Default PostgreSQL database
DB_USER="postgres"  # Default user, change if different

echo "Testing database connection to: $DB_HOST"
echo "=================================="

# Test 1: Check if database is reachable
echo "1. Testing network connectivity..."
nc -zv $DB_HOST $DB_PORT 2>&1 || echo "Network test failed"

# Test 2: Install PostgreSQL client if not present
echo "2. Installing PostgreSQL client..."
sudo apt update && sudo apt install -y postgresql-client

# Test 3: Try to connect (will prompt for password)
echo "3. Attempting database connection..."
echo "Enter database password when prompted:"
psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME

# Test 4: Show connection info if successful
echo "4. Connection successful! Showing database info..."
psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -c "\l" 2>/dev/null || echo "Connection failed - check credentials"
