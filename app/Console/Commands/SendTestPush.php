<?php

namespace App\Console\Commands;

use App\Models\DeviceToken;
use App\Models\User;
use App\Services\PushService;
use Illuminate\Console\Command;

/**
 * Proves the push chain end to end, without waiting for a real event.
 *
 * Exists because "notifications are built" and "a notification arrives on a
 * phone" are different claims, and only this one can be demonstrated. It walks
 * the same path a real message takes — the configured gateway, the stored device
 * tokens, the provider — and reports exactly which link failed.
 */
class SendTestPush extends Command
{
    protected $signature = 'push:test
        {email? : Send to this account; omit to list who can be reached}
        {--title=رسالة تجريبية : Notification title}
        {--body=هذا إشعار تجريبي من نظام إدارة مركز تدريب القيادة. : Notification body}';

    protected $description = 'Send a test push notification and report what happened';

    public function handle(PushService $push): int
    {
        $this->newLine();
        $this->line('<options=bold>حالة الإشعارات</>');

        $configured = filled(config('services.fcm.project_id'))
            && is_readable((string) config('services.fcm.credentials'));

        $this->table(['', ''], [
            ['مشروع Firebase', config('services.fcm.project_id') ?: '— غير محدد'],
            ['ملف المفتاح', $configured ? 'موجود ومقروء' : '— غير موجود'],
            ['الخدمة مفعّلة', $push->isEnabled() ? 'نعم' : 'لا'],
            ['أجهزة مسجّلة', (string) DeviceToken::count()],
        ]);

        if (! $push->isEnabled()) {
            $this->error('الإشعارات غير مفعّلة.');
            $this->line('تحقّق من: FCM_PROJECT_ID و FCM_CREDENTIALS_PATH في .env،');
            $this->line('ومن تشغيل "الإشعارات الفورية" في إعدادات النظام.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if (! $email) {
            return $this->listReachable();
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('لا يوجد حساب بهذا البريد: '.$email);

            return self::FAILURE;
        }

        $devices = DeviceToken::where('user_id', $user->id)->get();

        if ($devices->isEmpty()) {
            $this->error('لا يوجد جهاز مسجّل لهذا الحساب.');
            $this->line('يجب تسجيل الدخول من التطبيق أولاً والسماح بالإشعارات.');

            return self::FAILURE;
        }

        $this->line('الإرسال إلى '.$user->name.' ('.$devices->count().' جهاز)…');

        $delivered = $push->send(
            $user,
            (string) $this->option('title'),
            (string) $this->option('body'),
            ['kind' => 'test', 'sent_at' => now()->toIso8601String()],
        );

        $this->newLine();

        if ($delivered) {
            $this->info('✓ قبل المزوّد الإشعار. يجب أن يظهر على الجهاز الآن.');

            return self::SUCCESS;
        }

        $this->error('✗ لم يُقبل الإشعار.');
        $this->line('راجع storage/logs/laravel.log — يُسجّل رد المزوّد هناك.');
        $this->line('الأسباب الشائعة: رمز جهاز منتهٍ (يُحذف تلقائياً)، أو مفتاح خدمة لمشروع آخر.');

        return self::FAILURE;
    }

    /** Who could receive a notification right now. */
    protected function listReachable(): int
    {
        $devices = DeviceToken::with('user:id,name,email')
            ->orderByDesc('last_used_at')
            ->get();

        if ($devices->isEmpty()) {
            $this->warn('لا توجد أجهزة مسجّلة بعد.');
            $this->line('سجّل الدخول من التطبيق واسمح بالإشعارات، ثم أعد المحاولة.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>الأجهزة المسجّلة</>');
        $this->table(
            ['الحساب', 'البريد', 'النظام', 'التطبيق', 'آخر استخدام'],
            $devices->map(fn (DeviceToken $device) => [
                $device->user?->name ?? '—',
                $device->user?->email ?? '—',
                $device->platform,
                $device->app,
                $device->last_used_at?->diffForHumans() ?? '—',
            ])->all(),
        );

        $this->line('للإرسال: php artisan push:test <email>');

        return self::SUCCESS;
    }
}
