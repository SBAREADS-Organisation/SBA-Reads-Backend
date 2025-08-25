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

**Authentication Required**: Manager or Superadmin role

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Response:**
```json
{
  "data": {
    "reader_count": 150,
    "author_count": 45,
    "published_books_count": 230,
    "pending_books_count": 12,
    "active_subscription_count": 89,
    "revenue": {
      "usd": 1250.50,
      "ngn": 850000.00,
      "naira_total": 2100000.00
    },
    "total_sales": {
      "usd": 2500.75,
      "ngn": 1200000.00,
      "total": 3700000.75
    },
    "total_books_sold": 456,
    "reader_engagement": {
      "active_readers": 78,
      "total_reading_sessions": 1234,
      "average_reading_progress": 65.5,
      "total_reading_time_minutes": 45678.25
    },
    "recent_signups": [
      {
        "id": 123,
        "name": "John Doe",
        "email": "john@example.com",
        "account_type": "reader",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ],
    "recent_transactions": [
      {
        "id": 456,
        "amount": 25.99,
        "currency": "USD",
        "status": "completed",
        "created_at": "2024-01-15T14:20:00Z",
        "user": {
          "id": 789,
          "name": "Jane Smith",
          "email": "jane@example.com"
        }
      }
    ],
    "recent_book_uploads": [
      {
        "id": 101,
        "title": "Sample Book",
        "status": "pending",
        "created_at": "2024-01-15T09:15:00Z",
        "author": {
          "id": 202,
          "name": "Author Name",
          "email": "author@example.com"
        }
      }
    ],
    "weekly_revenue": 450.25,
    "weekly_signups": 23
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully."
}
```

**Dashboard Payload Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `reader_count` | integer | Total number of readers on platform |
| `author_count` | integer | Total number of authors on platform |
| `published_books_count` | integer | Total approved/published books |
| `pending_books_count` | integer | Books awaiting approval |
| `active_subscription_count` | integer | Active subscription count |
| `revenue` | object | Revenue breakdown by currency |
| `revenue.usd` | decimal | Total revenue in USD |
| `revenue.ngn` | decimal | Total revenue in Nigerian Naira |
| `revenue.naira_total` | decimal | Combined revenue in Naira |
| `total_sales` | object | Sales breakdown by currency |
| `total_books_sold` | integer | Total number of books sold |
| `reader_engagement` | object | Platform engagement metrics |
| `reader_engagement.active_readers` | integer | Currently active readers |
| `reader_engagement.total_reading_sessions` | integer | Total reading sessions |
| `reader_engagement.average_reading_progress` | decimal | Average reading progress percentage |
| `reader_engagement.total_reading_time_minutes` | decimal | Total reading time in minutes |
| `recent_signups` | array | Last 5 user registrations |
| `recent_transactions` | array | Last 5 platform transactions |
| `recent_book_uploads` | array | Last 5 book uploads |
| `weekly_revenue` | decimal | Revenue for current week |
| `weekly_signups` | integer | New signups this week |

```bash
curl -X GET https://your-domain.com/api/admin/dashboard \
  -H "Authorization: Bearer <admin-token>" \
  -H "Accept: application/json"
```

### GET /author/dashboard
Get author-specific dashboard analytics and metrics.

**Authentication Required**: Author role

**Headers:**
```
Authorization: Bearer {token}
Accept: application/json
```

