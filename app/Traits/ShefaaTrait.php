<?php

namespace App\Traits;

use App\Models\Coupon;
use App\Models\Delivery;
use App\Services\FcmService;
use App\Models\Notification;
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

    protected function autoAssignDelivery(FcmService $fcmService, $order, $excludeUserId = null)
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

            $title = __('delivery.traitTitle');
            $message = __('delivery.traitMessage', ['orderId' => $order->id, 'governorate' => __("governorates." . strtolower(str_replace([' ', '-'], '_', $order->governorate)))]);

            Notification::create([
                'user_id' => $deliveryGuy->user_id,
                'related_id' => $order->id,
                'related_type' => 'delivery_guy_assigned',
                'title' => $title,
                'message' => $message
            ]);

            $fcmService->sendFcmNotification(
                $deliveryGuy->user_id,
                $title,
                $message,
                [
                    'related_id' => (string)$order->id,
                    'related_type' => 'delivery_guy_assigned'
                ]
            );

            return true; // نجح التعيين
        }
        return false;
    }

    private function checkAndGenerateLoyaltyCoupon($userId, $pharmacyId)
    {
        $ordersCountFromThisPharmacy = Order::where('user_id', $userId)
            ->where('pharmacy_id', $pharmacyId)
            ->where('order_status', 'delivered')
            ->count();

        if ($ordersCountFromThisPharmacy > 0 && $ordersCountFromThisPharmacy % 2 == 0) {

            $code = 'PH' . $pharmacyId . 'U' . $userId . 'R' . rand(100, 999);

            Coupon::create([
                'user_id' => $userId,
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
