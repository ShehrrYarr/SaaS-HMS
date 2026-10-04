@foreach (['success', 'error', 'warning', 'info'] as $type)
    @if (session()->has($type))
        <div x-data x-init="$nextTick(() => window.hmsToast(@js($type), @js(session($type))))"></div>
    @endif
@endforeach
