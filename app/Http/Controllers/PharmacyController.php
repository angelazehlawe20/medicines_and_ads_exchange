<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Review;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PharmacyController extends Controller
{
    use ShefaaTrait;

    public function getMyInventory(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();
        if (!$pharmacy) return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);

        $query = Medicine::where('pharmacy_id', $pharmacy->id);

        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $inventory = $query->orderBy('created_at', 'desc')->get();


        return $this->SuccessResponse($inventory, __('pharmacy.inv_fetched'), 200);
    }

    public function getPharmacyReviews()
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);

        $reviews = Review::with('user:id,username')
            ->where('pharmacy_id', $pharmacy->id)
            ->latest()
            ->get();

        if ($reviews->isEmpty()) {
            return $this->SuccessResponse([], __('pharmacy.no_reviews'), 200);
        }

        return $this->SuccessResponse($reviews, __('pharmacy.reviews_fetched'), 200);
    }

    public function getMyOrders()
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);

        $orders = Order::with([
            'orderItems.medicine:id,name,image,price', // جلب بيانات الدواء الأساسية فقط
            'payment:id,order_id,payment_status,payment_method,amount',
            'user:id,username,phone'
        ])
            ->where('pharmacy_id', $pharmacy->id)
            ->whereIn('ph_approval_status', ['pending', 'approved'])
            ->whereNotIn('order_status', ['delivered', 'cancelled'])
            ->latest()
            ->get();

        if ($orders->isEmpty()) {
            return $this->SuccessResponse([], __('pharmacy.havent_orders'));
        }
        return $this->SuccessResponse($orders, __('pharmacy.orders_fetched'), 200);
    }

    public function acceptOrder(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) {
            return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);
        }

        $order = Order::with('user')
            ->where('pharmacy_id', $pharmacy->id)
            ->where('id', $request->order_id)
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('pharmacy.order_not_found'), 404);
        }

        if ($order->ph_approval_status !== 'pending') {
            return $this->ErrorResponse(__('pharmacy.isnt_pending'), 400);
        }

        if ($order->order_status === 'cancelled') {
            return $this->ErrorResponse(__('pharmacy.order_cancelled'), 400);
        }

        try {
            // تحديث حالة الموافقة داخل الترانزاكشن
            $assigned = DB::transaction(function () use ($order) {
                $order->update([
                    'ph_approval_status' => 'approved',
                ]);

                $title = __('pharmacy.title');
                $message = __('pharmacy.message', ['orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->user_id,
                    'related_id' => $order->id,
                    'related_type' => 'accept_order',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $order->user_id,
                $assigned['title'],
                $assigned['message'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'accept_order'
                ]
            );

            $isAssigned = $this->autoAssignDelivery($fcmService, $order);

            if ($isAssigned) {
                $msg = __(
                    'pharmacy.order_approved_assigned',
                    ['governorate' => __("governorates." . strtolower(str_replace([' ', '-'], '_', $order->governorate)))]
                );
            } else {
                $msg = __(
                    'pharmacy.order_approved_not_assigned',
                    ['governorate' => __("governorates." . strtolower(str_replace([' ', '-'], '_', $order->governorate)))]
                );
            }
            return $this->SuccessResponse($order, $msg, 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('pharmacy.approval_failed') . $e->getMessage(), 500);
        }
    }

    public function rejectOrder(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        // ملاحظة: تأكد أن العلاقة مع الصيدلية تستخدم user_id بشكل صحيح
        $order = Order::with(['orderItems', 'user', 'payment'])
            ->where('pharmacy_id', $pharmacy->id)
            ->where('id', $request->order_id)
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('pharmacy.not_found_to_modify'), 404);
        }
        if ($order->ph_approval_status !== 'pending') {
            return $this->ErrorResponse(__('pharmacy.cant_reject', ['ph_approval_status' => __("pharmacy." . $order->ph_approval_status)]), 400);
        }

        try {
            $data = DB::transaction(function () use ($order) {
                // إعادة الأدوية للمخزون
                foreach ($order->orderItems as $item) {
                    Medicine::where('id', $item->medicine_id)
                        ->increment('quantity_available', $item->desired_quantity);
                }


                if ($order->coupon_code) {
                    Coupon::where('code', $order->coupon_code)
                        ->where('user_id', $order->user_id)
                        ->update(['is_used' => false]);
                }

                // تحديث الحالات
                $order->update([
                    'ph_approval_status' => 'rejected',
                ]);

                if ($order->payment) {
                    $order->payment->update(['payment_status' => 'failed']);
                }

                $title = __('pharmacy.reject_title');
                $message = __('pharmacy.reject_message', ['pharmacy_name' => $order->pharmacy->pharmacy_name, 'orderId' => $order->id]);

                Notification::create([
                    'user_id' => $order->user_id,
                    'related_id' => $order->id,
                    'related_ad' => 'reject_order',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $order->user_id,
                $data['title'],
                $data['message'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'reject_order'
                ]
            );

            return $this->SuccessResponse(null, __('pharmacy.rejected_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('pharmacy.failed') . $e->getMessage(), 500);
        }
    }
}
