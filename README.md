# SBA Reads Backend Documentation

## Overview

This repository contains comprehensive documentation for the SBA Reads Backend API, including API documentation, architecture overview, and a Postman collection for testing.

## Documentation Files

### 1. API_DOCUMENTATION.md
Comprehensive documentation covering all API endpoints, their request/response formats, authentication requirements, and error handling.

### 2. ARCHITECTURE_OVERVIEW.md
Detailed overview of the system architecture including:
- High-level architecture diagram
- Key components (controllers, services, repositories, models)
- Authentication and authorization approach
- Data flow through the system
- Payment processing workflow
- File storage strategy
- Security considerations
- Database design and relationships
- Scalability and deployment considerations

## Using the Postman Collection

1. Open Postman
2. Click "Import" in the top left corner
3. Select the `SBA_Reads_API.postman_collection.json` file
4. Create a new environment with the following variables:
   - `base_url`: The base URL of your API (e.g., https://api.example.com)
   - `auth_token`: Your authentication token (can be set after login)
5. Start testing the endpoints

## Environment Variables

The Postman collection uses the following environment variables:
- `{{base_url}}` - The base URL of your API
- `{{auth_token}}` - Authentication token for protected endpoints

## API Endpoints Covered

The documentation and Postman collection cover all major API endpoints including:

### Authentication
- User login, registration, password reset
- Email verification for authors

### User Management
- Profile management
- Settings and preferences
- KYC verification
- Payment methods
- Notifications

### Book Management
- Book listing, creation, and retrieval
- Reading progress tracking
- Bookmarks and reviews
- Book purchasing

### Order Management
- Order creation and tracking
- Order status updates

### Payment Processing
- Transaction management
- Payment verification

### Administrative Functions
- User management
- Book audit actions
- Subscription management
- Analytics

## Getting Started

1. Review the `ARCHITECTURE_OVERVIEW.md` to understand the system design
2. Use `API_DOCUMENTATION.md` as a reference for implementing client applications
3. Import `APIS-SBAREADS.postman_collection.json` into Postman to test endpoints
4. Set up your Postman environment with the required variables

## Support
