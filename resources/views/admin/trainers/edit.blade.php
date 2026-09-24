@extends('layouts.app')

@section('title', 'تعديل بيانات المدرب')
@section('subtitle', $trainer->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المدربون' => route('admin.trainers.index'),
        $trainer->full_name => route('admin.trainers.show', $trainer),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.trainers.update', $trainer) }}">
        @csrf
        @method('PATCH')
        @include('admin.trainers._form', compact('trainer', 'branches', 'statuses'))
    </form>

    @canDo('trainers.delete')
        <x-ui.card title="منطقة الخطر" class="mt-5 border-rose-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-600">الأرشفة تتطلب عدم وجود حصص مجدولة لهذا المدرب.</p>

                <x-ui.confirm
                    :action="route('admin.trainers.destroy', $trainer)"
                    method="DELETE"
                    title="أرشفة المدرب"
                    message="سيتم تحويل حالة المدرب إلى «منتهي الخدمة» وأرشفة ملفه مع الاحتفاظ بكامل سجله."
                    confirm-label="أرشفة"
                >
                    <x-slot:trigger>
                        <x-ui.button type="button" variant="danger" size="sm" icon="trash">أرشفة المدرب</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            </div>
        </x-ui.card>
    @endcanDo
@endsection
