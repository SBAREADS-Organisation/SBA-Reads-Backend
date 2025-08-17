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

# Withdrawal Endpoint Documentation

## Overview
The withdrawal endpoint allows users to withdraw funds from their wallet balance to their connected bank accounts via Stripe Connect.

## Endpoints

### 1. Initiate Withdrawal
**POST** `/api/withdrawals/initiate`

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "amount": 50.00,
    "currency": "usd",
    "description": "Monthly earnings withdrawal",
    "withdrawal_method": "bank_transfer",
    "bank_account_id": "ba_1234567890"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Withdrawal initiated successfully",
    "data": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "reference": "wd_5f8a2b1c",
        "amount": 50.00,
        "currency": "usd",
        "status": "processing",
        "description": "Monthly earnings withdrawal",
        "withdrawal_method": "bank_transfer",
        "bank_account_id": "ba_1234567890",
        "created_at": "2024-01-15T10:30:00.000000Z",
        "updated_at": "2024-01-15T10:30:00.000000Z",
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
}
```

### 2. Get Withdrawal History
**GET** `/api/withdrawals/history`

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `status` (optional): Filter by status (pending, processing, succeeded, failed)
- `from_date` (optional): Filter from date (YYYY-MM-DD)
- `to_date` (optional): Filter to date (YYYY-MM-DD)
- `per_page` (optional): Results per page (default: 15)

**Response:**
```json
{
    "success": true,
    "message": "Withdrawal history retrieved successfully",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440000",
                "reference": "wd_5f8a2b1c",
                "amount": 50.00,
                "currency": "usd",
                "status": "processing",
                "description": "Monthly earnings withdrawal",
                "created_at": "2024-01-15T10:30:00.000000Z"
            }
        ],
        "total": 5,
        "per_page": 15,
        "current_page": 1,
        "last_page": 1
    }
}
```

### 3. Get Withdrawal Details
**GET** `/api/withdrawals/{id}`

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
    "success": true,
    "message": "Withdrawal details retrieved successfully",
    "data": {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "reference": "wd_5f8a2b1c",
        "amount": 50.00,
        "currency": "usd",
        "status": "processing",
        "description": "Monthly earnings withdrawal",
        "withdrawal_method": "bank_transfer",
        "bank_account_id": "ba_1234567890",
        "created_at": "2024-01-15T10:30:00.000000Z",
        "updated_at": "2024-01-15T10:30:00.000000Z",
        "payout_data": {
            "transfer_id": "tr_1234567890",
            "amount": 50.00,
            "currency": "usd",
            "destination": "acct_1234567890"
        },
        "user": {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com"
        }
    }
}
```

## Status Codes
- **pending**: Withdrawal request received, awaiting processing
- **processing**: Withdrawal is being processed
- **succeeded**: Withdrawal completed successfully
- **failed**: Withdrawal failed

## Error Responses

### 400 Bad Request
```json
{
    "success": false,
    "message": "Insufficient wallet balance for withdrawal",
    "data": null
}
```

### 404 Not Found
```json
{
    "success": false,
    "message": "Withdrawal not found",
    "data": null
}
```

### 500 Internal Server Error
```json
{
    "success": false,
    "message": "Stripe transfer failed: Insufficient funds",
    "data": null
}
```

## Validation Rules
- **amount**: Required, numeric, minimum $1.00
- **currency**: Required, string, must be one of: usd, eur, gbp
- **description**: Optional, string, max 255 characters
- **withdrawal_method**: Optional, string, must be one of: bank_transfer, paypal, check
- **bank_account_id**: Optional, string, Stripe bank account ID

## Business Logic
1. **Balance Check**: User must have sufficient wallet balance
2. **Minimum Amount**: Minimum withdrawal amount is $1.00
3. **Stripe Account**: User must have connected Stripe account (kyc_account_id)
4. **Fee Structure**: Withdrawal fees are calculated as $0.25 + 1% of amount
5. **Processing**: Withdrawals are processed via Stripe Connect transfers
6. **Balance Update**: User wallet balance is decremented upon successful transfer

## Webhook Integration
The system listens for Stripe webhook events to update withdrawal status:
- `transfer.succeeded`: Marks withdrawal as succeeded
- `transfer.failed`: Marks withdrawal as failed and refunds balance

