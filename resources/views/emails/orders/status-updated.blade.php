@extends('emails.layout')
@section('title', 'Order Status Update')

@php
  $statusConfig = [
    'processing' => ['label' => 'Processing',  'color' => '#1a6fb5', 'bg' => '#dbeafe', 'icon' => '⚙️',  'msg' => 'Your order is being processed and will be prepared for delivery soon.'],
    'paid'       => ['label' => 'Payment Confirmed', 'color' => '#6d28d9', 'bg' => '#ede9fe', 'icon' => '💳', 'msg' => 'Your payment has been confirmed. Your order is now being prepared.'],
    'delivered'  => ['label' => 'Delivered',   'color' => '#15803d', 'bg' => '#dcfce7', 'icon' => '✅',  'msg' => 'Your order has been delivered. We hope you enjoy your books!'],
    'completed'  => ['label' => 'Completed',   'color' => '#15803d', 'bg' => '#dcfce7', 'icon' => '🎉',  'msg' => 'Your order is complete. Thank you for shopping with SBA Reads!'],
    'cancelled'  => ['label' => 'Cancelled',   'color' => '#b91c1c', 'bg' => '#fee2e2', 'icon' => '❌',  'msg' => 'Your order has been cancelled. If this was a mistake, please contact us.'],
    'declined'   => ['label' => 'Declined',    'color' => '#c2410c', 'bg' => '#ffedd5', 'icon' => '⚠️',  'msg' => 'Your order could not be fulfilled. Please contact our support team for assistance.'],
  ];
  $cfg = $statusConfig[$status] ?? ['label' => ucfirst($status), 'color' => '#4a3728', 'bg' => '#f5f0eb', 'icon' => '📦', 'msg' => 'Your order status has been updated.'];
@endphp

@section('content')
  <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#D8B99C;text-transform:uppercase;letter-spacing:1px;">Order Update</p>
  <h1 style="margin:0 0 16px;font-size:24px;font-weight:700;color:#160c08;">Your Order Status Changed</h1>
  <p style="margin:0 0 24px;font-size:15px;color:#4a3728;line-height:1.7;">
    Hi {{ $userName }}, here's an update on your SBA Reads order.
  </p>

  {{-- Status badge --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td align="center" style="background:{{ $cfg['bg'] }};border-radius:12px;padding:28px 24px;">
        <p style="margin:0 0 8px;font-size:32px;">{{ $cfg['icon'] }}</p>
        <span style="display:inline-block;background:{{ $cfg['color'] }};color:#fff;font-size:14px;font-weight:700;padding:6px 20px;border-radius:20px;letter-spacing:0.5px;">
          {{ $cfg['label'] }}
        </span>
        <p style="margin:12px 0 0;font-size:14px;color:#4a3728;line-height:1.6;">{{ $cfg['msg'] }}</p>
      </td>
    </tr>
  </table>

  {{-- Order summary --}}
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;border:1px solid #e8ddd6;border-radius:10px;overflow:hidden;">
    <tr>
      <td style="background:#f5f0eb;padding:14px 20px;border-bottom:1px solid #e8ddd6;">
        <p style="margin:0;font-size:13px;font-weight:700;color:#160c08;text-transform:uppercase;letter-spacing:1px;">Order Summary</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;padding:16px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="width:50%;vertical-align:top;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Tracking Number</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:#160c08;font-family:monospace;">{{ $trackingNumber }}</p>
            </td>
            <td style="width:50%;vertical-align:top;padding-bottom:12px;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Total</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:700;color:#160c08;">${{ number_format($totalAmount, 2) }}</p>
            </td>
          </tr>
          <tr>
            <td style="vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Delivery</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#160c08;">
                {{ $deliveryType === 'pickup' ? 'Store Pickup — Lagos' : 'Home Delivery' }}
              </p>
            </td>
            <td style="vertical-align:top;">
              <p style="margin:0;font-size:11px;color:#9e8272;text-transform:uppercase;letter-spacing:1px;">Updated</p>
              <p style="margin:4px 0 0;font-size:14px;font-weight:600;color:#160c08;">{{ now()->format('M d, Y') }}</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  @if($status === 'delivered' || $status === 'completed')
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:24px;">
    <tr>
      <td style="background:#f5f0eb;border-left:3px solid #D8B99C;border-radius:0 8px 8px 0;padding:14px 18px;">
        <p style="margin:0;font-size:13px;color:#6b5448;line-height:1.6;">
          ⭐ Enjoying your books? Leave a review on SBA Reads and help other readers discover great titles!
        </p>
      </td>
    </tr>
  </table>
  @endif

  <p style="margin:0;font-size:14px;color:#9e8272;">
    Thank you for your support,<br/>
    <strong style="color:#160c08;">The SBA Reads Team</strong>
  </p>
@endsection
