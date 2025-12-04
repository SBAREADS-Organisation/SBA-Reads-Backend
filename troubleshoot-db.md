# Database Connection Troubleshooting

## Common Issues & Solutions

### 1. Security Group Issue (Most Common)
Your RDS security group might not allow connections from EC2.

**Fix:**
- Go to AWS Console → RDS → Database → database-1
- Click "VPC security groups"
- Edit inbound rules
- Add: PostgreSQL (3306) from EC2 security group

### 2. Network Connectivity Test
```bash
# Test if RDS is reachable from EC2
ssh -i "sba-reads.pem" ubuntu@ec2-13-49-145-42.eu-north-1.compute.amazonaws.com

# On EC2, test network connection
nc -zv database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com 5432
telnet database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com 5432
```

### 3. Check RDS Status
```bash
# Verify RDS is running and accessible
ssh -i "sba-reads.pem" ubuntu@ec2-13-49-145-42.eu-north-1.compute.amazonaws.com
ping database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com
```

### 4. Password/Authentication Issue
```bash
# Try with different user or check credentials
psql -h database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com -U postgres -d postgres
# Or try with 'admin' user if that's what you set
psql -h database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com -U admin -d postgres
```

### 5. VPC Configuration
Ensure EC2 and RDS are in the same VPC or have VPC peering.

### 6. Alternative: Use pgAdmin or DBeaver
Install GUI client to test connection from your local machine first.

## Quick Test Commands
```bash
# 1. Connect to EC2
ssh -i "sba-reads.pem" ubuntu@ec2-13-49-145-42.eu-north-1.compute.amazonaws.com

# 2. Install tools
sudo apt update && sudo apt install -y postgresql-client telnet netcat

# 3. Test connectivity
nc -zv database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com 5432

# 4. Try connection
psql -h database-1.cv82a8o6ebad.eu-north-1.rds.amazonaws.com -U postgres -d postgres
```

## What to Check
1. **Security Group**: RDS allows PostgreSQL from EC2
2. **VPC**: Same VPC or proper routing
3. **Credentials**: Correct username/password
4. **Network**: RDS endpoint is reachable
5. **Status**: RDS instance is "Available"
