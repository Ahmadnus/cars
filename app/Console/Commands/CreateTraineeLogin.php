<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Trainee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Gives a trainee a login for the portal.
 *
 * Provisioning is a command rather than a UI action because it mints a
 * credential: the generated password is printed once and never stored in
 * readable form, and the operator is the only one who sees it.
 */
class CreateTraineeLogin extends Command
{
    protected $signature = 'trainee:login
        {trainee : The trainee number, e.g. MT-2026-00001}
        {--email= : Login address; defaults to the trainee\'s own email}
        {--password= : Set an explicit password instead of generating one}';

    protected $description = 'Create (or reset) the portal login for one trainee';

    public function handle(): int
    {
        $trainee = Trainee::where('trainee_number', $this->argument('trainee'))->first();

        if (! $trainee) {
            $this->error('لا يوجد متدرب بهذا الرقم: '.$this->argument('trainee'));

            return self::FAILURE;
        }

        // Trainees are recorded by phone, not email, so the login address is
        // always supplied explicitly.
        $email = $this->option('email');

        if (! $email) {
            $this->error('يجب تحديد بريد الدخول: --email=someone@example.com');

            return self::FAILURE;
        }

        $role = Role::where('name', 'trainee')->first();

        if (! $role) {
            $this->error('دور "trainee" غير موجود. نفّذ php artisan db:seed --class=PermissionSeeder أولاً.');

            return self::FAILURE;
        }

        // Linking a user that already belongs to another trainee would hand
        // that person's file to this login.
        $existingUserId = User::withTrashed()->where('email', $email)->value('id');

        $taken = $existingUserId !== null
            && Trainee::where('user_id', $existingUserId)
                ->where('id', '!=', $trainee->id)
                ->exists();

        if ($taken) {
            $this->error('هذا الحساب مرتبط بملف متدرب آخر: '.$email);

            return self::FAILURE;
        }

        $password = $this->option('password') ?: Str::lower(Str::random(10));

        DB::transaction(function () use ($trainee, $email, $role, $password) {
            $user = User::withTrashed()->firstOrNew(['email' => $email]);

            $user->fill([
                'name' => $trainee->full_name,
                'phone' => $trainee->phone,
                'branch_id' => $trainee->branch_id,
                'locale' => 'ar',
            ]);

            $user->password = Hash::make($password);
            $user->status = 'active';
            $user->email_verified_at ??= now();
            $user->deleted_at = null;
            $user->save();

            $user->roles()->sync([$role->id]);
            $user->branches()->syncWithoutDetaching([$trainee->branch_id]);

            $trainee->forceFill(['user_id' => $user->id])->save();
        });

        $this->newLine();
        $this->info('تم إنشاء حساب بوابة المتدرب:');
        $this->table(['', ''], [
            ['الاسم', $trainee->full_name],
            ['رقم الملف', $trainee->trainee_number],
            ['البريد', $email],
            ['كلمة المرور', $password],
            ['الرابط', url('/portal')],
        ]);
        $this->warn('كلمة المرور تُعرض مرة واحدة فقط. اطلب من المتدرب تغييرها بعد أول دخول.');

        return self::SUCCESS;
    }
}
