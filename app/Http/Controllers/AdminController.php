<?php

namespace App\Http\Controllers;

use App\Models\ExchangeAd;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    use ShefaaTrait;

    public function getDashboardStats()
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $stats = [
            'users_overview' => [
                'total' => User::count(),
                'citizens' => User::where('role', 'citizen')->count(),
                'pharmacies' => User::where('role', 'pharmacy')->count(),
                'specialists' => User::where('role', 'specialist')->count(),
                'delivery' => User::where('role', 'delivery')->count(),
                'inactive_users' => User::where('account_status', 0)->count(),
            ],
            'ads_stats' => [
                'total_ads' => ExchangeAd::count(),
                'published' => ExchangeAd::where('is_showing', 1)->count(),
                'pending_verification' => ExchangeAd::whereNull('security_check_status')->count(),
                'completed_exchanges' => ExchangeAd::where('is_showing', 0)->where('security_check_status', 1)->count(),
                'rejected_ads' => ExchangeAd::where('security_check_status', 0)->count(), // إعلانات مرفوضة لأسباب طبية
            ],
            'orders_stats' => [
                'total_orders' => Order::count(),
                'pending_orders' => Order::where('order_status', 'pending')->count(),
                'processing' => Order::whereIn('order_status', ['accepted', 'picked_up'])->count(),
                'completed_orders' => Order::where('order_status', 'delivered')->count(),
                'canceled_orders' => Order::where('order_status', 'canceled')->count(),
            ],
            'financial_overview' => [
                'total_paid_orders' => Payment::where('payment_status', 'paid')->count(),
                // تجميع الإيرادات حسب العملة (لا تظهره كمجموع واحد لضمان الدقة)
                'revenue_by_currency' => Payment::where('payment_status', 'paid')
                    ->select('currency', DB::raw('SUM(amount) as total'))
                    ->groupBy('currency')
                    ->get(),
            ],
            'activity_by_governorate' => User::select('governorate', DB::raw('count(*) as count'))
                ->groupBy('governorate')
                ->orderBy('count', 'desc')
                ->get()
                ->map(function ($item) {
                    $govKey = strtolower(str_replace([' ', '-'], '_', $item->governorate));
                    return [
                        'governorate' => __("governorates.{$govKey}"),
                        'count' => $item->count
                    ];
                }),
        ];

        return $this->SuccessResponse($stats, __('admin.dashboard'), 200);
    }

    public function getUsersByRole(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'role' => 'required|in:citizen,pharmacy,specialist,delivery,admin'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $role = $request->role;

        // جلب المستخدمين مع فحص وجود العلاقة
        $query = User::where('role', $role);

        // تأكد أن لديك علاقة في مودل User بهذا الاسم، وإلا قم بإزالة هذا الشرط
        if (method_exists(User::class, $role)) {
            $query->with($role);
        }

        $users = $query->latest()->get();

        if ($users->isEmpty()) {
            return $this->SuccessResponse([], __('admin.no_users_found', ['role' => $role]), 200);
        }

        return $this->SuccessResponse($users, __('admin.users_fetched', ['role' => $role]), 200);
    }

    public function toggleUserStatus(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'status'  => 'required|boolean'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $user = User::find($request->user_id);

        if ($user->id === $admin->id) {
            return $this->ErrorResponse(__('admin.error_my_status'), 403);
        }

        // تحويل القيمة إلى Boolean صريح للمقارنة
        $newStatus = filter_var($request->status, FILTER_VALIDATE_BOOLEAN);

        if ($user->account_status == $newStatus) {
            $currentStatusText = $newStatus ? 'admin.active' : 'admin.suspended';
            return $this->ErrorResponse(__('admin.status_already_set', ['status' => $currentStatusText]), 400);
        }

        $user->update(['account_status' => $request->status]);

        $msg = $request->status ? 'admin.activated' : 'admin.suspended';
        return $this->SuccessResponse($user, __('admin.status_updated', ['status' => $msg]), 200);
    }

    public function manageExchangeAds(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'status'  => 'nullable|in:0,1'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $query = ExchangeAd::with([
            'user:id,username',
            'specialist:id,pharmacy_name'
        ]);

        if ($request->filled('status')) {
            $query->where('security_check_status', $request->status);
        }

        $ads = $query->latest()->get();
        return $this->SuccessResponse($ads, __('admin.get_ads'), 200);
    }

    public function searchUser(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'search' => 'required|string'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $search = $request->search;

        $users = User::where('username', 'like', "%$search%")
            ->orWhere('phone', 'like', "%$search%")
            ->limit(10)
            ->get();

        if ($users->isEmpty()) {
            return $this->SuccessResponse([], __('admin.no_results', ['query' => $search]), 200);
        }
        $count = $users->count();
        return $this->SuccessResponse($users, __('admin.search_results', ['count' => $count]), 200);
    }

    public function searchMedicineInAds(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'search' => 'required|string'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $search = $request->search;

        $ads = ExchangeAd::where('medicine_name', 'like', "%$search%")
            ->limit(10)
            ->get();

        if ($ads->isEmpty()) {
            return $this->SuccessResponse([], __('admin.no_results', ['query' => $search]), 200);
        }

        $count = $ads->count();
        return $this->SuccessResponse($ads, __('admin.search_results', ['count' => $count]), 200);
    }

    public function addUser(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        if ($request->has('governorate')) {
            // تحويل "homs" إلى "Homs" أو "rif dimashq" إلى "Rif Dimashq"
            $request->merge([
                'governorate' => ucwords(strtolower($request->governorate))
            ]);
        }

        $rules = [
            'username' => 'required|string|unique:users,username|max:255',
            'email' => 'nullable|string|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'phone' => 'nullable|string|unique:users,phone',
            'role' => 'required|in:admin,citizen,pharmacy,specialist,delivery',
            'governorate' => 'required|in:Damascus,Aleppo,Homs,Hama,Lattakia,Tartous,Daraa,Deir ez-Zor,Hasakah,Raqqa,Suwayda,Quneitra,Rif Dimashq',
        ];

        if ($request->role === 'citizen') {
            $rules['address'] = 'required|string';
        }
        if ($request->role === 'pharmacy') {
            $rules['pharmacy_name'] = 'required|string';
        }
        if ($request->role === 'specialist') {
            $rules['pharmacy_name'] = 'required|string';
            $rules['pharmacy_address'] = 'required|string';
        }

        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) return $this->ErrorResponse($validation->errors(), 422);

        try {
            return DB::transaction(function () use ($request) {
                $governorate = strtolower(str_replace([' ', '-'], '_', $request->governorate));
                $user = User::create([
                    'username' => $request->username,
                    'password' => bcrypt($request->password),
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'role' => $request->role,
                    'governorate' => $governorate,
                    'account_status' => 1
                ]);

                if ($request->role === 'citizen') {
                    $user->citizen()->create(['address' => $request->address]);
                } elseif ($request->role === 'pharmacy') {
                    $user->pharmacy()->create(['pharmacy_name' => $request->pharmacy_name, 'governorate' => $request->governorate]);
                } elseif ($request->role === 'specialist') {
                    $user->specialist()->create(['pharmacy_name' => $request->pharmacy_name, 'pharmacy_address' => $request->pharmacy_address, 'governorate' => $request->governorate]);
                } elseif ($request->role === 'delivery') {
                    $user->delivery()->create(['governorate' => $request->governorate, 'availability_status' => 0]);
                } elseif ($request->role === 'admin') {
                    $user->admin()->create();
                }

                // إضافة اسم المحافظة المترجم للرد فقط دون التأثير على قاعدة البيانات
                // نستخدم str_replace لتبديل الفراغات بـ _ لتطابق مفاتيح ملف الترجمة (مثل Deir ez-Zor تصبح deir_ez_zor)
                $govKey = strtolower(str_replace([' ', '-'], '_', $user->governorate));
                $user->translated_governorate = __("governorates.{$govKey}");

                return $this->SuccessResponse($user->load($request->role), __('admin.add_user'), 201);
            });
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('admin.creation_failed') . $e->getMessage(), 500);
        }
    }

    public function deleteUser(Request $request)
    {
        $admin = auth()->user();
        if (!$admin) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id'
        ]);
        if ($validator->fails()) {
            return $this->ErrorResponse($validator->errors(), 422);
        }

        $user = User::find($request->user_id);

        if ($user->id === $admin->id) {
            return $this->ErrorResponse(__('admin.delete_own_account'), 403);
        }

        $user->delete();
        return $this->SuccessResponse(null, __('admin.delete_success'), 200);
    }
}
