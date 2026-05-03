<?php

namespace App\Traits;

use App\Models\Coupon;
use App\Models\Delivery;
use App\Models\Order;

trait ShefaaTrait
{
    public function SuccessResponse($data = null, $message = null, $code = null)
    {
        return response()->json([
            'data' => $data,
            'message' => $message,
            'code' => $code
        ], $code);
    }

    public function ErrorResponse($message = null, $code = null)
    {
        return response()->json([
            'message' => $message,
            'code' => $code
        ], $code);
    }

    protected function autoAssignDelivery($order, $excludeUserId = null)
    {
        $query = Delivery::where('availability_status', 1)
            ->where('governorate', $order->governorate);

        if ($excludeUserId) {
            $query->where('user_id', '!=', $excludeUserId);
        }

        $deliveryGuy = $query->inRandomOrder()->first();

        if ($deliveryGuy) {
            $order->update([
                'delivery_id' => $deliveryGuy->user_id,
                'delivery_approval_status' => 'assigned',
            ]);

            return true; // نجح التعيين
        }
        return false;
    }

    private function checkAndGenerateLoyaltyCoupon($userId, $pharmacyId)
    {
        $ordersCountFromThisPharmacy = Order::where('user_id', $userId)
            ->where('pharmacy_id', $pharmacyId)
            ->where('order_status', 'delivered')
            ->where('total_price', '>=', 50000)
            ->count();

        if ($ordersCountFromThisPharmacy > 0 && $ordersCountFromThisPharmacy % 2 == 0) {

            $code = 'PH' . $pharmacyId . 'U' . $userId . 'R' . rand(100, 999);

            Coupon::create([
                'user_id' => $userId,
                'pharmacy_id' => $pharmacyId,
                'code' => $code,
                'discount_percentage' => 20,
                'is_used' => false,
                'valid_until' => now()->addMonth(),
            ]);

            return $code;
        }

        return null;
    }
}
