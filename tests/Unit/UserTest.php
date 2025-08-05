<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Book;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_user()
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'account_type' => 'reader',
        ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'account_type' => 'reader',
        ]);

        $this->assertInstanceOf(User::class, $user);
    }

    /** @test */
    public function it_can_check_if_user_is_author()
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $reader = User::factory()->create(['account_type' => 'reader']);

        $this->assertTrue($author->isAuthor());
        $this->assertFalse($reader->isAuthor());
    }

    /** @test */
    public function it_can_check_if_user_is_admin()
    {
        $admin = User::factory()->create(['account_type' => 'manager']);
        $reader = User::factory()->create(['account_type' => 'reader']);

        $this->assertTrue($admin->isAdmin());
        $this->assertFalse($reader->isAdmin());
    }

    /** @test */
    public function it_can_check_if_user_is_super_admin()
    {
        $superAdmin = User::factory()->create(['account_type' => 'superadmin']);
        $reader = User::factory()->create(['account_type' => 'reader']);

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($reader->isSuperAdmin());
    }

    /** @test */
    public function it_can_check_if_user_is_reader()
    {
        $reader = User::factory()->create(['account_type' => 'reader']);
        $author = User::factory()->create(['account_type' => 'author']);

        $this->assertTrue($reader->isReader());
        $this->assertFalse($author->isReader());
    }

    /** @test */
    public function it_has_many_books_as_author()
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create();
        $author->authoredBooks()->attach($book);

        $this->assertCount(1, $author->authoredBooks);
        $this->assertInstanceOf(Book::class, $author->authoredBooks->first());
    }

    /** @test */
    public function it_has_many_bookmarks()
    {
        $user = User::factory()->create(['account_type' => 'reader']);
        $book = Book::factory()->create();
        $user->bookmarks()->attach($book);

        $this->assertCount(1, $user->bookmarks);
        $this->assertInstanceOf(Book::class, $user->bookmarks->first());
    }

    /** @test */
    public function it_can_have_a_default_delivery_address()
    {
        $user = User::factory()->create();
        $address = $user->deliveryAddresses()->create([
            'address' => '123 Main St',
            'city' => 'Test City',
            'region' => 'Test State',
            'postal_code' => '12345',
            'country' => 'Test Country',
            'is_default' => true,
        ]);

        $defaultAddress = $user->defaultDeliveryAddress();

        $this->assertNotNull($defaultAddress);
        $this->assertEquals($address->id, $defaultAddress->id);
        $this->assertEquals('123 Main St', $defaultAddress->address);
    }
}
