@extends('layouts.app')

@section('title', 'تعديل بيانات المركبة')
@section('subtitle', $vehicle->name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المركبات' => route('admin.vehicles.index'),
        $vehicle->name => route('admin.vehicles.show', $vehicle),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.vehicles.update', $vehicle) }}">
        @csrf
        @method('PATCH')
        @include('admin.vehicles._form', compact('vehicle', 'branches', 'trainers', 'statuses'))
    </form>

    @canDo('vehicles.manage')
        <x-ui.card title="منطقة الخطر" class="mt-5 border-rose-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-ink-600">لا يمكن أرشفة مركبة مرتبطة بحصص مجدولة.</p>

                <x-ui.confirm
                    :action="route('admin.vehicles.destroy', $vehicle)"
                    method="DELETE"
                    title="أرشفة المركبة"
                    message="سيتم تحويل حالة المركبة إلى «غير نشطة» وأرشفتها مع الاحتفاظ بسجل الصيانة والحصص."
                    confirm-label="أرشفة"
                >
                    <x-slot:trigger>
                        <x-ui.button type="button" variant="danger" size="sm" icon="trash">أرشفة المركبة</x-ui.button>
                    </x-slot:trigger>
                </x-ui.confirm>
            </div>
        </x-ui.card>
    @endcanDo
@endsection
