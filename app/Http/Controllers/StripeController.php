<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Order;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class StripeController extends Controller
{
    use ShefaaTrait;

    protected $fcmService;

    public function __construct(FcmService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    // إنشاء نية الدفع
    public function createPaymentIntent(Request $request)
    {
        // التحقق من وجود الطلب مع بيانات الدفع الخاصة به
        $order = Order::with('payment')->find($request->order_id);

        if (!$order) {
            return $this->ErrorResponse(__('payment.order_not_found'), 404);
        }

        // منع الدفع المتكرر
        if ($order->payment && $order->payment->payment_status === 'paid') {
            return $this->ErrorResponse(__('payment.already_paid'), 422);
        }

        Stripe::setApiKey(env('STRIPE_SECRET'));

        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => $order->total_price * 100,
                'currency' => 'USD',
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => auth()->id()
                ],
            ]);

            return $this->SuccessResponse([
                'clientSecret' => $paymentIntent->client_secret,
                'publishableKey' => env('STRIPE_KEY')
            ], __('payment.pay_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage(), 500);
        }
    }

    // استقبال إشعارات Stripe أو المحاكاة

    public function handleWebhook(Request $request)
    {
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');

        // اختبار محلي (Postman)
        if (!$sig_header && app()->environment('local')) {
            return $this->processEvent($request->all());
        }

        // تحقق رسمي من Stripe
        try {
            $payload = $request->getContent();
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
            return $this->processEvent($event->jsonSerialize());
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('payment.webhook_error') . $e->getMessage(), 400);
        }
    }

    // تحليل نوع الحدث

    protected function processEvent($eventData)
    {
        if (isset($eventData['type']) && $eventData['type'] === 'payment_intent.succeeded') {
            $intent = $eventData['data']['object'];
            $orderId = $intent['metadata']['order_id'] ?? null;
            $stripeId = $intent['id'];
            // جلب العملة من كائن سترايب
            $currency = $intent['currency'] ?? 'USD';

            if ($orderId) {
                return $this->confirmOrderPayment($orderId, $stripeId, $currency);
            }
        }

        return $this->ErrorResponse(['status' => 'ignored'], 400);
    }

    // التحديث النهائي وإرسال رسالة الخطأ إذا كان مدفوعاً
    protected function confirmOrderPayment($orderId, $stripePaymentId, $currency)
    {
        try {
            // جلب الطلب مع التأكد من حالة الدفع
            $order = Order::with('payment', 'pharmacy.user')->find($orderId);

            if (!$order) {
                return $this->ErrorResponse(__('payment.order_not_found'), 404);
            }

            // التحقق المطلوب إذا كان paid يظهر رسالة خطأ
            if ($order->payment && $order->payment->payment_status === 'paid') {
                return $this->ErrorResponse(__('payment.already_paid_error'), 422);
            }

            $data = DB::transaction(function () use ($order, $stripePaymentId, $currency) {
                // تحديث حالة الطلب
                $order->update(['order_status' => 'pending']);

                // تحديث سجل الدفع
                $order->payment()->update([
                    'payment_status' => 'paid',
                    'stripe_payment_id' => $stripePaymentId,
                    'currency' => strtoupper($currency),
                ]);

                // الإشعارات
                $pharmacyUserId = $order->pharmacy->user_id;
                $title = __('payment.pay_title');
                $message = __('payment.pay_message', ['customer_name' => $order->customer_name]);

                Notification::create([
                    'user_id' => $pharmacyUserId,
                    'related_id' => $order->id,
                    'related_type' => 'order_paid',
                    'title' => $title,
                    'message' => $message
                ]);

                return [
                    'pharmacy_user_id' => $pharmacyUserId,
                    'order_id' => $order->id,
                    'title' => $title,
                    'message' => $message
                ];
            });

            // إرسال إشعار
            if ($data) {
                $this->fcmService->sendFcmNotification(
                    $data['pharmacy_user_id'],
                    $data['title'],
                    $data['message'],
                    [
                        'related_id' => (string)$data['order_id'],
                        'related_type' => 'order_paid'
                    ]
                );
            }

            return $this->SuccessResponse(null, __('payment.order_processed_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse($e->getMessage(), 500);
        }
    }
}
