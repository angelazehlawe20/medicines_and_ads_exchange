<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Notification;
use App\Models\Order;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeliveryController extends Controller
{
    use ShefaaTrait;

    public function acceptDelivery(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $delivery = Delivery::where('user_id', $user->id)->first();

        if (!$delivery || $delivery->availability_status == 0) {
            return $this->ErrorResponse(__('delivery.you_are_unavailable'), 400);
        }

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $order = Order::with(['user', 'pharmacy.user', 'payment'])
            ->where('id', $request->order_id)
            ->where('delivery_id', $delivery->id)
            ->where('delivery_approval_status', 'assigned')
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('delivery.order_not_found'), 404);
        }

        try {
            $data = DB::transaction(function () use ($order, $delivery) {
                // تحديث حالة الطلب والمندوب
                $order->update([
                    'delivery_approval_status' => 'accepted',
                    'order_status' => 'in_process'
                ]);

                $delivery->update(['availability_status' => 0]);

                $userTitle = __('delivery.title_on_way');
                $userMessage = __('delivery.accept_user_message', ['orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'delivery_on_way',
                    'title' => $userTitle,
                    'message' => $userMessage
                ]);

                $pharmacyTitle = __('delivery.pharmacy_title');
                $pharmacyMessage = __('delivery.pharmacy_message', ['name' => $delivery->user->username, 'orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->pharmacy->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'delivery_accepted',
                    'title' => $pharmacyTitle,
                    'message' => $pharmacyMessage
                ]);

                return [
                    'userTitle' => $userTitle,
                    'userMessage' => $userMessage,
                    'pharmacyTitle' => $pharmacyTitle,
                    'pharmacyMessage' => $pharmacyMessage
                ];
            });

            $fcmService->sendFcmNotification(
                $order->user_id,
                $data['userTitle'],
                $data['userMessage'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'delivery_on_way'
                ]
            );

            $fcmService->sendFcmNotification(
                $order->pharmacy->user_id,
                $data['pharmacyTitle'],
                $data['pharmacyMessage'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'accept_delivery'
                ]
            );

            return $this->SuccessResponse($order, __('delivery.order_accepted'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('delivery.failed_to_accept') . $e->getMessage(), 500);
        }
    }

    public function rejectDelivery(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }
        $delivery = Delivery::where('user_id', $user->id)->first();

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $order = Order::where('id', $request->order_id)
            ->where('delivery_id', $delivery->id)
            ->where('delivery_approval_status', 'assigned')
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('delivery.order_cant_rejected'), 404);
        }

        $delivery = Delivery::where('user_id', $user->id)->first();

        try {
            DB::transaction(function () use ($order, $delivery) {
                $order->update([
                    'delivery_id' => null,
                    'delivery_approval_status' => 'pending',
                ]);
                $delivery->update(['availability_status' => 1]);
            });

            $this->autoAssignDelivery($fcmService, $order, $user->id);

            return $this->SuccessResponse(null, __('delivery.order_rejected_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('delivery.error_rejection') . $e->getMessage(), 500);
        }
    }

    public function pickUpOrder(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }
        $delivery = Delivery::where('user_id', $user->id)->first();

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $order = Order::with(['user', 'pharmacy.user', 'payment'])
            ->where('id', $request->order_id)
            ->where('delivery_id', $delivery->id)
            ->where('delivery_approval_status', 'accepted')
            ->where('order_status', 'in_process')
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('delivery.not_ready_pickup'), 400);
        }

        try {
            $data = DB::transaction(function () use ($order) {
                $order->update(['order_status' => 'picked_up']);

                $uTitle = __('delivery.user_title');
                $uMessage = __('delivery.user_message', ['orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'order_picked_up',
                    'title' => $uTitle,
                    'message' => $uMessage
                ]);

                $pTitle = __('delivery.pharmacy_left_title');

                $pMessage = __('delivery.pharmacy_left_message', ['orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->pharmacy_id,
                    'related_id' => $order->id,
                    'related_type' => 'order_out_for_delivery',
                    'title' => $pTitle,
                    'message' => $pMessage
                ]);

                return [
                    'uTitle' => $uTitle,
                    'uMessage' => $uMessage,
                    'pTitle' => $pTitle,
                    'pMessage' => $pMessage
                ];
            });

            $fcmService->sendFcmNotification(
                $order->user_id,
                $data['uTitle'],
                $data['uMessage'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'order_picked_up'
                ]
            );

            $fcmService->sendFcmNotification(
                $order->pharmacy->user_id,
                $data['pTitle'],
                $data['pMessage'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'order_out_for_delivery'
                ]
            );

            return $this->SuccessResponse($order, __('delivery.order_pickedup'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('delivery.failed_pickup') . $e->getMessage(), 500);
        }
    }

    public function deliverOrder(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();
        if (!$user) return $this->ErrorResponse(__('admin.unauthorized'), 401);

        $delivery = Delivery::where('user_id', $user->id)->first();

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) return $this->ErrorResponse($validation->errors(), 422);

        $order = Order::with(['user', 'pharmacy.user', 'payment'])
            ->where('id', $request->order_id)
            ->where('delivery_id', $delivery->id)
            ->where('order_status', 'picked_up')
            ->first();

        if (!$order) return $this->ErrorResponse(__('delivery.not_pickedup'), 400);

        try {
            $result = DB::transaction(function () use ($order, $user) {
                $order->update(['order_status' => 'delivered']);
                if ($order->payment) {
                    $order->payment->update(['payment_status' => 'paid']);
                }
                Delivery::where('user_id', $user->id)->update(['availability_status' => 1]);

                $couponCode = $this->checkAndGenerateLoyaltyCoupon($order->user_id, $order->pharmacy_id);
                $hasCoupon = false;
                $couponTitle = $couponMessage = null;

                if ($couponCode) {
                    $hasCoupon = true;
                    $couponTitle = __('coupon.loyalty_title');
                    $couponMessage = __('coupon.loyalty_message', ['code' => $couponCode]);

                    Notification::create([
                        'user_id' => $order->user_id,
                        'related_id' => $order->id,
                        'related_type' => 'loyalty_coupon',
                        'title' => $couponTitle,
                        'message' => $couponMessage
                    ]);
                }

                $userTitle = __('delivery.order_delivered');
                $userMessage = __('delivery.done', ['orderId' => $order->id]);
                Notification::create([
                    'user_id' => $order->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'order_delivered',
                    'title' => $userTitle,
                    'message' => $userMessage
                ]);

                $pharmacyTitle = __('delivery.order_completed');
                $pharmacyMessage = __('delivery.order_completed_message', ['orderId' => $order->id]);
                Notification::create([
                    'user_id' => $order->pharmacy->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'order_completed',
                    'title' => $pharmacyTitle,
                    'message' => $pharmacyMessage
                ]);

                return [
                    'hasCoupon'      => $hasCoupon,
                    'couponTitle'    => $couponTitle,
                    'couponMessage'  => $couponMessage,
                    'userTitle'      => $userTitle,
                    'userMessage'    => $userMessage,
                    'pharmacyTitle'  => $pharmacyTitle,
                    'pharmacyMessage' => $pharmacyMessage,
                ];
            });

            $fcmService->sendFcmNotification($order->user_id, $result['userTitle'], $result['userMessage'], [
                'related_id' => (string)$order->id,
                'related_type' => 'order_delivered'
            ]);

            $fcmService->sendFcmNotification($order->pharmacy->user_id, $result['pharmacyTitle'], $result['pharmacyMessage'], [
                'related_id' => (string)$order->id,
                'related_type' => 'order_completed'
            ]);

            // إشعار الكوبون (يرسل فقط في حال وجوده)
            if ($result['hasCoupon']) {
                $fcmService->sendFcmNotification($order->user_id, $result['couponTitle'], $result['couponMessage'], [
                    'related_id'   => (string)$order->id,
                    'related_type' => 'loyalty_coupon',
                ]);
            }

            return $this->SuccessResponse(null, __('delivery.order_completed_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('delivery.failed_delivered') . $e->getMessage(), 500);
        }
    }

    public function updateAvailabilityStatus(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'availability_status' => 'required|boolean'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $delivery = Delivery::where('user_id', $user->id)->first();

        if ($request->availability_status == 0) {
            $hasActive = Order::where('delivery_id', $delivery->id)
                ->whereIn('order_status', ['in_process', 'picked_up'])
                ->exists();
            if ($hasActive) {
                return $this->ErrorResponse(__('delivery.error_finish_first'), 400);
            }
        }

        $delivery->update(['availability_status' => $request->availability_status]);
        return $this->SuccessResponse($delivery, __('delivery.status_updated'), 200);
    }

    public function getMyAssignedOrders()
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $delivery = Delivery::where('user_id', $user->id)->first();

        $myOrders = Order::with(['pharmacy.user', 'orderItems.medicine', 'payment'])
            ->where('delivery_id', $delivery->id)
            ->where('delivery_approval_status', 'assigned')
            ->where('order_status', 'in_process')
            ->latest()
            ->get();

        return $this->SuccessResponse($myOrders, __('delivery.assigned_orders'), 200);
    }

    public function getOrderDetails(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $delivery = Delivery::where('user_id', $user->id)->first();

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $order = Order::with([
            'orderItems.medicine:id,name,price',
            'pharmacy.user:id,username,phone',
            'payment',
            'user:id,username,phone'
        ])
            ->where('id', $request->order_id)
            ->where('delivery_id', $delivery->id)
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('delivery.unable_access'), 403);
        }
        return $this->SuccessResponse($order, __('delivery.order_details'), 200);
    }
}
