{{--
	The unsaved-changes bar.

	Each section posts its own form and the response is a redirect, so there is
	no way to save two sections in one trip — and pretending otherwise would
	silently drop one of them. The bar therefore saves the section being looked
	at and says plainly, in its detail line, when another section is still
	holding edits.

	It starts hidden and stays hidden until settings-site.js finds a control
	whose value differs from the one the server rendered. Every string it can
	show is handed over here, so the bar reads in the admin's own language.
--}}
<div class="ss-savebar"
     id="ss-savebar"
     hidden
     data-ss-in="{{ __('in') }}"
     data-ss-nothing="{{ __('Nothing is applied until you save.') }}"
     data-ss-others="{{ __('Save applies to the section you are viewing; the others keep their edits.') }}"
     data-ss-unsaved-one="{{ __(':count unsaved change') }}"
     data-ss-unsaved-many="{{ __(':count unsaved changes') }}"
     data-ss-attention-one="{{ __(':count field needs attention') }}"
     data-ss-attention-many="{{ __(':count fields need attention') }}"
     data-ss-blocked-one="{{ __(':count field needs attention before saving') }}"
     data-ss-blocked-many="{{ __(':count fields need attention before saving') }}">
	<div class="ss-savebar__inner" role="status" aria-live="polite">
		<span class="ss-savebar__icon" aria-hidden="true">
			<x-icon name="edit" height="15" width="15"/>
		</span>
		<span class="ss-savebar__copy">
			<strong class="ss-savebar__title" data-ss-savebar-title>{{ __('Unsaved changes') }}</strong>
			<span class="ss-savebar__detail" data-ss-savebar-detail>{{ __('Nothing is applied until you save.') }}</span>
		</span>
		<button type="button" class="ss-savebar__btn ss-savebar__btn--ghost" data-ss-discard>
			{{ __('Discard') }}
		</button>
		<button type="button" class="ss-savebar__btn ss-savebar__btn--primary" data-ss-save>
			{{ __('Save changes') }}
			<kbd class="ss-savebar__kbd" aria-hidden="true" data-ss-save-shortcut>Ctrl S</kbd>
		</button>
	</div>
</div>
