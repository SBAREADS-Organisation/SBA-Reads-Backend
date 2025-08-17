<?php

namespace App\Http\Resources\Order;

use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'tracking_number' => $this->tracking_number,
            'status' => $this->status,
            'total_amount' => $this->total_amount,
            'delivery_address' => $this->deliveryAddress?->address,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'book' => [
                        'id' => $item->book->id,
                        'title' => $item->book->title,
                        'cover_image' => $item->book->cover_image,
                        'actual_price' => $item->book->actual_price,
                        'discounted_price' => $item->book->discounted_price,
                    ],
                ];
            }),
        ];
    }
}