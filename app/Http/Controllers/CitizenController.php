<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Order;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CitizenController extends Controller
{
    use ShefaaTrait;

    public function getAllMedicines(Request $request)
    {
        $user = auth()->user();

        $query = Medicine::with(['pharmacy.user'])
            ->where('expiration_date', '>', now()->toDateString())
            ->where('quantity_available', '>', 0);

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $targetGovernorate = $request->governorate ?? ($user ? $user->governorate : null);

        if ($targetGovernorate) {
            $formattedGov = ucwords(strtolower(str_replace('_', ' ', $targetGovernorate)));
            $query->whereHas('pharmacy', function ($q) use ($formattedGov) {
                $q->where('governorate', $formattedGov);
            });
        }

        if ($request->has('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        if ($request->has('pharmacy_id')) {
            $query->where('pharmacy_id', $request->pharmacy_id);
        }

        $medicines = $query->select('id', 'name', 'price', 'image', 'category', 'requires_prescription', 'pharmacy_id')
            ->orderBy('expiration_date', 'asc')
            ->get();

        $categoryLabel = $request->category === 'cosmetic' ? __('citizen.cosmetics') : __('citizen.medicines');

        if ($medicines->isEmpty()) {
            $location = $targetGovernorate
                ? __('governorates.' . strtolower(str_replace([' ', '-'], '_', $targetGovernorate)))
                : __('citizen.your_area');

            return $this->SuccessResponse([], __('citizen.no_medicines', ['categoryLabel' => $categoryLabel, 'location' => $location]), 200);
        }

        return $this->SuccessResponse($medicines, __('citizen.get_medicines', ['categoryLabel' => $categoryLabel]), 200);
    }

    public function createOrderForPharmacist(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        if ($request->has('governorate')) {
            $request->merge(['governorate' => ucwords(strtolower($request->governorate))]);
        }

        $validation = Validator::make($request->all(), [
            'pharmacy_id' => 'required|integer|exists:pharmacies,id',
            'customer_name' => 'required|string|max:255',
            'phone_number' => 'required|string',
            'address' => 'required|string',
            'governorate' => 'required|in:Damascus,Aleppo,Homs,Hama,Lattakia,Tartous,Daraa,Deir ez-Zor,Hasakah,Raqqa,Suwayda,Quneitra,Rif Dimashq',
            'payment_method' => 'required|in:electronic,cash',
            'coupon_code' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.medicine_id' => 'required|integer|exists:medicines,id',
            'items.*.desired_quantity' => 'required|integer|min:1',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $coupon = null;
        if ($request->filled('coupon_code')) {
            $coupon = Coupon::where('code', $request->coupon_code)
                ->where('user_id', $user->id)
                ->where('pharmacy_id', $request->pharmacy_id)
                ->where('is_used', false)
                ->where('valid_until', '>', now())
                ->first();

            if (!$coupon) {
                return $this->ErrorResponse(__('citizen.invalid_coupon'), 422);
            }
        }

        try {
            $data = DB::transaction(function () use ($user, $request, $coupon) {
                $cosmeticsSubtotal = 0;
                $medicinesSubtotal = 0;
                $itemsToCreate = [];
                $pharmacyUserId = null;

                foreach ($request->items as $itemData) {
                    $medicine = Medicine::lockForUpdate()->find($itemData['medicine_id']);
                    $pharmacyUserId = $medicine->pharmacy->user_id;

                    if ($medicine->requires_prescription) {
                        throw new \Exception(__('citizen.prescription_required', ['name' => $medicine->name]));
                    }

                    if ($medicine->quantity_available < $itemData['desired_quantity']) {
                        throw new \Exception(__('citizen.quantity_unavailable', ['count' => $medicine->quantity_available]));
                    }

                    $itemTotalPrice = $medicine->price * $itemData['desired_quantity'];
                    if ($medicine->category === 'cosmetic') {
                        $cosmeticsSubtotal += $itemTotalPrice;
                    } else {
                        $medicinesSubtotal += $itemTotalPrice;
                    }

                    $itemsToCreate[] = [
                        'medicine_id' => $medicine->id,
                        'desired_quantity' => $itemData['desired_quantity'],
                        'total_price' => $itemTotalPrice,
                        'medicine_model' => $medicine
                    ];
                }

                $discountValue = 0;
                if ($coupon && $cosmeticsSubtotal > 0) {
                    $discountValue = ($cosmeticsSubtotal * $coupon->discount_percentage / 100);
                }

                $finalOrderTotal = ($cosmeticsSubtotal + $medicinesSubtotal) - $discountValue;

                $newOrder = Order::create([
                    'user_id' => $user->id,
                    'pharmacy_id' => $request->pharmacy_id,
                    'customer_name' => $request->customer_name,
                    'phone_number' => $request->phone_number,
                    'address' => $request->address,
                    'governorate' => $request->governorate,
                    'coupon_code' => $request->coupon_code,
                    'total_price' => $finalOrderTotal,
                    'ph_approval_status' => 'pending',
                    'order_status' => 'pending',
                    'delivery_approval_status' => 'pending'
                ]);

                foreach ($itemsToCreate as $item) {
                    $newOrder->orderItems()->create([
                        'medicine_id' => $item['medicine_id'],
                        'desired_quantity' => $item['desired_quantity'],
                        'total_price' => $item['total_price']
                    ]);
                    $item['medicine_model']->decrement('quantity_available', $item['desired_quantity']);
                }

                $payment = $newOrder->payment()->create([
                    'payment_method' => $request->payment_method,
                    'amount' => $finalOrderTotal,
                    'payment_status' => 'pending'
                ]);

                if ($coupon) {
                    $coupon->update(['is_used' => true]);
                }

                $title = __('citizen.order_title');
                $message = __('citizen.order_message', ['name' => $request->customer_name]);

                Notification::create([
                    'user_id' => $pharmacyUserId,
                    'related_id' => $newOrder->id,
                    'related_type' => 'new_order',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'order' => $newOrder->load('orderItems.medicine'),
                    'payment_details' => $payment,
                    'pharmacy_user_id' => $pharmacyUserId,
                    'discount_amount' => $discountValue,
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $data['pharmacy_user_id'],
                $data['title'],
                $data['message'],
                [
                    'related_id' => (string) $data['order']->id,
                    'related_type' => 'new_order'
                ]
            );

            return $this->SuccessResponse([
                'order' => $data['order'],
                'payment_details' => $data['payment_details'],
                'savings' => $data['discount_amount']
            ], __('citizen.create_order_success'), 201);

        } catch (\Exception $e) {
            return $this->ErrorResponse(__('citizen.process_failed') . $e->getMessage(), 500);
        }
    }

    public function cancelOrder(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'order_id' => 'required|integer|exists:orders,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors()->first(), 422);
        }

        $order = Order::with(['orderItems', 'payment', 'pharmacy'])
            ->where('id', $request->order_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return $this->ErrorResponse(__('citizen.order_not_found'), 404);
        }

        if ($order->ph_approval_status !== 'pending' || $order->order_status !== 'pending') {
            return $this->ErrorResponse(__('citizen.cancellation_failed'), 400);
        }

        try {
            $data = DB::transaction(function () use ($order, $user) {
                foreach ($order->orderItems as $item) {
                    Medicine::where('id', $item->medicine_id)
                        ->increment('quantity_available', $item->desired_quantity);
                }

                $order->update(['order_status' => 'cancelled']);

                if ($order->coupon_code) {
                    Coupon::where('code', $order->coupon_code)
                        ->where('user_id', $user->id)
                        ->where('pharmacy_id', $order->pharmacy_id)
                        ->update(['is_used' => false]);
                }

                if ($order->payment) {
                    $order->payment->update(['payment_status' => 'failed']);
                }

                $title = __('citizen.cancel_title', ['name' => $user->username]);
                $message = __('citizen.cancel_message', ['name' => $user->username, 'orderId' => $order->id]);
                $pharmacistUserId = $order->pharmacy->user_id;

                Notification::create([
                    'user_id' => $pharmacistUserId,
                    'related_id' => $order->id,
                    'related_type' => 'cancellation_order',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'pharmacy_id' => $pharmacistUserId,
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $data['pharmacy_id'],
                $data['title'],
                $data['message'],
                [
                    'related_id' => (string) $order->id,
                    'related_type' => 'cancellation_order'
                ]
            );

            return $this->SuccessResponse(null, __('citizen.order_cancelled_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('citizen.error_order_cancellation'), 500);
        }
    }

    public function getMyOrderHistory(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'status' => 'nullable|string|in:pending,in_process,picked_up,delivered,cancelled'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $query = Order::with(['orderItems.medicine', 'pharmacy.user', 'payment'])
            ->where('user_id', $user->id)
            ->latest();

        if ($request->filled('status')) {
            $query->where('order_status', $request->status);
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            return $this->SuccessResponse([], __('citizen.havent_orders'), 200);
        }

        $formattedHistory = [
            'active_orders' => $orders->whereIn('order_status', ['pending', 'in_process', 'picked_up'])->values(),
            'past_orders'   => $orders->whereIn('order_status', ['delivered', 'cancelled'])->values(),
        ];

        return $this->SuccessResponse($formattedHistory, __('citizen.order_retrieved'), 200);
    }

    public function getMyCoupons()
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $coupons = Coupon::with(['pharmacy' => function ($query) {
            $query->select('id', 'pharmacy_name');
        }])
            ->where('user_id', $user->id)
            ->where('is_used', false)
            ->where('valid_until', '>', now())
            ->orderBy('valid_until', 'asc')
            ->get();

        if ($coupons->isEmpty()) {
            return $this->SuccessResponse([], __('citizen.no_coupons'), 200);
        }

        $coupons->map(function ($coupon) {
            $coupon->days_left = now()->diffInDays($coupon->valid_until, false);
            return $coupon;
        });

        return $this->SuccessResponse($coupons, __('citizen.coupons_retrieved'), 200);
    }

    public function toggleFavorite(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'medicine_id' => 'required|integer|exists:medicines,id',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $status = $user->favorites()->toggle($request->medicine_id);

        $message = count($status['attached']) > 0
            ? __('citizen.favorite_added')
            : __('citizen.favorite_removed');

        return $this->SuccessResponse(null, $message, 200);
    }

    public function getMyFavorites()
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $favorites = $user->favorites()
            ->with('pharmacy:id,pharmacy_name')
            ->select('medicines.id', 'name', 'price', 'image', 'category', 'requires_prescription')
            ->latest()
            ->get();

        if ($favorites->isEmpty()) {
            return $this->SuccessResponse([], __('citizen.no_favorites'), 200);
        }

        return $this->SuccessResponse($favorites, __('citizen.favorites_fetched'), 200);
    }
}
