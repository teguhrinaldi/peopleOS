@extends('layouts.admin')
@section('title', $item->employee_name.' · '.$period->name)

@section('content')
<x-page-header :title="$item->employee_name" :subtitle="'Pay details · '.$period->name" :breadcrumbs="[['Payroll', route('admin.payroll')], [$period->name, route('admin.payroll.show', $period)], [$item->employee_name]]">
    <x-badge :status="$period->status" />
</x-page-header>
<x-card class="max-w-2xl">@include('admin.payroll._item')</x-card>
@endsection
