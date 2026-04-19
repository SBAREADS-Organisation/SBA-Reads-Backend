<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Book\BookResource;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'order_number'     => $this->order_number,
            'tracking_number'  => $this->tracking_number,
            'status'           => $this->status,
            'total_amount'     => $this->total_amount,
            'delivery_type'    => $this->delivery_type ?? 'delivery',
            'delivery_address' => $this->deliveryAddress?->address,
            'delivery_state'   => $this->delivery_state,
            'delivery_country' => $this->delivery_country,
            'contact_name'     => $this->contact_name,
            'contact_phone'    => $this->contact_phone,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
            'items'            => $this->items->map(function ($item) {
                return [
                    'id'          => $item->id,
                    'quantity'    => $item->quantity,
                    'unit_price'  => $item->unit_price,
                    'total_price' => $item->total_price,
                    'book'        => new BookResource($item->book),
                ];
            }),
        ];
    }
}