## Security Considerations
- All endpoints require authentication
- Users can only access their own withdrawal records
- Rate limiting applied to prevent abuse
- Input validation and sanitization implemented
- Secure Stripe API integration with proper error handling


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
  "otp": "123456"
}
```

**Response:**
```json
{
  "data": null,
  "code": 200,
  "message": "OTP verified successfully"
}
```

**Error Response:**
```json
{
  "data": null,
  "code": 400,
  "message": "Invalid or expired OTP"
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
  },
  "profile_picture": "file_upload",
  "preferences": {
    "genres": ["fiction", "mystery"],
    "notifications": true
  },
  "settings": {
    "theme": "dark",
    "language": "en"
  }
}
```

**Response:**
```json
{
  "data": {
    "id": 1,
    "name": "John Updated",
    "email": "user@example.com",
    "account_type": "reader",
    "status": "active",
    "profile_picture": "https://example.com/profile.jpg",
    "bio": "An updated book lover",
    "preferences": {
      "genres": ["fiction", "mystery"]
    },
    "settings": {
      "theme": "dark"
    }
  },
  "code": 200,
  "message": "Profile updated successfully!"
}
```

**Notes:**
- `profile_picture` accepts file upload
- `preferences` and `settings` are merged with existing data (for authors)
- Only provided fields are updated

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

**Query Parameters:**
- `page`: Page number (default: 1)
- `per_page`: Items per page (default: 15)
- `status`: Filter by read status (read, unread)
- `type`: Filter by notification type

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "type": "book_approved",
      "title": "Book Approved",
      "message": "Your book 'The Great Novel' has been approved and is now live.",
      "data": {
        "book_id": 123,
        "book_title": "The Great Novel"
      },
      "read_at": null,
      "created_at": "2025-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "type": "new_review",
      "title": "New Review",
      "message": "You received a new 5-star review on 'The Great Novel'.",
      "data": {
        "book_id": 123,
        "review_id": 456,
        "rating": 5
      },
      "read_at": "2025-01-15T11:00:00.000000Z",
      "created_at": "2025-01-15T10:45:00.000000Z"
    }
  ],
  "links": {
    "first": "http://api.example.com/user/notifications?page=1",
    "last": "http://api.example.com/user/notifications?page=3",
    "prev": null,
    "next": "http://api.example.com/user/notifications?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "per_page": 15,
    "to": 15,
    "total": 42,
    "unread_count": 5
  }
}
```

#### POST /user/notifications/{notification}/mark-as-read
Mark notification as read

**Response:**
```json
{
  "data": {
    "id": 1,
    "type": "book_approved",
    "title": "Book Approved",
    "message": "Your book 'The Great Novel' has been approved and is now live.",
    "data": {
      "book_id": 123,
      "book_title": "The Great Novel"
    },
    "read_at": "2025-01-15T12:00:00.000000Z",
    "created_at": "2025-01-15T10:30:00.000000Z"
  },
  "code": 200,
  "message": "Notification marked as read successfully"
}
```

#### POST /user/notifications/mark-all-as-read
Mark all notifications as read

**Response:**
```json
{
  "data": {
    "marked_count": 5
  },
  "code": 200,
  "message": "All notifications marked as read successfully"
}
```

**Notification Types:**
- `book_approved`: Book has been approved by admin
- `book_rejected`: Book has been rejected by admin
- `new_review`: New review received on user's book
- `order_status`: Order status update
- `payment_success`: Payment processed successfully
- `payment_failed`: Payment processing failed
- `subscription_expiring`: Subscription expiring soon
- `new_book_release`: New book from followed author

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

**Response (Listing - Optimized):**
```json
{
  "data": [
    {
      "id": 1,
      "title": "The Hitchhiker's Guide to the Galaxy",
      "slug": "the-hitchhikers-guide-to-the-galaxy",
      "cover_image": {
        "public_url": "https://res.cloudinary.com/example/image/upload/v1754163793/books/covers/book.png",
        "public_id": 1
      },
      "actual_price": 9.99,
      "discounted_price": 39.99,
      "currency": "USD",
      "format": "ebook",
      "publisher": "Pan Books",
      "publication_date": "1979-10-12T00:00:00.000000Z",
      "status": "pending",
      "created_at": "2025-08-02T19:43:18.000000Z",
      "average_rating": 4.2,
      "reviews_count": 15,
      "authors": ["Douglas Adams"],
      "categories": ["Fiction", "Science Fiction"]
    }
  ],
  "links": {
    "first": "http://api.example.com/books?page=1",
    "last": "http://api.example.com/books?page=5",
    "prev": null,
    "next": "http://api.example.com/books?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "per_page": 10,
    "to": 10,
    "total": 50
  }
}
```

**Classification Types:**
- `new_arrivals`: Books created in the last 30 days, ordered by newest first
- `trending`: Books ordered by view count (most viewed first)
- `top_picks`: Books with average rating >= 4.0, ordered by highest rating
- No classification: Random order

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

**Response:**
```json
{
  "data": {
    "book_id": 1,
    "user_id": 3,
    "bookmarked": true
  },
  "code": 200,
  "message": "Book bookmarked successfully"
}
```

#### DELETE /books/{id}/bookmark
Remove bookmark from a book

**Response:**
```json
{
  "data": {
    "book_id": 1,
    "user_id": 3,
    "bookmarked": false
  },
  "code": 200,
  "message": "Bookmark removed successfully"
}
```

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

#### GET /author/transactions (Author Only)
Get author's transaction history including payouts and earnings

**Query Parameters:**
- `search` - Search in reference, description, or payment_intent_id
- `status` - Filter by transaction status (pending, succeeded, failed)
- `type` - Filter by transaction type (payout, purchase, etc.)
- `direction` - Filter by direction (credit, debit)
- `purpose_type` - Filter by purpose type (digital_book_purchase, order, etc.)
- `start_date` - Filter transactions from this date
- `end_date` - Filter transactions until this date
- `sort_by` - Sort by field (default: created_at)
- `sort_order` - Sort order (asc, desc - default: desc)
- `per_page` - Number of results per page (default: 15)

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





