<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\User;
use App\Models\Address;
use App\Models\OrderItem;
use App\Models\Book;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_an_order()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'total_amount' => 100.00,
            'platform_fee_amount' => 10.00,
            'status' => 'pending',
            'tracking_number' => 'TRK-TEST-12345',
        ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total_amount' => 100.00,
            'platform_fee_amount' => 10.00,
            'status' => 'pending',
            'tracking_number' => 'TRK-TEST-12345',
        ]);

        $this->assertInstanceOf(Order::class, $order);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $order->user);
        $this->assertEquals($user->id, $order->user->id);
    }

    /** @test */
    public function it_has_many_order_items()
    {
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(1, $order->items);
        $this->assertInstanceOf(OrderItem::class, $order->items->first());
    }

    /** @test */
    public function it_belongs_to_a_delivery_address()
    {
        $user = User::factory()->create();
        $address = Address::factory()->create(['user_id' => $user->id]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'delivery_address_id' => $address->id,
        ]);

        $this->assertInstanceOf(Address::class, $order->deliveryAddress);
        $this->assertEquals($address->id, $order->deliveryAddress->id);
    }

    /** @test */
    public function it_belongs_to_a_transaction()
    {
        $transaction = Transaction::factory()->create();
        $order = Order::factory()->create(['transaction_id' => $transaction->id]);

        $this->assertInstanceOf(Transaction::class, $order->transaction);
        $this->assertEquals($transaction->id, $order->transaction->id);
    }

    /** @test */
    public function it_can_generate_tracking_number()
    {
        $trackingNumber = Order::generateTrackingNumber();

        $this->assertStringStartsWith('TRK-', $trackingNumber);
        $this->assertEquals(15, strlen($trackingNumber));
    }

    /** @test */
    public function it_has_correct_fillable_fields()
    {
        $order = new Order();
        $expectedFillable = [
            'user_id',
            'total_amount',
            'platform_fee_amount',
            'status',
            'payout_status',
            'transaction_id',
            'tracking_number',
            'delivery_address_id',
            'delivered_at',
        ];

        $this->assertEquals($expectedFillable, $order->getFillable());
    }

    /** @test */
    public function it_has_correct_casts()
    {
        $order = new Order();
        $expectedCasts = [
            'id' => 'int',
            'transaction_id' => 'string',
        ];

        $this->assertEquals($expectedCasts, $order->getCasts());
    }

    /** @test */
    public function it_can_have_different_statuses()
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $this->assertEquals('pending', $order->status);

        $order->update(['status' => 'processing']);
        $this->assertEquals('processing', $order->status);

        $order->update(['status' => 'completed']);
        $this->assertEquals('completed', $order->status);

        $order->update(['status' => 'cancelled']);
        $this->assertEquals('cancelled', $order->status);
    }

    /** @test */
    public function it_can_have_different_payout_statuses()
    {
        $order = Order::factory()->create(['payout_status' => 'initiated']);
        $this->assertEquals('initiated', $order->payout_status);

        $order->update(['payout_status' => 'completed']);
        $this->assertEquals('completed', $order->payout_status);

        $order->update(['payout_status' => 'failed']);
        $this->assertEquals('failed', $order->payout_status);
    }
}
