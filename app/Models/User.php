<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'default_login',
        'first_name',
        'last_name',
        'status',
        'account_type',
        'settings',
        'preferences',
        // 'pronouns',
        'profile_picture',
        'kyc_account_id',
        'kyc_customer_id',
        'kyc_provider',
        'kyc_metadata',
        'kyc_status',
        'profile_info',
        'last_login_at',
        'mfa_secret',
        'deleted',
        'archived',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'roles',
        'permissions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'profile_info' => 'array',
            'last_login_at' => 'datetime',
            'settings' => 'array',
            'preferences' => 'array',
            'profile_picture' => 'array',
            'kyc_metadata' => 'array',
        ];
    }

    public function media()
    {
        return $this->morphMany(MediaUpload::class, 'mediable');
    }

    public function avatar()
    {
        return $this->morphOne(MediaUpload::class, 'mediable')->where('context', 'user_avatar');
    }

    /**
     * Get the professional profile associated with the user.
     */
    public function professionalProfile()
    {
        return $this->hasOne(ProfessionalProfile::class);
    }

    // One-to-many relationship: User has many payment methods
    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function bookmarks() {
        return $this->belongsToMany(Book::class, 'book_user_bookmarks')->withTimestamps();
    }

    public function authoredBooks() {
        return $this->belongsToMany(Book::class, 'books_authors', 'author_id', 'book_id');
    }

    public function books()
    {
        return $this->belongsToMany(Book::class, 'book_authors', 'author_id', 'book_id')->withTimestamps();
    }

    /**
     * Get the social accounts associated with the user.
     */
    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)->where('status', 'active');
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryAddresses()
    {
        return $this->hasMany(Address::class);
    }

    /**
     * The user's “default” delivery address.
     */
    public function defaultDeliveryAddress()
    {
        return $this->deliveryAddresses()->where('is_default', true)->first();
    }

    /**
     * Is user an author?
     */
    public function isAuthor(): bool
    {
        return $this->account_type === 'author';
    }

    /**
     * Is user an admin?
     */
    public function isAdmin(): bool
    {
        return $this->account_type === 'admin';
    }

    /**
     * Is user an superadmin?
     */
    public function isSuperAdmin(): bool
    {
        return $this->account_type === 'superadmin';
    }

    /**
     * Is user a reader?
     */
    public function isReader(): bool
    {
        return $this->account_type === 'reader';
    }

    /**
     * Get all payments/transactions for the user.
     */
    public function payments()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Optionally, get only successful payments (helper relationship).
     */
    public function successfulPayments()
    {
        return $this->payments()->where('status', 'succeeded');
    }

    /**
     * Optionally, get only failed payments (helper relationship).
     */
    public function failedPayments()
    {
        return $this->payments()->where('status', 'failed');
    }

    /**
     * Automatically hash password when setting it
     */
    // public function setPasswordAttribute($value)
    // {
    //     $this->attributes['password'] = $value;
    // }
}
