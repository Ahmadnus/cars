@extends('layouts.app')

@section('title', 'إضافة مركبة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المركبات' => route('admin.vehicles.index'), 'مركبة جديدة' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.vehicles.store') }}">
        @csrf
        @include('admin.vehicles._form', compact('branches', 'trainers', 'statuses'))
    </form>
@endsection
