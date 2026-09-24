@extends('layouts.app')

@section('title', 'التقارير')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['التقارير' => null]" />
@endsection

@section('content')
    <x-layout.page-header
        title="مركز التقارير"
        description="تقارير قابلة للتصفية والتصدير بصيغ PDF وExcel وCSV."
    />

    @forelse ($groups as $group => $reports)
        <section class="mb-6">
            <h2 class="mb-3 text-sm font-semibold text-ink-700">{{ $group }}</h2>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($reports as $report)
                    <a href="{{ route('admin.reports.show', $report->key) }}"
                       class="group rounded-xl border border-ink-200 bg-white p-4 transition hover:border-brand-300 hover:shadow-sm">
                        <div class="flex items-start gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                                <x-ui.icon name="file-text" class="size-[18px]" />
                            </span>
                            <div class="min-w-0">
                                <h3 class="truncate font-medium text-ink-900 group-hover:text-brand-700">{{ $report->title }}</h3>
                                @if ($report->description)
                                    <p class="mt-0.5 text-xs text-ink-500">{{ $report->description }}</p>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <x-ui.card>
            <x-ui.empty-state icon="file-text" title="لا توجد تقارير متاحة" description="لا تملك صلاحية عرض أي تقرير حالياً." />
        </x-ui.card>
    @endforelse
@endsection
