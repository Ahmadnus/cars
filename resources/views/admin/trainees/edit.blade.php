@extends('layouts.app')

@section('title', 'تعديل بيانات المتدرب')
@section('subtitle', $trainee->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المتدربون' => route('admin.trainees.index'),
        $trainee->full_name => route('admin.trainees.show', $trainee),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
    {{-- Resetting the password is what brings staff here more often than
         editing a birth date, so it sits above the record. Its own form,
         outside the one below: a form nested in a form posts nothing. --}}
    <div class="mb-5 lg:max-w-md">
        <x-account.reset
            :subject="$trainee"
            issue-route="admin.trainees.account"
            show-route="admin.trainees.show"
            :can-manage="auth()->user()->hasPermission('trainees.update')" />
    </div>

    <form method="POST" action="{{ route('admin.trainees.update', $trainee) }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')
        @include('admin.trainees._form', compact('trainee', 'trainers', 'branches', 'statuses'))
    </form>

    @canDo('trainees.delete')
        <x-ui.card title="منطقة الخطر" class="mt-5 border-rose-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-600">
                    الأرشفة تخفي المتدرب من القوائم مع الاحتفاظ بكامل سجله المالي والتدريبي.
                </p>

                <x-ui.confirm
                    :action="route('admin.trainees.destroy', $trainee)"
                    method="DELETE"
                    title="أرشفة المتدرب"
                    message="سيتم أرشفة المتدرب وتحويل حالته إلى «ملغى». لن يتم حذف أي سجل مالي."
                    confirm-label="أرشفة"
                >
                    <x-slot:trigger>
                        <x-ui.button type="button" variant="danger" size="sm" icon="trash">أرشفة المتدرب</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            </div>
        </x-ui.card>
    @endcanDo
@endsection
