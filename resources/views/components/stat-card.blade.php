@props(['title', 'value', 'icon' => 'ri-bar-chart-line', 'color' => 'primary', 'hint' => null, 'href' => null])
<div class="card card-h-100 overflow-hidden mb-0">
    <div class="card-body p-4">
        <div class="hstack gap-3 mb-3">
            <div class="bg-{{ $color }}-subtle text-{{ $color }} avatar avatar-item rounded-2">
                <i class="{{ $icon }} fs-16 fw-medium"></i>
            </div>
            <h6 class="mb-0 fs-13 text-muted">{{ $title }}</h6>
        </div>
        <h4 class="fw-semibold fs-5 mb-0">{{ $value }}</h4>
        @if ($hint)
            <p class="text-muted fs-12 mb-0 mt-2">{!! $hint !!}</p>
        @endif
        @if ($href)
            <a href="{{ $href }}" wire:navigate class="stretched-link"></a>
        @endif
    </div>
</div>
