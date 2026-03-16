<?php

namespace App\Services;


use Google\Client as GoogleClient;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    protected string $projectId;

    // يتم استدعاؤه تلقائياً عند إنشاء نسخة من هذه الخدمة
    public function __construct()
    {
        // جلب معرف مشروع فايربيز من ملف الإعدادات أو ملف الـ .env
        $this->projectId = config('services.firebase.project_id') ?: env('FIREBASE_PROJECT_ID');
    }

    /**
     * Send notification via FCM HTTP v1
     * @param int $userId
     * @param string $title
     * @param string $body
     * @param array $data optional data payload
     * @return array|false response json or false on error
     */
    public function sendFcmNotification(int $userId, string $title, string $body, array $data = [])
    {
        // 1. البحث عن المستخدم في قاعدة البيانات
        $user = \App\Models\User::find($userId);
        if (! $user) {
            Log::warning("FcmService: user {$userId} not found");
            return false;
        }

        // 2. التحقق من وجود توكن FCM الخاص بجهاز المستخدم
        $fcmToken = $user->fcm_token;
        if (! $fcmToken) {
            Log::info("FcmService: user {$userId} has no fcm token");
            return false;
        }

        // 3. تحديد مسار ملف الحساب الخدمي (JSON) الخاص بصلاحيات جوجل
        $credentialsPath = config('services.firebase.service_account') ?: env('FIREBASE_SERVICE_ACCOUNT');
        if (! $credentialsPath) {
            Log::error("FcmService: service account path not configured");
            return false;
        }

        // 4. معالجة المسار للحصول على المسار الكامل للملف داخل نظام التخزين
        $credentialsFullPath = Storage::path(str_replace('storage/app/', '', $credentialsPath));

        // التحقق من وجود ملف الـ JSON في المسار المحدد، وإلا محاولة البحث عنه في مسار افتراضي
        if (! file_exists($credentialsFullPath)) {
            $credentialsFullPath = Storage::path('json/file.json');
        }

        // إذا لم يتم العثور على الملف نهائياً، يتم تسجيل خطأ والتوقف
        if (! file_exists($credentialsFullPath)) {
            Log::error("FcmService: service account file not found at {$credentialsFullPath}");
            return false;
        }

        // 5. البدء في عملية المصادقة مع جوجل (Google Authentication)
        $client = new GoogleClient();
        $client->setAuthConfig($credentialsFullPath); // استخدام ملف الـ JSON للصلاحيات
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging'); // تحديد نطاق الوصول لإرسال الإشعارات
        $token = $client->fetchAccessTokenWithAssertion(); // طلب "توكن وصول" مؤقت من جوجل

        // رفضت غوغل تعطي ال token
        if (isset($token['error'])) {
            Log::error('FcmService: token error', ['error' => $token]);
            return false;
        }

        $accessToken = $token['access_token'] ?? null;
        if (! $accessToken) {
            Log::error('FcmService: no access_token returned');
            return false;
        }

        // 6. تجهيز محتوى الرسالة (Payload) بالشكل الذي يطلبه جوجل (HTTP v1)
        $payload = [
            "message" => array_filter([
                "token" => $fcmToken, // توكن جهاز المستخدم
                "notification" => [   // محتوى الإشعار المرئي
                    "title" => $title,
                    "body" => $body,
                ],
                "data" => $data ?: null, // البيانات الإضافية الموجهة للمبرمج (إن وجدت)
            ]),
        ];

        // 7. الرابط الخاص بإرسال الإشعارات لمشروعك في فايربيز
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        // 8. إرسال الطلب الفعلي باستخدام مكتبة Http مع توكن الوصول (Bearer Token)
        $response = Http::withToken($accessToken)
            ->acceptJson()  // بدي الرد يكون json
            ->post($url, $payload);

        // 9. التحقق من نتيجة الإرسال
        if ($response->successful()) {
            return $response->json(); // تحويل الرد ل json
        }

        // في حال فشل الإرسال، يتم تسجيل حالة الرد والرسالة القادمة من جوجل للتشخيص
        Log::error('FcmService: send failed', [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        return false;
    }
}
