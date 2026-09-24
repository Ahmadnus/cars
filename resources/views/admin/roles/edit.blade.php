@extends('layouts.app')

@section('title', 'تعديل الدور')
@section('subtitle', $role->label_ar)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الأدوار والصلاحيات' => route('admin.roles.index'), $role->label_ar => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.roles.update', $role) }}">
        @csrf
        @method('PATCH')
        @include('admin.roles._form', compact('role', 'catalog', 'assigned'))
    </form>
@endsection
