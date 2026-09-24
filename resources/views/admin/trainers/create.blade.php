@extends('layouts.app')

@section('title', 'إضافة مدرب')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المدربون' => route('admin.trainers.index'), 'مدرب جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.trainers.store') }}">
        @csrf
        @include('admin.trainers._form', compact('branches', 'statuses'))
    </form>
@endsection
