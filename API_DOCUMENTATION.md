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

## Dashboard API Endpoints

### GET /admin/dashboard
Get comprehensive dashboard analytics and metrics.

**Authentication Required**: Admin or Superadmin role

**Response:**
```json
{
  "data": {
    "reader_count": 1250,
    "author_count": 350,
    "published_books_count": 850,
    "pending_books_count": 45,
    "recent_signups": [...],
    "recent_transactions": [...],
    "recent_book_uploads": [...],
    "active_subscription_count": 420,
    "revenue": 125000.50,
    "reader_engagement": {
      "active_readers": 980,
      "total_reading_sessions": 15420,
      "average_reading_progress": 0.75,
      "total_reading_time_minutes": 1250.5
    },
    "books_published": 850,
    "total_sales": 145000.75
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully."
}
```

### Dashboard Payload Fields

| Field | Type | Description |
|-------|------|-------------|
| `reader_count` | integer | Total number of registered readers |
| `author_count` | integer | Total number of registered authors |
| `published_books_count` | integer | Total number of published books |
| `pending_books_count` | integer | Total number of books pending approval |
| `recent_signups` | array | List of recent user registrations |
| `recent_transactions` | array | List of recent financial transactions |
| `recent_book_uploads` | array | List of recent book uploads |
| `active_subscription_count` | integer | Total number of active subscriptions |
| `revenue` | decimal | Total revenue from all successful transactions |
| `reader_engagement` | object | Reader engagement metrics |
| `books_published` | integer | Total published books (alias of published_books_count) |
| `total_sales` | decimal | Total sales amount from all completed orders |

### Reader Engagement Metrics

The `reader_engagement` object contains:

| Field | Type | Description |
|-------|------|-------------|
| `active_readers` | integer | Number of users with reading progress |
| `total_reading_sessions` | integer | Total reading sessions across all users |
| `average_reading_progress` | float | Average reading progress across all users (0-100) |
| `total_reading_time_minutes` | float | Total reading time in minutes across all users |

### Usage Example

```bash
curl -X GET https://your-domain.com/api/admin/dashboard \
  -H "Authorization: Bearer <admin-token>" \
  -H "Accept: application/json"
```

### GET /author/dashboard
Get author-specific dashboard analytics and metrics.

**Authentication Required**: Author role

**Response:**
```json
{
  "data": {
    "revenue": 15000.75,
    "reader_engagement": {
      "active_readers": 120,
      "total_reading_sessions": 450,
      "average_reading_progress": 0.68,
      "total_reading_time_minutes": 2850.5
    },
    "books_published": 12,
    "total_sales": 18500.25,
    "total_books_count": 15,
    "pending_books_count": 3,
    "recent_transactions": [...],
    "recent_book_uploads": [...]
  },
  "code": 200,
  "message": "Author dashboard data retrieved successfully."
}
```

### Author Dashboard Payload Fields

| Field | Type | Description |
|-------|------|-------------|
| `revenue` | decimal | Total earnings from successful transactions for this author |
| `reader_engagement` | object | Reader engagement metrics for author's books |
| `books_published` | integer | Number of published books by this author |
| `total_sales` | decimal | Total sales amount from all completed orders for author's books |
| `total_books_count` | integer | Total number of books created by this author |
| `pending_books_count` | integer | Number of books pending approval by this author |
| `recent_transactions` | array | List of recent transactions for this author |
| `recent_book_uploads` | array | List of recent book uploads by this author |

### Author Reader Engagement Metrics

The `reader_engagement` object for authors contains:

| Field | Type | Description |
|-------|------|-------------|
| `active_readers` | integer | Number of users reading this author's books |
| `total_reading_sessions` | integer | Total reading sessions across author's books |
| `average_reading_progress` | decimal | Average reading progress across author's books |
| `total_reading_time_minutes` | decimal | Total reading time for author's books |

```bash
curl -X GET https://your-domain.com/api/author/dashboard \
  -H "Authorization: Bearer <author-token>" \
  -H "Accept: application/json"
```

### Response Structure

All dashboard endpoints return a consistent JSON response with the following structure:

```json
{
  "data": {
    // Dashboard metrics
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully."
}
```

### Error Handling

If an error occurs, the response will include:

```json
{
  "data": null,
  "code": 500,
  "message": "An error occurred while retrieving dashboard data.",
  "error": {
    "details": "Error details"
  }
}
```

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

#### GET /author/dashboard
Get author-specific dashboard data

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
