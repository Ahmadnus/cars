{{-- Shared by the create and edit screens; included, not a component, so it
     reads the same $errors and old() state as the form around it. --}}
@php
    $trainee ??= null;
    $trainers ??= [];
    $branches ??= [];
    $statuses ??= [];

    // Whether a reader is configured. Without one the card below still takes the
    // photo — it is the trainee's identity document either way — and simply does
    // not offer to fill anything from it.
    $canReadIds ??= false;
@endphp

<div class="grid gap-5 lg:grid-cols-3">
    <div class="space-y-5 lg:col-span-2">
        {{--
            The ID photo, first on the form because of what it does: attach it and
            the boxes below fill themselves from the card, so the receptionist
            checks a filled form against the ID in their hand instead of typing a
            ten-digit number off it.

            Three rules in the script below, and each is there for a reason:
            a box someone has already typed in is never written over; nothing is
            submitted by the reading itself, so the person at the desk is still
            the one who saves the record; and the photo is sent to be read but
            kept only if the form is submitted with it.
        --}}
        <x-ui.card title="صورة الهوية">
            <div x-data="{
                     busy: false,
                     filled: [],
                     note: null,
                     labels: {
                         full_name: 'الاسم',
                         national_id: 'الرقم الوطني',
                         birth_date: 'تاريخ الميلاد',
                         gender: 'الجنس',
                         address: 'العنوان',
                     },

                     async read() {
                         const file = this.$refs.file.files?.[0];

                         if (!file || !@js($canReadIds)) return;

                         this.busy = true;
                         this.filled = [];
                         this.note = null;

                         try {
                             const body = new FormData();
                             body.append('id_photo', file);

                             const response = await fetch(@js(route('admin.trainees.scan-id')), {
                                 method: 'POST',
                                 headers: {
                                     Accept: 'application/json',
                                     'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content,
                                 },
                                 credentials: 'same-origin',
                                 body,
                             });

                             if (!response.ok) {
                                 this.note = 'تعذّرت قراءة الهوية. أدخل البيانات يدوياً.';
                                 return;
                             }

                             const payload = await response.json();

                             this.apply(payload.fields ?? {});

                             if (this.filled.length === 0) {
                                 this.note = payload.message ?? 'لم تُقرأ بيانات من الصورة.';
                             }
                         } catch (error) {
                             this.note = 'تعذّر الاتصال بالخادم لقراءة الهوية.';
                         } finally {
                             this.busy = false;
                         }
                     },

                     apply(fields) {
                         Object.entries(fields).forEach(([name, value]) => {
                             const input = document.getElementById(name);

                             if (!input || !value) return;

                             // Never over what a person has already entered: they
                             // may be correcting the card, and a form that
                             // overwrites typing is a form nobody trusts twice.
                             if (input.value && input.value.trim() !== '') return;

                             input.value = value;
                             input.dispatchEvent(new Event('input'));
                             input.dispatchEvent(new Event('change'));
                             input.classList.add('ring-1', 'ring-brand-300');

                             this.filled.push(this.labels[name] ?? name);
                         });
                     },
                 }"
                 class="space-y-3">

                <x-form.field
                    label="رفع صورة الهوية"
                    name="id_photo"
                    :hint="$canReadIds
                        ? 'تُقرأ البيانات من الصورة وتُعبّأ الحقول الفارغة تلقائياً. راجعها قبل الحفظ.'
                        : 'تُحفظ مع مستندات المتدرب.'">
                    <input type="file" name="id_photo" accept="image/*" x-ref="file" @change="read()"
                           class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
                </x-form.field>

                <p x-show="busy" x-cloak class="flex items-center gap-2 text-xs text-ink-500">
                    <span class="size-3 animate-spin rounded-full border-2 border-brand-500 border-t-transparent"></span>
                    جارٍ قراءة الهوية…
                </p>

                <p x-show="!busy && filled.length > 0" x-cloak
                   class="rounded-lg bg-emerald-50 p-2.5 text-xs text-emerald-800">
                    تم تعبئة: <span x-text="filled.join('، ')"></span>. راجع البيانات أمام الهوية قبل الحفظ.
                </p>

                <p x-show="!busy && note" x-cloak x-text="note"
                   class="rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800"></p>
            </div>
        </x-ui.card>

        <x-ui.card title="البيانات الشخصية">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.input name="full_name" label="الاسم الكامل" :value="$trainee?->full_name" required class="sm:col-span-2" />
                <x-form.input name="phone" label="رقم الهاتف" :value="$trainee?->phone" required dir="ltr" placeholder="07XXXXXXXX" />
                <x-form.input name="secondary_phone" label="هاتف إضافي" :value="$trainee?->secondary_phone" dir="ltr" />
                <x-form.input name="national_id" label="الرقم الوطني" :value="$trainee?->national_id" dir="ltr" />
                <x-form.input name="birth_date" type="date" label="تاريخ الميلاد" :value="$trainee?->birth_date?->format('Y-m-d')" />
                <x-form.select
                    name="gender"
                    label="الجنس"
                    :options="['male' => 'ذكر', 'female' => 'أنثى']"
                    :selected="$trainee?->gender"
                    placeholder="غير محدد"
                />
                <x-form.input name="address" label="العنوان" :value="$trainee?->address" />
            </div>
        </x-ui.card>

        <x-ui.card title="بيانات التدريب">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-form.select
                    name="license_type"
                    label="نوع الرخصة"
                    :options="\App\Enums\LicenseType::options()"
                    :selected="$trainee?->license_type ?? 'private'"
                    required
                />
                <x-form.input
                    name="registration_date"
                    type="date"
                    label="تاريخ التسجيل"
                    :value="$trainee?->registration_date?->format('Y-m-d') ?? now()->toDateString()"
                    required
                />
                <x-form.select name="trainer_id" label="المدرب المسؤول" :options="$trainers" :selected="$trainee?->trainer_id" placeholder="بدون مدرب" />
                <x-form.select name="status" label="الحالة" :options="$statuses" :selected="$trainee?->status ?? 'new'" required />

                @if ($trainee)
                    <x-form.input name="exam_date" type="date" label="موعد الامتحان" :value="$trainee->exam_date?->format('Y-m-d')" />
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="ملاحظات">
            <x-form.textarea name="notes" :value="$trainee?->notes" rows="4" hint="ملاحظات داخلية لا تظهر للمتدرب." />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="الصورة الشخصية">
            @if ($trainee?->photo_path)
                <img src="{{ Storage::disk('public')->url($trainee->photo_path) }}" alt=""
                     class="mb-3 size-28 rounded-xl object-cover">
            @endif

            <x-form.field label="رفع صورة" name="photo" hint="JPG أو PNG بحد أقصى 4 ميجابايت.">
                <input type="file" name="photo" accept="image/*"
                       class="block w-full text-sm text-ink-600 file:me-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100">
            </x-form.field>
        </x-ui.card>

        @if (count($branches) > 1)
            <x-ui.card title="الفرع">
                <x-form.select name="branch_id" label="الفرع" :options="$branches" :selected="$trainee?->branch_id ?? branch_context()->currentId()" />
            </x-ui.card>
        @elseif (count($branches) === 1)
            <input type="hidden" name="branch_id" value="{{ array_key_first($branches) }}">
        @endif

        {{-- A trainee registered at the desk should leave able to sign in, so
             the login is offered here rather than only after they apply. --}}
        @unless ($trainee)
            <x-account.fields
                title="حساب الدخول للتطبيق"
                hint="يدخل المتدرب على التطبيق برقم هاتفه. تظهر كلمة المرور مرة واحدة بعد الحفظ." />
        @endunless

        <x-ui.card>
            <div class="flex flex-col gap-2">
                <x-ui.button type="submit" size="lg">{{ $trainee ? 'حفظ التعديلات' : 'تسجيل المتدرب' }}</x-ui.button>
                <x-ui.button :href="route('admin.trainees.index')" variant="secondary">إلغاء</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</div>
