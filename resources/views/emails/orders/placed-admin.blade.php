@extends('emails.layout')
@section('title', 'New Order Received')

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#e67e22;text-transform:uppercase;letter-spacing:1px;">New Order 🛒</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">A New Physical Order Was Placed</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    A customer just placed an order. Here are the details:
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

  {{-- Customer info --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">Customer</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 20px;">
        <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Name:</strong> {{ $order->user->name ?? $order->contact_name ?? 'N/A' }}</p>
        <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Email:</strong> {{ $order->user->email ?? 'N/A' }}</p>
        <p style="margin:0;font-size:14px;color:#4a3728;"><strong>Phone:</strong> {{ $order->contact_phone ?? 'N/A' }}</p>
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
          {{ $order->delivery_type === 'pickup' ? 'Store Pickup' : 'Delivery Address' }}
        </p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 20px;">
        @if($order->delivery_type === 'pickup')
          <p style="margin:0;font-size:14px;color:#4a3728;">Customer will pick up from store.</p>
        @else
          @if($order->contact_name)
            <p style="margin:0 0 6px;font-size:14px;color:#4a3728;"><strong>Contact:</strong> {{ $order->contact_name }}</p>
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

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Log in to the admin dashboard to process this order.<br/>
    <strong style="color:#160c08;">SBA Reads System</strong>
  </p>
@endsection
