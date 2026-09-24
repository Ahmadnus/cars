@extends('layouts.app')

@section('title', 'تسجيل متدرب جديد')

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="['المتدربون' => route('admin.trainees.index'), 'متدرب جديد' => null]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.trainees.store') }}" enctype="multipart/form-data">
        @csrf
        @include('admin.trainees._form', compact('trainers', 'branches', 'statuses'))
    </form>
@endsection
