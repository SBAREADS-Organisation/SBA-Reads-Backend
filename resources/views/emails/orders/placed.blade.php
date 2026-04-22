@extends('emails.layout')
@section('title', 'Order Confirmed')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#27ae60;text-transform:uppercase;letter-spacing:1px;">Order Confirmed ✓</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Order Is Placed!</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $order->contact_name ?? $userName }}, we've received your order. Keep this email for your records.
  </p>

  {{-- Tracking number --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="background:#160c08;border-radius:10px;padding:20px 24px;">
        <p style="margin:0 0 4px;font-size:12px;color:rgba(216,185,156,0.7);text-transform:uppercase;letter-spacing:2px;">Tracking Number</p>
        <span style="font-size:22px;font-weight:700;color:#D8B99C;letter-spacing:4px;font-family:monospace;">{{ $order->tracking_number }}</span>
      </td>
    </tr>
  </table>

  {{-- Order items --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">Order Items</p>
      </td>
    </tr>
    @foreach($order->items as $item)
    <tr>
      <td style="background:#ffffff;padding:14px 20px;border-bottom:1px solid #f5f0eb;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td>
              <p style="margin:0;font-size:14px;font-weight:600;color:#160c08;">{{ $item->book->title ?? 'Book' }}</p>
              <p style="margin:3px 0 0;font-size:12px;color:#9e8272;">Qty: {{ $item->quantity }}</p>
            </td>
            <td align="right">
              <p style="margin:0;font-size:14px;font-weight:700;color:#4E342E;">${{ number_format($item->unit_price ?? $item->price, 2) }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    @endforeach
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:14px;font-weight:700;color:#160c08;">Total</td>
            <td align="right" style="font-size:16px;font-weight:700;color:#160c08;">${{ number_format($order->total_amount, 2) }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- Delivery info --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:28px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">
          {{ $order->delivery_type === 'pickup' ? '🏪 Store Pickup' : '🚚 Delivery Details' }}
        </p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 20px;">
        @if($order->delivery_type === 'pickup')
          <p style="margin:0 0 6px;font-size:14px;color:#4a3728;line-height:1.6;"><strong>Location:</strong> SBAreads Store, Lagos, Nigeria</p>
          <p style="margin:0;font-size:13px;color:#9e8272;">We'll notify you when your order is ready for pickup.</p>
        @else
          @if($order->contact_name)
            <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Contact:</strong> {{ $order->contact_name }}</p>
          @endif
          @if($order->contact_phone)
            <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Phone:</strong> {{ $order->contact_phone }}</p>
          @endif
          @if($order->delivery_address)
            <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Address:</strong> {{ $order->delivery_address }}</p>
          @endif
          @if($order->delivery_state || $order->delivery_country)
            <p style="margin:0;font-size:14px;color:#4a3728;">
              <strong>Location:</strong> {{ implode(', ', array_filter([$order->delivery_state, $order->delivery_country])) }}
            </p>
          @endif
        @endif
      </td>
    </tr>
  </table>

  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f5f0eb;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#6b5448;line-height:1.6;">
          📦 You'll receive another email when your order status updates. Use your tracking number to follow up with our team.
        </p>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Thank you for your order,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
