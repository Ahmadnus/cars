@extends('layouts.app')

@section('title', 'باقة تدريبية جديدة')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الباقات' => route('admin.packages.index'), 'باقة جديدة' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.packages.store') }}">
        @csrf
        @include('admin.packages._form', compact('branches'))
    </form>
@endsection
