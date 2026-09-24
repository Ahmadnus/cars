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
    <form method="POST" action="{{ route('admin.employees.update', $employee) }}">
        @csrf
        @method('PATCH')
        @include('admin.employees._form', compact('employee', 'branches', 'positions', 'statuses'))
    </form>
@endsection
