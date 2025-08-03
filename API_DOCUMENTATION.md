# SBA Reads Backend API Documentation

## Overview

This document provides comprehensive documentation for the SBA Reads Backend API, a Laravel-based platform for managing digital books, user accounts, orders, and payments. The API follows RESTful principles and uses JSON for data exchange.

## Base URL

```
https://your-domain.com/api
```

## Authentication

Most endpoints require authentication using Laravel Sanctum tokens. Include the token in the Authorization header:

```
Authorization: Bearer <your-token>
```

## Rate Limiting

API requests are rate-limited to prevent abuse. The default limit is 60 requests per minute per IP address.

## Error Responses

All error responses follow a consistent format:

```json
{
  "data": null,
  "code": 400,
  "message": "Error message",
  "error": {
    "field": ["Error details"]
  }
}
```

## API Endpoints

### Authentication

#### POST /auth/login
Login a user

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "data": {
    "user": {
      "id": 1,
      "email": "user@example.com",
      "name": "John Doe",
      "account_type": "reader"
    },
    "token": "auth_token"
  },
  "code": 200,
  "message": "Login successful"
}
```

#### POST /auth/forgot-password
Request password reset

**Request Body:**
```json
{
  "email": "user@example.com"
}
```

#### POST /auth/reset-password
Reset password with token

**Request Body:**
```json
{
  "email": "user@example.com",
  "token": "reset_token",
  "password": "newpassword123",
  "password_confirmation": "newpassword123"
}
```

#### POST /auth/verify-reset-password-otp
Verify OTP for password reset

**Request Body:**
```json
{
  "email": "user@example.com",
  "token": "otp_token"
}
```

### User Management

#### POST /user/register
Register a new user

**Request Body:**
```json
{
  "email": "user@example.com",
  "password": "Password123!",
  "account_type": "reader" // or "author"
}
```

#### POST /user/verify-email
Verify author email with token

**Request Body:**
```json
{
  "email": "author@example.com",
  "token": "verification_token"
}
```

#### POST /user/resend-email-token
Resend verification token

**Request Body:**
```json
{
  "email": "author@example.com"
}
```

#### GET /user/profile
Get current user profile

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "user@example.com",
    "account_type": "reader",
    "profile_info": {
      "username": "johndoe",
      "bio": "A book lover",
      "pronouns": "he/him"
    },
    "settings": {
      "theme": "light",
      "notifications": {
        "email": true,
        "sms": false
      }
    },
    "preferences": {
      "interests": ["fiction", "mystery"],
      "sort_by": "popularity"
    }
  },
  "code": 200,
  "message": "Profile retrieved successfully!"
}
```

#### POST /user/profile
Update user profile

**Request Body:**
```json
{
  "name": "John Updated",
  "profile_info": {
    "username": "johnupdated",
    "bio": "An updated book lover",
    "pronouns": "he/him"
  }
}
```

#### PATCH /user/profile/preference
Update user preferences

**Request Body:**
```json
{
  "interests": ["fiction", "mystery", "sci-fi"],
  "sort_by": "recent"
}
```

#### PATCH /user/profile/settings
Update user settings

**Request Body:**
```json
{
  "theme": "dark",
  "notifications": {
    "email": true,
    "sms": true
  }
}
```

#### POST /user/profile/change-password
Change user password

**Request Body:**
```json
{
  "current_password": "oldpassword",
  "new_password": "newpassword123",
  "confirm_new_password": "newpassword123"
}
```

#### POST /user/token/refresh
Refresh authentication token

#### POST /user/logout
Logout current user

#### GET /user/all (Admin Only)
Get all users with filtering options

**Query Parameters:**
- `search`: Search by email, name, or username
- `account_type`: Filter by account type (reader, author, superadmin)
- `status`: Filter by status (active, inactive, etc.)
- `role`: Filter by role
- `sort_by`: Sort field
- `sort_order`: Sort order (asc, desc)
- `per_page`: Items per page
- `page`: Page number

#### GET /user/{user_id}
Get specific user details

#### POST /user/profile/action/{action}/{user_id} (Admin Only)
Update user status (active, suspended, verified, etc.)

### User Address Management

#### POST /user/address
Create new address

**Request Body:**
```json
{
  "street": "123 Main St",
  "city": "Anytown",
  "state": "CA",
  "zip_code": "12345",
  "country": "USA",
  "is_default": true
}
```

#### GET /user/address/all
Get all user addresses

### User Subscription Management

#### GET /user/subscriptions/history
Get subscription history

#### POST /user/subscriptions/subscribe
Subscribe to a plan

**Request Body:**
```json
{
  "subscription_id": 1
}
```

### User KYC Management

#### POST /user/kyc/initiate
Initiate KYC process

#### POST /user/kyc/upload-document
Upload KYC document

#### GET /user/kyc/status
Get KYC status

### User Payment Method Management

#### GET /user/payment_method/list
List payment methods

#### POST /user/payment_method/delete
Delete payment method

#### POST /user/payment_method/add-card
Add card payment method

#### POST /user/payment_method/add-bank-account
Add bank account (authors only)

### User Notification Management

#### GET /user/notifications
Get user notifications

#### POST /user/notifications/{notification}/mark-as-read
Mark notification as read

#### POST /user/notifications/mark-all-as-read
Mark all notifications as read

### Book Management

#### GET /books
Get all books with filtering and search

**Query Parameters:**
- `search`: Search term
- `interests`: Filter by category interests
- `classification`: Filter by classification (new_arrivals, trending, top_picks)
- `sort_by`: Sort field (title, publication_date, etc.)
- `sort_dir`: Sort direction (asc, desc)
- `items_per_page`: Items per page

