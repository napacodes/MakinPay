@extends('backend.layouts.app')
@section('title')
	{{ __('Footer Items Manage') }}
@endsection
@section('content')
	<x-page-header
		:title="__('Manage Items for:')"
		icon="sidebar-footer"
		:badge="$footerSection->title_text"
		:back="route('admin.page.footer.section.index')"
		:back-label="__('Back to Footer Sections')"
	>
		<x-slot:actions>
			<button type="button" class="ph-btn ph-btn--primary"
			        data-coreui-toggle="modal" data-coreui-target="#create-footer-item-modal">
				<x-icon name="plus" width="14" height="14"/>{{ __('Add New Item') }}
			</button>
		</x-slot:actions>
	</x-page-header>

	<div class="card border-0 mb-4">
		<div class="card-body">
			<div class="table-responsive">
				<table class="table user-table align-items-center">
					<thead>
					<tr>
						<th class="text-center">
							<x-icon name="up-down" height="20" width="20"/>
						</th>
						<th>{{ __('Label') }}</th>
						<th>{{ __('Type') }}</th>
						<th>{{ __('Status') }}</th>
						<th>{{ __('Action') }}</th>
					</tr>
					</thead>
					<tbody id="footer-item-sortable">
					@forelse($footerSection->items()->orderBy('order')->get()  as $item)
						<tr data-id="{{ $item->id }}">
							<td class="text-muted text-center">
								<i class="fa-solid fa-grip-vertical drag-handle fs-5" title="Drag to sort" data-coreui-toggle="tooltip"></i>
							</td>
							<td>
								{{ $item->label_text }}
							</td>
							<td>
								<span class="badge bg-{{ $item->url_type->colorClass() }}">{{ $item->url_type->label() }}</span>
								<small class="d-block text-muted">
									<a href="{{ safeUrl($item->url) }}" target="_blank" rel="noopener" class="text-decoration-none">
										{{ $item->dynamic_label }}
									</a>
								</small>
							</td>
							<td>
                                <span class="badge bg-{{ $item->status ? 'success' : 'danger' }}">
                                    {{ strtoupper($item->status ? 'ACTIVE' : 'INACTIVE') }}
                                </span>
							</td>
							<td>
								<div class="btn-group" role="group" aria-label="Actions">
									<a href="javascript:void(0)" class="btn btn-primary edit-modal"
									   data-edit-url="{{ route('admin.page.footer.item.edit', $item->id) }}">
										<x-icon name="edit" height="20" width="20"/> {{ __('Edit') }}
									</a>
									<a href="javascript:void(0)" class="btn btn-danger text-white delete"
									   data-url="{{ route('admin.page.footer.item.destroy', $item->id) }}">
										<x-icon name="delete-3" height="20" width="20"/> {{ __('Delete') }}
									</a>
								</div>
							</td>
						</tr>
					@empty
						<tr>
							<td colspan="6">
								<x-admin-not-found
									:title="__('No footer items found')"
									:message="__('Add footer items to populate this footer section.')"
									icon="fa-list"
								/>
							</td>
						</tr>
					@endforelse
					</tbody>
				</table>
			</div>
		</div>
	</div>
	
	{{-- Create Modal --}}
	@include('backend.page_footer.items.partials._create_modal')
	
	{{-- Edit Modal --}}
	@include('backend.page_footer.items.partials._edit_modal')
@endsection

@push('scripts')
	@include('backend.page_footer.items.partials._scripts')
@endpush
