@extends('layouts.app')

@section('title', 'تعديل الباقة')
@section('subtitle', $package->name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الباقات' => route('admin.packages.index'), $package->name => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.packages.update', $package) }}">
        @csrf
        @method('PATCH')
        @include('admin.packages._form', compact('package', 'branches'))
    </form>
@endsection
