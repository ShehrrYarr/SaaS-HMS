@php
    $menu = \App\Support\Menu::for($area);
    $homeUrl = auth()->user()?->homeUrl() ?? url('/');
    $logo = $area === 'admin' ? asset('assets/images/light-logo.png') : (hospital()?->logoUrl() ?? asset('assets/images/light-logo.png'));
@endphp
<!-- START SIDEBAR -->
<aside class="app-sidebar">
    <div class="app-sidebar-logo px-6 justify-content-center align-items-center">
        <a href="{{ $homeUrl }}" wire:navigate>
            <img height="35" class="app-sidebar-logo-default hms-logo" alt="Logo" src="{{ $logo }}">
            <img height="40" class="app-sidebar-logo-minimize" alt="Logo" src="{{ asset('assets/images/Favicon.png') }}">
        </a>
    </div>
    <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" id="sidebarMenu" aria-label="Main navigation">
        @include('layouts.partials.menu', ['menu' => $menu, 'menuId' => 'all-menu-items'])
    </nav>
</aside>
<div class="horizontal-overlay"></div>

<!-- SMALL SCREEN SIDEBAR -->
<div class="offcanvas offcanvas-md offcanvas-start small-screen-sidebar" data-bs-scroll="true" tabindex="-1" id="smallScreenSidebar" aria-labelledby="smallScreenSidebarLabel">
    <div class="offcanvas-header hstack border-bottom">
        <div class="app-sidebar-logo">
            <a href="{{ $homeUrl }}" wire:navigate>
                <img height="35" class="app-sidebar-logo-default h-25px hms-logo" alt="Logo" src="{{ $logo }}">
                <img height="40" class="app-sidebar-logo-minimize" alt="Logo" src="{{ asset('assets/images/Favicon.png') }}">
            </a>
        </div>
        <button type="button" class="btn-close bg-transparent" data-bs-dismiss="offcanvas" aria-label="Close">
            <i class="ri-close-line"></i>
        </button>
    </div>
    <div class="offcanvas-body p-0">
        <aside class="app-sidebar">
            <nav class="app-sidebar-menu nav nav-pills flex-column fs-6" aria-label="Main navigation">
                @include('layouts.partials.menu', ['menu' => $menu, 'menuId' => 'all-menu-items-mobile'])
            </nav>
        </aside>
    </div>
</div>
<!-- END SIDEBAR -->
