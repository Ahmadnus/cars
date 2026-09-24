@extends('layouts.app')

@section('title', 'تعديل مصروف')
@section('subtitle', $expense->reference)

@section('breadcrumbs')
    <x-layout.breadcrumbs :items="[
        'المصاريف' => route('admin.expenses.index'),
        $expense->reference => route('admin.expenses.show', $expense),
        'تعديل' => null,
    ]" />
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.expenses.update', $expense) }}">
        @csrf
        @method('PATCH')
        @include('admin.expenses._form', compact('expense', 'categories', 'methods'))
    </form>
@endsection
