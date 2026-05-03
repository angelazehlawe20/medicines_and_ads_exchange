<?php

namespace App\Http\Controllers;

use App\Models\ExchangeAd;
use App\Models\Notification;
use App\Models\Specialist;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SpecialistController extends Controller
{
    use ShefaaTrait;

    public function getMyPendingAds()
    {
        $user = auth()->user();

        if (! $user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $specialist = Specialist::where('user_id', $user->id)->first();

        if (! $specialist) {
            return $this->ErrorResponse(__('specialist.specialist_not_found'), 404);
        }

        $pendingAds = ExchangeAd::with('user:id,username,phone')
            ->where('security_check_status', null)
            ->where('specialist_id', $specialist->id)
            ->latest()
            ->get();

        if ($pendingAds->isEmpty()) {
            return $this->SuccessResponse([], __('specialist.no_pending_ads'), 200);
        }

        return $this->SuccessResponse($pendingAds, __('specialist.pending_ads'), 200);
    }

    public function verifyAd(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        $isRoleSpecialist = ($user->role === 'specialist');
        $isPharmacistSpecialist = ($user->role === 'pharmacy' && $user->pharmacy && $user->pharmacy->is_specialist === true);

        if (! $isRoleSpecialist && ! $isPharmacistSpecialist) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'ad_id' => 'required|integer|exists:exchange_ads,id',
            'status' => 'required|boolean',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $assignedId = $isPharmacistSpecialist ? $user->pharmacy->id : $user->specialist->id;

        $ad = ExchangeAd::where('id', $request->ad_id)
            ->where('specialist_id', $assignedId)
            ->first();

        if (! $ad) {
            return $this->ErrorResponse(__('specialist.notFound_notAssigned'), 404);
        }

        if ($ad->security_check_status !== null) {
            return $this->ErrorResponse(__('specialist.has_processed'), 400);
        }

        try {
            $data = DB::transaction(function () use ($ad, $request) {
                $ad->update([
                    'security_check_status' => $request->status ? 1 : 0,
                    'notes' => $request->notes ?? ($request->status ? __('specialist.verify') : __('specialist.rejected')),
                    'is_showing' => $request->status ? 1 : 0,
                ]);

                $title = $request->status ? __('specialist.ad_approved') : __('specialist.ad_rejected');
                $message = $request->status
                    ? __('specialist.ad_available', ['adId' => $ad->id])
                    : __('specialist.apologize', ['adId' => $ad->id]);

                Notification::create([
                    'user_id' => $ad->user_id,
                    'related_id' => $ad->id,
                    'related_type' => 'specialist_reply',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'ad' => $ad,
                    'title' => $title,
                    'message' => $message
                ];
            });

            $fcmService->sendFcmNotification(
                $ad->user_id,
                $data['title'],
                $data['message'],
                [
                    'related_id' => (string) $ad->id,
                    'related_type' => 'specialist_reply'
                ]
            );

            $statusMessage = $request->status ? __('specialist.verify') : __('specialist.rejected');

            return $this->SuccessResponse($ad, __('specialist.status_updated_success', ['statusMessage' => $statusMessage]), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('specialist.error_processing') . $e->getMessage(), 500);
        }
    }

    public function markAdAsTaken(Request $request)
    {
        $user = auth()->user();

        if (! $user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $specialist = Specialist::where('user_id', $user->id)->first();

        if (! $specialist) {
            return $this->ErrorResponse(__('specialist.specialist_not_found'), 404);
        }

        $validation = Validator::make($request->all(), [
            'ad_id' => 'required|integer|exists:exchange_ads,id',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $ad = ExchangeAd::where('id', $request->ad_id)
            ->where('specialist_id', $specialist->id)
            ->where('security_check_status', true)
            ->first();

        if (! $ad) {
            return $this->ErrorResponse(__('specialist.ad_notFound_notVerified'), 404);
        }

        if ($ad->is_showing === 0) {
            return $this->ErrorResponse(__('specialist.already_taken'), 400);
        }

        $ad->update([
            'is_showing' => 0,
        ]);

        return $this->SuccessResponse($ad, __('specialist.has_been_marked'), 200);
    }

    public function getMyActionHistory()
    {
        $user = auth()->user();

        if (! $user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $specialist = Specialist::where('user_id', $user->id)->first();

        $history = ExchangeAd::with('user:id,username')
            ->where('specialist_id', $specialist->id)
            ->whereNotNull('security_check_status')
            ->latest()
            ->get();

        return $this->SuccessResponse($history, __('specialist.specialist_action_history'), 200);
    }
}
