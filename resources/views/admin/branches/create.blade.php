@extends('layouts.app')

@section('title', 'فرع جديد')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['الفروع' => route('admin.branches.index'), 'فرع جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.branches.store') }}">
        @csrf
        @include('admin.branches._form', compact('organizations', 'days'))
    </form>
@endsection
