<?php

namespace App\Http\Controllers;

use App\Models\ExchangeAd;
use App\Models\Notification;
use App\Models\Specialist;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ExchangeAdController extends Controller
{
    use ShefaaTrait;

    public function createAd(FcmService $fcmService, Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'medicine_name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'price' => 'required_if:ad_type,sale|nullable|numeric|min:0',
            'ad_type' => 'required|in:donation,sale',
            'notes' => 'nullable|string',
            'governorate' => 'required|in:Damascus,Aleppo,Homs,Hama,Lattakia,Tartous,Daraa,Deir ez-Zor,Hasakah,Raqqa,Suwayda,Quneitra,Rif Dimashq',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $specialist = Specialist::where('governorate', $request->governorate)
            ->inRandomOrder()
            ->first();

        if (!$specialist) {
            return $this->ErrorResponse(__('ad.no_specialist'), 404);
        }

        $finalPrice = ($request->ad_type === 'donation') ? 0 : $request->price;

        $imagePath = null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('exchangeAds', 'public');
            $imagePath = asset('storage/' . $path);
        }

        try {
            $result = DB::transaction(function () use ($user, $specialist, $request, $imagePath, $finalPrice) {
                $ad = ExchangeAd::create([
                    'user_id' => $user->id,
                    'specialist_id' => $specialist->id,
                    'governorate' => $request->governorate,
                    'medicine_name' => $request->medicine_name,
                    'image' => $imagePath,
                    'price' => $finalPrice,
                    'ad_type' => $request->ad_type,
                    'security_check_status' => null,
                    'is_showing' => null,
                    'notes' => $request->notes
                ]);

                $title = __('ad.title');
                $message = __('ad.message', ['name' => $user->username, 'medicine_name' => $request->medicine_name]);

                Notification::create([
                    'user_id' => $specialist->user_id,
                    'related_id' => $ad->id,
                    'related_ad' => 'ad_verification',
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
                $specialist->user_id,
                $result['title'],
                $result['message'],
                [
                    'related_id' => (string) $result['ad']->id,
                    'related_type' => 'ad_verification'
                ]
            );
            return $this->SuccessResponse(
                $result['ad'],
                __('ad.ad_submitted'),
                201
            );
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('ad.failed_submitted') . $e->getMessage(), 500);
        }
    }

    public function getAllMyAds()
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $myAds = ExchangeAd::with(['specialist.user'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        if ($myAds->isEmpty()) {
            return $this->SuccessResponse([], __('ad.no_ads'), 200);
        }

        $formattedAds = $myAds->map(function ($ad) {
            return [
                'id' => $ad->id,
                'medicine_name' => $ad->medicine_name,
                'image' => $ad->image,
                'price' => $ad->price,
                'ad_type' => $ad->ad_type,
                'status' => is_null($ad->security_check_status) ? __('ad.pending') : ($ad->security_check_status ? __('ad.verified') : __('ad.rejected')),
                'availability' => $ad->is_showing === 1 ? __('ad.available') : __('ad.taken_or_hidden'),
                'specialist_at' => $ad->specialist->pharmacy_name ?? $ad->specialist->user->username,
                'location' => $ad->specialist->pharmacy_address ?? $ad->governorate,
                'created_at' => $ad->created_at->diffForHumans(),
            ];
        });

        return $this->SuccessResponse($formattedAds, __('ad.ads_fetched'), 200);
    }

    public function getAllConfirmAds(Request $request)
    {
        $query = ExchangeAd::with(['specialist.user:id,username'])
            ->where('security_check_status', true)
            ->where('is_showing', 1)
            ->latest();

        // عرض إعلانات محافظة المستخدم إذا لم يحدد غيرها
        $governorate = $request->governorate ?? (auth()->user() ? auth()->user()->governorate : null);

        if ($governorate) {
            $query->where('governorate', $governorate);
        }

        if ($request->filled('medicine_name')) {
            $query->where('medicine_name', 'like', '%' . $request->medicine_name . '%');
        }

        if ($request->filled('ad_type')) {
            $query->where('ad_type', $request->ad_type);
        }

        $ads = $query->get();

        $govKey = $governorate ? strtolower(str_replace([' ', '-'], '_', $governorate)) : null;
        $locationLabel = $govKey ? __("governorates.{$govKey}") : __('citizen.your_area');

        if ($ads->isEmpty()) {
            return $this->SuccessResponse([], __('ad.no_ads_in_location', ['location' => $locationLabel]), 200);
        }

        $formattedAds = $ads->map(function ($ad) {
            return [
                'id' => $ad->id,
                'medicine_name' => $ad->medicine_name,
                'image' => $ad->image,
                'price' => $ad->price == 0 ? __('ad.free_donation') : $ad->price,
                'ad_type' => $ad->ad_type,
                'governorate' => $ad->governorate,
                'notes' => $ad->notes,
                'verification'  => __('ad.verified_by_specialist'),
                'collect_from'  => $ad->specialist->pharmacy_name ?? $ad->specialist->user->username,
                'address'       => $ad->specialist->pharmacy_address ?? $ad->specialist->governorate,
                'availability' => $ad->is_showing === 1 ? __('ad.available') : __('ad.taken'),
                'posted_at' => $ad->created_at->diffForHumans(),
            ];
        });

        return $this->SuccessResponse($formattedAds, __('ad.confirmed_ads'), 200);
    }

    public function deletePendingAd(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'ad_id' => 'required|integer|exists:exchange_ads,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $ad = ExchangeAd::where('id', $request->ad_id)
            ->where('user_id', $user->id)
            ->first();

        if (!$ad) {
            return $this->ErrorResponse(__('ad.ad_not_found'), 404);
        }

        if ($ad->security_check_status !== null) {
            return $this->ErrorResponse(__('ad.cant_delete'), 400);
        }

        if ($ad->image) {
            $path = str_replace(asset('storage/'), '', $ad->image);
            Storage::disk('public')->delete($path);
        }

        $ad->delete();
        return $this->SuccessResponse(null, __('ad.deleted_success'), 200);
    }
}
