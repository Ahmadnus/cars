@extends('layouts.app')

@section('title', 'تسجيل مصروف')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المصاريف' => route('admin.expenses.index'), 'تسجيل مصروف' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.expenses.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.expenses._form', compact('categories', 'methods'))
    </form>
@endsection
