@props(['title', 'subtitle' => null, 'breadcrumbs' => []])
<div class="hstack flex-wrap gap-3 mb-4">
    <div class="flex-grow-1">
        <h4 class="mb-1 fw-semibold">{{ $title }}</h4>
        @if ($breadcrumbs || $subtitle)
            <nav>
                <ol class="breadcrumb breadcrumb-arrow mb-0">
                    @foreach ($breadcrumbs as $label => $url)
                        <li class="breadcrumb-item"><a href="{{ $url }}" wire:navigate>{{ $label }}</a></li>
                    @endforeach
                    <li class="breadcrumb-item active" aria-current="page">{{ $subtitle ?? $title }}</li>
                </ol>
            </nav>
        @endif
    </div>
    @if (trim($slot))
        <div class="d-flex flex-wrap gap-2 my-xl-auto align-items-center flex-shrink-0">
            {{ $slot }}
        </div>
    @endif
</div>