**Response:**
```json
{
  "data": {
    "revenue": 1250.50,
    "reader_engagement": {
      "active_readers": 25,
      "total_reading_sessions": 156,
      "average_reading_progress": 72.3,
      "total_reading_time_minutes": 8945.5
    },
    "books_published": 8,
    "total_sales": 2340.75,
    "books_sold": 45,
    "books_uploaded": 10,
    "books_rejected": 1,
    "books_approved": 8,
    "total_books_count": 10,
    "pending_books_count": 1,
    "recent_transactions": [
      {
        "id": 789,
        "amount": 15.99,
        "currency": "USD",
        "status": "completed",
        "created_at": "2024-01-15T16:45:00Z",
        "book": {
          "id": 101,
          "title": "My Book Title"
        }
      }
    ],
    "recent_book_uploads": [
      {
        "id": 102,
        "title": "Latest Book",
        "status": "approved",
        "created_at": "2024-01-14T11:30:00Z",
        "cover_image": "https://cloudinary.com/image.jpg"
      }
    ],
    "monthly_sales": 12,
    "wallet_balance": 450.25,
    "metrics": {
      "sales_by_book": {
        "101": {
          "digital_sales": 5,
          "physical_sales": 3,
          "total_sales": 8
        }
      },
      "top_performing_books": [
        {
          "id": 101,
          "title": "Best Seller",
          "total_sales": 25,
          "revenue": 375.00
        }
      ],
      "total_books_with_sales": 6
    },
    "status_breakdown": {
      "approved": 8,
      "pending": 1,
      "rejected": 1,
      "total": 10
    }
  },
  "code": 200,
  "message": "Author dashboard data retrieved successfully."
}
```

**Author Dashboard Payload Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `revenue` | decimal | Total author earnings |
| `reader_engagement` | object | Engagement metrics for author's books |
| `reader_engagement.active_readers` | integer | Readers currently reading author's books |
| `reader_engagement.total_reading_sessions` | integer | Total sessions for author's books |
| `reader_engagement.average_reading_progress` | decimal | Average progress on author's books |
| `reader_engagement.total_reading_time_minutes` | decimal | Total time spent reading author's books |
| `books_published` | integer | Number of approved books |
| `total_sales` | decimal | Total sales revenue |
| `books_sold` | integer | Total number of books sold |
| `books_uploaded` | integer | Total books uploaded by author |
| `books_rejected` | integer | Number of rejected books |
| `books_approved` | integer | Number of approved books |
| `total_books_count` | integer | Total books by author |
| `pending_books_count` | integer | Books awaiting approval |
| `recent_transactions` | array | Last 5 author transactions |
| `recent_book_uploads` | array | Last 5 books uploaded by author |
| `monthly_sales` | integer | Sales count for current month |
| `wallet_balance` | decimal | Author's current wallet balance |
| `metrics` | object | Detailed performance metrics |
| `metrics.sales_by_book` | object | Sales breakdown per book |
| `metrics.top_performing_books` | array | Top 5 books by performance |
| `metrics.total_books_with_sales` | integer | Number of books that have sales |
| `status_breakdown` | object | Book status distribution |

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
Delete a book (authors can delete own books, admins can delete any)

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "reason": "Content violates platform guidelines"
}
```

**Response (Success):**
```json
{
  "data": null,
  "code": 200,
  "message": "Book deleted successfully."
}
```

**Response (Unauthorized):**
```json
{
  "data": null,
  "code": 403,
  "message": "Unauthorized. You can only delete your own books."
}
```

**Response (Has Purchases):**
```json
{
  "data": null,
  "code": 500,
  "message": "Cannot delete book that has been purchased"
}
```

**Notes:**
- Authors can only delete their own books
- Admins/superadmins can delete any book
- Books with completed purchases cannot be deleted
- Cover images and files are automatically removed from Cloudinary
- Author receives deletion notification with reason

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

#### POST /admin/invite-admin
SuperAdmin invite other Admin

**Request Body:**
```json
{
  "name": "Admin Invite",
  "email": "admininvite@example.com",
  "password": "Password123."
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

#### GET /migrate/rollback
Rollback previous migration

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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings": "1750.50",
    "pending_earnings": "525.75",
    "this_month_sales": 32,
    "this_month_earnings": "224.00"
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully"
}
```

### GET /author/my-books
Get author's books

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "status": "approved",
        "total_sales": 45,
        "created_at": "2024-01-01T00:00:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Books retrieved successfully"
}
```

### GET /author/transactions
Get author's transaction history

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "transactions": [
      {
        "id": 1,
        "book_title": "Book Title",
        "amount": "17.50",
        "type": "book_sale",
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book description...",
        "cover_image": "https://example.com/cover.jpg"
      }
    ]
  },
  "code": 200,
  "message": "Pending books retrieved successfully"
}
```

### POST /admin/books/{id}/approve
Approve a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Book meets all quality standards"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "approved_by": "Admin Name"
    }
  },
  "code": 200,
  "message": "Book approved successfully"
}
```

