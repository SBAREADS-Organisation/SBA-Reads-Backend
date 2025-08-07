// In the success block
$transaction->update([
    'status' => 'succeeded',
    'payment_intent_id' => $transfer->id,
    // ... other fields
]);

// This will automatically trigger Transaction::updated() 
// which calls DashboardCacheService::clearAuthorDashboard()