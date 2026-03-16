<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Review;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ReviewController extends Controller
{
    use ShefaaTrait;

    public function addReview(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'pharmacy_id' => 'required|integer|exists:pharmacies,id',
            'rate' => 'nullable|integer|min:1|max:5',
            'comment' => 'nullable|string'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $pharmacy = Pharmacy::find($request->pharmacy_id);

        if (!$pharmacy) {
            return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);
        }

        // التحقق من أن المواطن قد اشترى فعلاً من هذه الصيدلية (لضمان المصداقية)
        $hasOrdered = Order::where('user_id', $user->id)
            ->where('pharmacy_id', $request->pharmacy_id)
            ->where('order_status', 'delivered')
            ->exists();

        if (!$hasOrdered) {
            return $this->ErrorResponse(__('review.should_order'), 403);
        }

        try {
            $result = DB::transaction(function () use ($user, $request, $pharmacy) {
                $newReview = Review::create([
                    'pharmacy_id' => $request->pharmacy_id,
                    'user_id'     => $user->id,
                    'rate'        => $request->rate,
                    'comment'     => $request->comment
                ]);

                $title = __('review.new_rate');
                $message = __('review.new_rate_message', ['name' => $user->username, 'rate' => $request->rate]);

                Notification::create([
                    'user_id'      => $pharmacy->user_id,
                    'related_id'   => $newReview->id,
                    'related_type' => 'new_review',
                    'title'        => $title,
                    'message'      => $message
                ]);

                return [
                    'newReview' => $newReview,
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $pharmacy->user_id,
                $result['title'],
                $result['message'],
                [
                    'related_id'   => (string) $result['newReview']->id,
                    'related_type' => 'new_review'
                ]
            );

            return $this->SuccessResponse($result['newReview'], __('review.review_completed'), 201);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('review.failed_add_review') . $e->getMessage(), 500);
        }
    }

    public function deleteReview(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'review_id' => 'required|integer|exists:reviews,id',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $checkReview = Review::where('id', $request->review_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$checkReview) {
            return $this->ErrorResponse(__('review.review_not_found'), 404);
        }

        $checkReview->delete();
        return $this->SuccessResponse(null, __('review.deleted_success'), 200);
    }
}