### POST /admin/books/{id}/reject
Reject a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "rejection_reason": "Content does not meet quality standards",
  "feedback": "Please improve the writing quality and resubmit"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "rejected",
      "rejected_at": "2024-01-15T10:30:00Z",
      "rejection_reason": "Content does not meet quality standards"
    }
  },
  "code": 200,
  "message": "Book rejected successfully"
}
```

### GET /admin/payouts/pending
Get pending payout requests

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "payouts": [
      {
        "id": 1,
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "amount": "500.00",
        "requested_at": "2024-01-15T10:30:00Z",
        "payout_method": "bank_transfer",
        "bank_details": {
          "account_name": "Author Name",
          "account_number": "1234567890",
          "bank_name": "First Bank"
        }
      }
    ]
  },
  "code": 200,
  "message": "Pending payouts retrieved successfully"
}
```

### POST /admin/payouts/{id}/approve
Approve a payout request

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Payout approved and processed",
  "transaction_reference": "TXN_123456789"
}
```

**Response:**
```json
{
  "data": {
    "payout": {
      "id": 1,
      "amount": "500.00",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "transaction_reference": "TXN_123456789"
    }
  },
  "code": 200,
  "message": "Payout approved successfully"
}
```

## Additional User Endpoints

### GET /user/purchases
Get user's purchase history

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number
- `status` (optional): `completed`, `pending`, `failed`

**Response:**
```json
{
  "data": {
    "purchases": [
      {
        "id": 1,
        "book": {
          "id": 1,
          "title": "Book Title",
          "author": "Author Name",
          "cover_image": "https://example.com/cover.jpg"
        },
        "amount": "25.00",
        "payment_method": "stripe",
        "status": "completed",
        "purchased_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 3,
      "total_items": 25
    }
  },
  "code": 200,
  "message": "Purchase history retrieved successfully"
}
```

### GET /user/library
Get user's purchased books library

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "author": "Author Name",
        "cover_image": "https://example.com/cover.jpg",
        "purchased_at": "2024-01-15T10:30:00Z",
        "last_read_at": "2024-01-16T14:20:00Z",
        "reading_progress": 45
      }
    ]
  },
  "code": 200,
  "message": "Library retrieved successfully"
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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings": "1750.50",
    "pending_earnings": "525.75",
    "this_month_sales": 32,
    "this_month_earnings": "224.00"
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully"
}
```

### GET /author/my-books
Get author's books

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "status": "approved",
        "total_sales": 45,
        "created_at": "2024-01-01T00:00:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Books retrieved successfully"
}
```

### GET /author/transactions
Get author's transaction history

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "transactions": [
      {
        "id": 1,
        "book_title": "Book Title",
        "amount": "17.50",
        "type": "book_sale",
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book description...",
        "cover_image": "https://example.com/cover.jpg"
      }
    ]
  },
  "code": 200,
  "message": "Pending books retrieved successfully"
}
```

### POST /admin/books/{id}/approve
Approve a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Book meets all quality standards"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "approved_by": "Admin Name"
    }
  },
  "code": 200,
  "message": "Book approved successfully"
}
```

### POST /admin/books/{id}/reject
Reject a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "rejection_reason": "Content does not meet quality standards",
  "feedback": "Please improve the writing quality and resubmit"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "rejected",
      "rejected_at": "2024-01-15T10:30:00Z",
      "rejection_reason": "Content does not meet quality standards"
    }
  },
  "code": 200,
  "message": "Book rejected successfully"
}
```

### GET /admin/payouts/pending
Get pending payout requests

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "payouts": [
      {
        "id": 1,
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "amount": "500.00",
        "requested_at": "2024-01-15T10:30:00Z",
        "payout_method": "bank_transfer",
        "bank_details": {
          "account_name": "Author Name",
          "account_number": "1234567890",
          "bank_name": "First Bank"
        }
      }
    ]
  },
  "code": 200,
  "message": "Pending payouts retrieved successfully"
}
```

### POST /admin/payouts/{id}/approve
Approve a payout request

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Payout approved and processed",
  "transaction_reference": "TXN_123456789"
}
```

