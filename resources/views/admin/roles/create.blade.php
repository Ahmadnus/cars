@extends('layouts.app')

@section('title', 'دور جديد')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الأدوار والصلاحيات' => route('admin.roles.index'), 'دور جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.roles.store') }}">
        @csrf
        @include('admin.roles._form', compact('catalog'))
    </form>
@endsection
