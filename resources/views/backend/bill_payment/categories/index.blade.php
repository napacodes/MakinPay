@extends('backend.bill_payment.layout')

@section('title', __('Bill categories'))

@section('sub_header')
    <x-page-header
        :title="__('Bill categories')"
        :subtitle="__('The groups customers browse billers by. Switching one off hides its billers.')"
        icon="receipt"
        :stats="[
            ['label' => __('Categories'), 'value' => (int) $stats['total'], 'tone' => 'brand', 'icon' => 'tags'],
            ['label' => __('Active'), 'value' => (int) $stats['active'], 'tone' => 'success', 'icon' => 'check'],
            ['label' => __('Disabled'), 'value' => (int) $stats['disabled'], 'tone' => $stats['disabled'] > 0 ? 'warning' : 'neutral', 'icon' => 'pause'],
        ]"
    >
        @can('bill-payment-manage')
            <x-slot:actions>
                <button type="button" class="ph-btn ph-btn--primary" data-bpa-category-create>
                    <x-icon name="plus" width="14" height="14"/>
                    {{ __('New category') }}
                </button>
            </x-slot:actions>
        @endcan
    </x-page-header>
@endsection

@section('sub_content')
    @php
        $canManage = auth('admin')->user()?->can('bill-payment-manage') ?? false;
    @endphp

    <div class="card border-0 mb-4 bpa-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <caption class="visually-hidden">{{ __('Bill categories') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Category') }}</th>
                            <th scope="col">{{ __('Code') }}</th>
                            <th scope="col">{{ __('Billers') }}</th>
                            <th scope="col">{{ __('Status') }}</th>
                            @if($canManage)
                                <th scope="col" class="text-end">{{ __('Actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $category)
                            <tr>
                                <td>
                                    <div class="bpa-biller">
                                        <span class="bpa-biller__logo bpa-biller__logo--icon" aria-hidden="true">
                                            <x-icon :name="$category->icon ?: 'bill-other'" width="18" height="18"/>
                                        </span>
                                        <div class="bpa-biller__copy">
                                            <span class="bpa-biller__name">
                                                {{ __($category->name) }}
                                                @if($category->is_system)
                                                    <span class="bpa-pill bpa-pill--info">{{ __('System') }}</span>
                                                @endif
                                            </span>
                                            @if($category->description)
                                                <span class="bpa-muted">{{ $category->description }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td><span class="bpa-code" dir="ltr">{{ $category->code }}</span></td>
                                <td>{{ number_format((int) $category->billers_count) }}</td>
                                <td>
                                    @if($canManage)
                                        <form action="{{ route('admin.bill-payment.categories.toggle-status', $category) }}" method="POST" class="bpa-toggle-form">
                                            @csrf
                                            <label class="form-check form-switch feature-mgmt-switch">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       data-bpa-autosubmit
                                                       aria-label="{{ $category->status ? __('Disable :name', ['name' => __($category->name)]) : __('Enable :name', ['name' => __($category->name)]) }}"
                                                       @checked($category->status)>
                                            </label>
                                        </form>
                                    @else
                                        <span class="bpa-pill bpa-pill--{{ $category->status ? 'success' : 'secondary' }}">
                                            {{ $category->status ? __('Active') : __('Disabled') }}
                                        </span>
                                    @endif
                                </td>
                                @if($canManage)
                                    <td class="text-end">
                                        <div class="admin-table-actions">
                                            <button type="button"
                                                    class="btn btn-outline-primary btn-sm admin-table-action"
                                                    data-bpa-category-edit
                                                    data-action="{{ route('admin.bill-payment.categories.update', $category) }}"
                                                    data-id="{{ $category->id }}"
                                                    data-name="{{ $category->name }}"
                                                    data-code="{{ $category->code }}"
                                                    data-icon="{{ $category->icon }}"
                                                    data-description="{{ $category->description }}"
                                                    data-sort-order="{{ $category->sort_order }}"
                                                    data-status="{{ $category->status ? '1' : '0' }}"
                                                    data-system="{{ $category->is_system ? '1' : '0' }}"
                                                    title="{{ __('Edit :name', ['name' => __($category->name)]) }}">
                                                <x-icon name="edit" width="14" height="14"/>
                                                <span>{{ __('Edit') }}</span>
                                            </button>
                                            @if(! $category->is_system && (int) $category->billers_count === 0)
                                                <form action="{{ route('admin.bill-payment.categories.destroy', $category) }}"
                                                      method="POST"
                                                      class="admin-table-actions__form"
                                                      data-bpa-confirm="{{ __('Delete :name? This cannot be undone.', ['name' => __($category->name)]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="btn btn-outline-danger btn-sm admin-table-action admin-table-action--danger"
                                                            title="{{ __('Delete :name', ['name' => __($category->name)]) }}">
                                                        <x-icon name="trash" width="14" height="14"/>
                                                        <span>{{ __('Delete') }}</span>
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $canManage ? 5 : 4 }}">
                                    <x-admin-not-found
                                        :title="__('No categories yet')"
                                        :message="__('Add a category before adding billers to it.')"
                                        icon="fa-receipt"
                                    />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @can('bill-payment-manage')
        @include('backend.bill_payment.categories._modal')
    @endcan
@endsection