**Response:**
```json
{
  "data": {
    "payout": {
      "id": 1,
      "amount": "500.00",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "transaction_reference": "TXN_123456789"
    }
  },
  "code": 200,
  "message": "Payout approved successfully"
}
```

## Additional User Endpoints

### GET /user/purchases
Get user's purchase history

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number
- `status` (optional): `completed`, `pending`, `failed`

**Response:**
```json
{
  "data": {
    "purchases": [
      {
        "id": 1,
        "book": {
          "id": 1,
          "title": "Book Title",
          "author": "Author Name",
          "cover_image": "https://example.com/cover.jpg"
        },
        "amount": "25.00",
        "payment_method": "stripe",
        "status": "completed",
        "purchased_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 3,
      "total_items": 25
    }
  },
  "code": 200,
  "message": "Purchase history retrieved successfully"
}
```

### GET /user/library
Get user's purchased books library

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "author": "Author Name",
        "cover_image": "https://example.com/cover.jpg",
        "purchased_at": "2024-01-15T10:30:00Z",
        "last_read_at": "2024-01-16T14:20:00Z",
        "reading_progress": 45
      }
    ]
  },
  "code": 200,
  "message": "Library retrieved successfully"
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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings": "1750.50",
    "pending_earnings": "525.75",
    "this_month_sales": 32,
    "this_month_earnings": "224.00"
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully"
}
```

### GET /author/my-books
Get author's books

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "status": "approved",
        "total_sales": 45,
        "created_at": "2024-01-01T00:00:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Books retrieved successfully"
}
```

### GET /author/transactions
Get author's transaction history

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "transactions": [
      {
        "id": 1,
        "book_title": "Book Title",
        "amount": "17.50",
        "type": "book_sale",
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book description...",
        "cover_image": "https://example.com/cover.jpg"
      }
    ]
  },
  "code": 200,
  "message": "Pending books retrieved successfully"
}
```

### POST /admin/books/{id}/approve
Approve a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Book meets all quality standards"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "approved_by": "Admin Name"
    }
  },
  "code": 200,
  "message": "Book approved successfully"
}
```

### POST /admin/books/{id}/reject
Reject a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "rejection_reason": "Content does not meet quality standards",
  "feedback": "Please improve the writing quality and resubmit"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "rejected",
      "rejected_at": "2024-01-15T10:30:00Z",
      "rejection_reason": "Content does not meet quality standards"
    }
  },
  "code": 200,
  "message": "Book rejected successfully"
}
```

### GET /admin/payouts/pending
Get pending payout requests

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "payouts": [
      {
        "id": 1,
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "amount": "500.00",
        "requested_at": "2024-01-15T10:30:00Z",
        "payout_method": "bank_transfer",
        "bank_details": {
          "account_name": "Author Name",
          "account_number": "1234567890",
          "bank_name": "First Bank"
        }
      }
    ]
  },
  "code": 200,
  "message": "Pending payouts retrieved successfully"
}
```

