@extends('layouts.app')

@section('title', 'إضافة موظف')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الموظفون' => route('admin.employees.index'), 'موظف جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.employees.store') }}">
        @csrf
        @include('admin.employees._form', compact('branches', 'positions', 'statuses'))
    </form>
@endsection
