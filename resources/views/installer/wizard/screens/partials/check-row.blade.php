<div class="wz-check {{ $row['ok'] ? '' : 'is-bad' }}">
    <span class="wz-badge {{ $row['ok'] ? '' : 'is-bad' }}">@include('installer.wizard.icon', ['name' => $row['ok'] ? 'check' : 'cross', 'size' => 12, 'stroke' => 3])</span>
    <span class="wz-check__text">
        <span class="wz-check__label">{{ $row['label'] }}</span>
        @if($withHelp ?? true)
            <span class="wz-check__help">{{ $row['help'] }}</span>
        @endif
    </span>
    <code class="wz-chip">{{ $row['key'] }}</code>
</div>
