<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_book()
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create([
            'title' => 'Test Book',
            'author_id' => $author->id,
        ]);

        $this->assertDatabaseHas('books', [
            'title' => 'Test Book',
            'author_id' => $author->id,
        ]);

        $this->assertInstanceOf(Book::class, $book);
    }

    #[Test]
    public function it_has_an_author()
    {
        $author = User::factory()->create(['account_type' => 'author']);
        $book = Book::factory()->create(['author_id' => $author->id]);

        $this->assertInstanceOf(User::class, $book->author);
        $this->assertEquals($author->id, $book->author->id);
    }

    /** @test */
    public function it_has_categories()
    {
        $book = Book::factory()->create();
        $category = \App\Models\Category::factory()->create();
        $book->categories()->attach($category);

        $this->assertCount(1, $book->categories);
        $this->assertInstanceOf(\App\Models\Category::class, $book->categories->first());
    }

    /** @test */
    public function it_has_bookmarks()
    {
        $book = Book::factory()->create();
        $user = User::factory()->create(['account_type' => 'reader']);
        $user->bookmarks()->attach($book);

        $this->assertCount(1, $book->bookmarkedBy);
        $this->assertInstanceOf(User::class, $book->bookmarkedBy->first());
    }

    /** @test */
    public function it_has_reviews()
    {
        $book = Book::factory()->create();
        $user = User::factory()->create(['account_type' => 'reader']);
        $review = $book->reviews()->create([
            'user_id' => $user->id,
            'rating' => 5,
            'comment' => 'Great book!',
        ]);

        $this->assertCount(1, $book->reviews);
        $this->assertInstanceOf(\App\Models\BookReviews::class, $book->reviews->first());
        $this->assertEquals($review->id, $book->reviews->first()->id);
    }

    /** @test */
    public function it_has_reading_progress()
    {
        $book = Book::factory()->create();
        $user = User::factory()->create(['account_type' => 'reader']);
        $progress = $book->readingProgress()->create([
            'user_id' => $user->id,
            'progress' => 50,
            'page' => '50',
        ]);

        $this->assertCount(1, $book->readingProgress);
        $this->assertInstanceOf(\App\Models\ReadingProgress::class, $book->readingProgress->first());
        $this->assertEquals($progress->id, $book->readingProgress->first()->id);
    }

    /** @test */
    public function it_has_analytics()
    {
        $book = Book::factory()->create();
        $analytics = $book->analytics()->create([
            'views' => 100,
            'downloads' => 50,
            'likes' => 25,
        ]);

        $this->assertInstanceOf(\App\Models\BookMetaDataAnalytics::class, $book->analytics);
        $this->assertEquals($analytics->id, $book->analytics->id);
    }

    /** @test */
    public function it_has_purchasers()
    {
        $book = Book::factory()->create();
        $user = User::factory()->create(['account_type' => 'reader']);
        $user->purchasedBooks()->attach($book);

        $this->assertCount(1, $book->purchasers);
        $this->assertInstanceOf(User::class, $book->purchasers->first());
    }
}