### POST /admin/payouts/{id}/approve
Approve a payout request

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Payout approved and processed",
  "transaction_reference": "TXN_123456789"
}
```

**Response:**
```json
{
  "data": {
    "payout": {
      "id": 1,
      "amount": "500.00",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "transaction_reference": "TXN_123456789"
    }
  },
  "code": 200,
  "message": "Payout approved successfully"
}
```

## Additional User Endpoints

### GET /user/purchases
Get user's purchase history

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number
- `status` (optional): `completed`, `pending`, `failed`

**Response:**
```json
{
  "data": {
    "purchases": [
      {
        "id": 1,
        "book": {
          "id": 1,
          "title": "Book Title",
          "author": "Author Name",
          "cover_image": "https://example.com/cover.jpg"
        },
        "amount": "25.00",
        "payment_method": "stripe",
        "status": "completed",
        "purchased_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 3,
      "total_items": 25
    }
  },
  "code": 200,
  "message": "Purchase history retrieved successfully"
}
```

### GET /user/library
Get user's purchased books library

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "author": "Author Name",
        "cover_image": "https://example.com/cover.jpg",
        "purchased_at": "2024-01-15T10:30:00Z",
        "last_read_at": "2024-01-16T14:20:00Z",
        "reading_progress": 45
      }
    ]
  },
  "code": 200,
  "message": "Library retrieved successfully"
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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings": "1750.50",
    "pending_earnings": "525.75",
    "this_month_sales": 32,
    "this_month_earnings": "224.00"
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully"
}
```

### GET /author/my-books
Get author's books

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "status": "approved",
        "total_sales": 45,
        "created_at": "2024-01-01T00:00:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Books retrieved successfully"
}
```

### GET /author/transactions
Get author's transaction history

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "transactions": [
      {
        "id": 1,
        "book_title": "Book Title",
        "amount": "17.50",
        "type": "book_sale",
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "
          "name": "Author Name",
          "email": "author@example.com"
        },
        "amount": "500.00",
        "requested_at": "2024-01-15T10:30:00Z",
        "payout_method": "bank_transfer",
        "bank_details": {
          "account_name": "Author Name",
          "account_number": "1234567890",
          "bank_name": "First Bank"
        }
      }
    ]
  },
  "code": 200,
  "message": "Pending payouts retrieved successfully"
}
```

### POST /admin/payouts/{id}/approve
Approve a payout request

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Payout approved and processed",
  "transaction_reference": "TXN_123456789"
}
```

**Response:**
```json
{
  "data": {
    "payout": {
      "id": 1,
      "amount": "500.00",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "transaction_reference": "TXN_123456789"
    }
  },
  "code": 200,
  "message": "Payout approved successfully"
}
```

## Additional User Endpoints

### GET /user/purchases
Get user's purchase history

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number
- `status` (optional): `completed`, `pending`, `failed`

**Response:**
```json
{
  "data": {
    "purchases": [
      {
        "id": 1,
        "book": {
          "id": 1,
          "title": "Book Title",
          "author": "Author Name",
          "cover_image": "https://example.com/cover.jpg"
        },
        "amount": "25.00",
        "payment_method": "stripe",
        "status": "completed",
        "purchased_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 3,
      "total_items": 25
    }
  },
  "code": 200,
  "message": "Purchase history retrieved successfully"
}
```

### GET /user/library
Get user's purchased books library

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "author": "Author Name",
        "cover_image": "https://example.com/cover.jpg",
        "purchased_at": "2024-01-15T10:30:00Z",
        "last_read_at": "2024-01-16T14:20:00Z",
        "reading_progress": 45
      }
    ]
  },
  "code": 200,
  "message": "Library retrieved successfully"
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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings": "1750.50",
    "pending_earnings": "525.75",
    "this_month_sales": 32,
    "this_month_earnings": "224.00"
  },
  "code": 200,
  "message": "Dashboard data retrieved successfully"
}
```

### GET /author/my-books
Get author's books

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "status": "approved",
        "total_sales": 45,
        "created_at": "2024-01-01T00:00:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Books retrieved successfully"
}
```

### GET /author/transactions
Get author's transaction history

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "transactions": [
      {
        "id": 1,
        "book_title": "Book Title",
        "amount": "17.50",
        "type": "book_sale",
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book
        "status": "completed",
        "created_at": "2024-01-15T10:30:00Z"
      }
    ]
  },
  "code": 200,
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book description...",
       
  "message": "Transactions retrieved successfully"
}
```

## Admin Endpoints

