@extends('layouts.app')

@section('title', 'الإشعارات')
@section('subtitle', $unreadCount . ' غير مقروء')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الإشعارات' => null]" />
@endsection

@section('content')
@php
    $tones = ['success' => 'success', 'warning' => 'warning', 'error' => 'danger', 'info' => 'info'];
    $icons = ['success' => 'check', 'warning' => 'alert', 'error' => 'alert', 'info' => 'info'];
@endphp

<x-layout.page-header title="الإشعارات" description="تنبيهات النظام المتعلقة بالمواعيد والمالية والتشغيل.">
    <x-slot:actions>
        <div class="flex rounded-lg border border-ink-200 p-0.5">
            @foreach (['all' => 'الكل', 'unread' => 'غير المقروءة'] as $key => $label)
                <a href="{{ route('admin.notifications.index', ['filter' => $key]) }}"
                   @class([
                       'rounded-md px-3 py-1.5 text-sm transition',
                       'bg-brand-600 font-medium text-white' => $filter === $key,
                       'text-ink-600 hover:bg-ink-100' => $filter !== $key,
                   ])>{{ $label }}</a>
            @endforeach
        </div>

        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="check">تعليم الكل كمقروء</x-ui.button>
            </form>
        @endif
    </x-slot:actions>
</x-layout.page-header>

<x-ui.card padded="false">
    @if ($notifications->isEmpty())
        <x-ui.empty-state icon="bell" title="لا توجد إشعارات" description="ستظهر هنا تنبيهات المواعيد والمالية والتشغيل." />
    @else
        <ul class="divide-y divide-ink-100">
            @foreach ($notifications as $notification)
                @php
                    $data = $notification->data;
                    $level = $data['level'] ?? 'info';
                @endphp
                <li @class(['bg-brand-50/40' => ! $notification->read_at])>
                    <form method="POST" action="{{ route('admin.notifications.read', $notification->id) }}">
                        @csrf
                        <button type="submit" class="flex w-full items-start gap-3 px-4 py-3.5 text-start hover:bg-ink-50">
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full
                                         {{ ['success' => 'bg-emerald-50 text-emerald-600', 'warning' => 'bg-amber-50 text-amber-600', 'error' => 'bg-rose-50 text-rose-600'][$level] ?? 'bg-sky-50 text-sky-600' }}">
                                <x-ui.icon :name="$icons[$level] ?? 'info'" class="size-4" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-ink-900">{{ $data['title'] ?? 'إشعار' }}</span>
                                    @unless ($notification->read_at)
                                        <span class="size-1.5 rounded-full bg-brand-500"></span>
                                    @endunless
                                </span>
                                <span class="mt-0.5 block text-sm text-ink-600">{{ $data['body'] ?? '' }}</span>
                                <span class="mt-1 block text-xs text-ink-400">{{ $notification->created_at->diffForHumans() }}</span>
                            </span>
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif
</x-ui.card>

<div class="mt-4">{{ $notifications->links() }}</div>
@endsection
