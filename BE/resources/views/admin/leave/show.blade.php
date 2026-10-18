@extends('layouts.admin')
@section('title', 'Leave request')

@section('content')
<x-page-header title="Leave request" :subtitle="$request->employee->full_name" :breadcrumbs="[['Leave'], ['Leave Requests', route('admin.leave-requests')], ['#'.$request->id]]" />

<x-card class="max-w-3xl">
    @include('admin.leave._detail')
</x-card>

@include('admin.leave._decision-modal', ['reopen' => old('_form') === 'leave-decision'])
@endsection
