@extends('layouts.app')

@section('title', 'تعديل الفرع')
@section('subtitle', $branch->name)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الفروع' => route('admin.branches.index'), $branch->name => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.branches.update', $branch) }}">
        @csrf
        @method('PATCH')
        @include('admin.branches._form', compact('branch', 'organizations', 'days'))
    </form>
@endsection