#### POST /books
Create new book (authenticated users)

**Request Body:**
```json
{
  "books": [
    {
      "title": "Book Title",
      "sub_title": "Book Subtitle",
      "description": "Book description",
      "author_id": 1,
      "authors": [1, 2],
      "isbn": "978-3-16-148410-0",
      "table_of_contents": "{}",
      "categories": [1, 2],
      "publication_date": "2023-01-01",
      "language": ["en"],
      "cover_image": "file",
      "files": ["file1", "file2"],
      "pricing": {
        "actual_price": 19.99,
        "discounted_price": 14.99
      },
      "meta_data": {
        "pages": 300
      }
    }
  ]
}
```

#### GET /books/{id}
Get specific book details

#### PUT /books/{id}
Update book details

#### POST /books/{id}/start-reading
Start or update reading progress

**Request Body:**
```json
{
  "page": "50"
}
```

#### GET /books/user/reading-progress
Get user's reading progress

#### POST /books/{id}/reviews
Post a review for a book

**Request Body:**
```json
{
  "rating": 5,
  "comment": "Great book!"
}
```

#### GET /books/bookmarks/all
Get all bookmarked books

#### POST /books/{id}/bookmark
Bookmark a book

#### DELETE /books/{id}/bookmark
Remove bookmark from a book

#### POST /books/purchase
Purchase books

**Request Body:**
```json
{
  "books": [1, 2, 3]
}
```

#### GET /books/search
Search books

#### POST /books/preview
Extract preview from PDF

#### GET /books/all (Admin Only)
Get all books for admin

#### POST /books/{book}/delete
Delete a book

#### POST /books/{action}/{bookId} (Admin Only)
Audit actions (request_changes, approve, decline, restore)

### Category Management

#### GET /categories
Get all categories

#### GET /categories/{category}
Get specific category

#### POST /categories
Create new category

#### PUT /categories/{category}
Update category

#### DELETE /categories/{category}
Delete category

### Order Management

#### GET /order
Get all orders (admin)

#### GET /order/my-orders
Get current user's orders

#### POST /order
Create new order

**Request Body:**
```json
{
  "books": [
    {
      "book_id": 1,
      "quantity": 2
    }
  ],
  "delivery_address_id": 1
}
```

#### GET /order/{id}
Get specific order details

#### GET /order/track/{tracking_id}
Track order by tracking number

#### PUT /order/{id}/status-update
Update order status

**Request Body:**
```json
{
  "status": "completed"
}
```

### Transaction Management

#### GET /transaction/verify
Verify payment

#### GET /transaction/my-transactions
Get user's transactions

#### GET /transaction/all (Admin Only)
Get all transactions

#### GET /transaction/{id}
Get specific transaction

### Subscription Management

#### GET /subscriptions
Get available subscriptions

#### GET /subscriptions/history (User)
Get user subscription history

#### GET /admin/subscriptions (Admin)
Get all subscriptions

#### POST /admin/subscriptions (Admin)
Create subscription

#### PUT /admin/subscriptions/{id} (Admin)
Update subscription

#### DELETE /admin/subscriptions/{id} (Admin)
Delete subscription

### Analytics

#### GET /analytics
Get analytics data

### Admin Management

#### GET /admin/app-versions-support
Get app versions support info

#### POST /admin/app-versions-support
Create app version support

#### PUT /admin/app-versions-support/{id}
Update app version support

#### GET /admin/app-versions-support/{id}
Get specific app version support

#### DELETE /admin/app-versions-support/{id}
Delete app version support

#### GET /admin/dashboard
Get admin dashboard data

### Social Authentication

#### GET /auth/{provider}/redirect
Redirect to social provider

#### GET /auth/{provider}/callback
Handle social provider callback

#### GET /auth/google
Redirect to Google

#### GET /auth/google/callback
Handle Google callback

### Utility Endpoints

#### GET /migrate
Run database migrations

#### GET /seed
Run database seeders

#### GET /clear
Clear application cache

#### GET /routes
List all routes

#### GET /storage-link
Create storage link

#### GET /optimize
Optimize application

#### GET /key-generate
Generate application key

#### GET /debug-db
Debug database connection

#### GET /show-db
Show database information

### Monitoring Endpoints

#### GET /monitor/health
Check application health

#### GET /monitor/queue
Check queue status

#### GET /monitor/schedule
Check schedule status

#### GET /monitor/webhooks/recent
Get recent webhooks

#### GET /monitor/version
Get application version

#### GET /monitor/stripe
Check Stripe connection

#### GET /monitor/cloudinary
Check Cloudinary connection

## Response Format

All API responses follow a consistent format:

```json
{
  "data": {},
  "code": 200,
  "message": "Success message"
}
```

For paginated responses:

```json
{
  "data": {
    "current_page": 1,
    "data": [...],
    "first_page_url": "...",
    "from": 1,
    "last_page": 10,
    "last_page_url": "...",
    "links": [...],
    "next_page_url": "...",
    "path": "...",
    "per_page": 20,
    "prev_page_url": null,
    "to": 20,
    "total": 200
  },
  "code": 200,
  "message": "Success message"
}
```

## HTTP Status Codes

- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `409` - Conflict
- `422` - Unprocessable Entity
- `500` - Internal Server Error
- `503` - Service Unavailable

## Rate Limiting

Endpoints are rate-limited to 60 requests per minute. Exceeding this limit will result in a 429 status code.

## Data Validation

All endpoints validate input data according to defined rules. Validation errors are returned in the following format:

```json
{
  "data": null,
  "code": 400,
  "message": "Validation failed",
  "error": {
    "field_name": [
      "Error message"
    ]
  }
}
