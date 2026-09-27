@extends('layouts.app')

@section('title', 'تعديل بيانات الموظف')
@section('subtitle', $employee->full_name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'الموظفون' => route('admin.employees.index'),
        $employee->full_name => route('admin.employees.show', $employee),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
    {{-- Resetting the password is what brings staff here more often than
         editing a birth date, so it sits above the record. Its own form,
         outside the one below: a form nested in a form posts nothing. --}}
    <div class="mb-5 lg:max-w-md">
        <x-account.reset
            :subject="$employee"
            issue-route="admin.employees.account"
            show-route="admin.employees.show"
            :can-manage="auth()->user()->hasPermission('employees.update')" />
    </div>

    <form method="POST" action="{{ route('admin.employees.update', $employee) }}">
        @csrf
        @method('PATCH')
        @include('admin.employees._form', compact('employee', 'branches', 'positions', 'statuses'))
    </form>
@endsection