### GET /admin/dashboard
Get admin dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_users": 1250,
    "total_authors": 85,
    "total_books": 320,
    "pending_books": 12,
    "total_sales": 5420,
    "total_revenue": "38940.00",
    "platform_earnings": "11682.00",
    "pending_payouts": 8,
    "this_month_stats": {
      "new_users": 45,
      "new_books": 8,
      "sales": 234,
      "revenue": "1680.00"
    }
  },
  "code": 200,
  "message": "Admin dashboard data retrieved successfully"
}
```

### GET /admin/users
Get all users with filtering

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `role` (optional): `user`, `author`, `admin`
- `status` (optional): `active`, `suspended`
- `search` (optional): Search by name or email
- `page` (optional): Page number

**Response:**
```json
{
  "data": {
    "users": [
      {
        "id": 1,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "author",
        "status": "active",
        "total_purchases": 15,
        "total_books": 3,
        "joined_at": "2024-01-01T00:00:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250
    }
  },
  "code": 200,
  "message": "Users retrieved successfully"
}
```

### GET /admin/books/pending
Get books pending approval

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Pending Book Title",
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "category": "Fiction",
        "price": "25.00",
        "submitted_at": "2024-01-15T10:30:00Z",
        "description": "Book description...",
        "cover_image": "https://example.com/cover.jpg"
      }
    ]
  },
  "code": 200,
  "message": "Pending books retrieved successfully"
}
```

### POST /admin/books/{id}/approve
Approve a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Book meets all quality standards"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "approved_by": "Admin Name"
    }
  },
  "code": 200,
  "message": "Book approved successfully"
}
```

### POST /admin/books/{id}/reject
Reject a pending book

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "rejection_reason": "Content does not meet quality standards",
  "feedback": "Please improve the writing quality and resubmit"
}
```

**Response:**
```json
{
  "data": {
    "book": {
      "id": 1,
      "title": "Book Title",
      "status": "rejected",
      "rejected_at": "2024-01-15T10:30:00Z",
      "rejection_reason": "Content does not meet quality standards"
    }
  },
  "code": 200,
  "message": "Book rejected successfully"
}
```

### GET /admin/payouts/pending
Get pending payout requests

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "payouts": [
      {
        "id": 1,
        "author": {
          "id": 5,
          "name": "Author Name",
          "email": "author@example.com"
        },
        "amount": "500.00",
        "requested_at": "2024-01-15T10:30:00Z",
        "payout_method": "bank_transfer",
        "bank_details": {
          "account_name": "Author Name",
          "account_number": "1234567890",
          "bank_name": "First Bank"
        }
      }
    ]
  },
  "code": 200,
  "message": "Pending payouts retrieved successfully"
}
```

### POST /admin/payouts/{id}/approve
Approve a payout request

**Headers:**
```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
  "approval_notes": "Payout approved and processed",
  "transaction_reference": "TXN_123456789"
}
```

**Response:**
```json
{
  "data": {
    "payout": {
      "id": 1,
      "amount": "500.00",
      "status": "approved",
      "approved_at": "2024-01-15T10:30:00Z",
      "transaction_reference": "TXN_123456789"
    }
  },
  "code": 200,
  "message": "Payout approved successfully"
}
```

## Additional User Endpoints

### GET /user/purchases
Get user's purchase history

**Headers:**
```
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number
- `status` (optional): `completed`, `pending`, `failed`

**Response:**
```json
{
  "data": {
    "purchases": [
      {
        "id": 1,
        "book": {
          "id": 1,
          "title": "Book Title",
          "author": "Author Name",
          "cover_image": "https://example.com/cover.jpg"
        },
        "amount": "25.00",
        "payment_method": "stripe",
        "status": "completed",
        "purchased_at": "2024-01-15T10:30:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 3,
      "total_items": 25
    }
  },
  "code": 200,
  "message": "Purchase history retrieved successfully"
}
```

### GET /user/library
Get user's purchased books library

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "books": [
      {
        "id": 1,
        "title": "Book Title",
        "author": "Author Name",
        "cover_image": "https://example.com/cover.jpg",
        "purchased_at": "2024-01-15T10:30:00Z",
        "last_read_at": "2024-01-16T14:20:00Z",
        "reading_progress": 45
      }
    ]
  },
  "code": 200,
  "message": "Library retrieved successfully"
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
```

## Author/Publisher Endpoints

### GET /author/dashboard
Get author dashboard statistics

**Headers:**
```
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "total_books": 15,
    "total_sales": 245,
    "total_earnings




