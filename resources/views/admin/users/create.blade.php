@extends('layouts.app')

@section('title', 'مستخدم جديد')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المستخدمون' => route('admin.users.index'), 'مستخدم جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.users.store') }}">
        @csrf
        @include('admin.users._form', compact('roles', 'branches'))
    </form>
@endsection
