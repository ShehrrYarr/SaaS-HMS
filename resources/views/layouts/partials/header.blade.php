@php
    $user = auth()->user();
    $logoutRoute = match ($area) { 'admin' => 'admin.logout', 'portal' => 'portal.logout', default => 'tenant.logout' };
@endphp
<!-- START HEADER -->
<header class="app-header">
    <div class="container-fluid">
        <div class="nav-header">
            <div class="header-left hstack gap-3">
                <div class="app-sidebar-logo app-horizontal-logo justify-content-center align-items-center">
                    <a href="{{ $user?->homeUrl() ?? url('/') }}" wire:navigate>
                        <img height="35" class="app-sidebar-logo-default" alt="Logo" src="{{ asset('assets/images/hms-logo.png') }}">
                        <img height="40" class="app-sidebar-logo-minimize" alt="Logo" src="{{ asset('assets/images/hms-mark.png') }}">
                    </a>
                </div>

                <button type="button" class="btn btn-light-light icon-btn sidebar-toggle d-none d-md-block" aria-expanded="false" aria-controls="main-menu">
                    <span class="visually-hidden">Toggle sidebar</span>
                    <i class="ri-menu-2-fill"></i>
                </button>
                <button class="btn btn-light-light icon-btn d-md-none small-screen-toggle" id="smallScreenSidebarLabel" type="button" data-bs-toggle="offcanvas" data-bs-target="#smallScreenSidebar" aria-controls="smallScreenSidebar">
                    <span class="visually-hidden">Sidebar toggle for mobile</span>
                    <i class="ri-arrow-right-fill"></i>
                </button>
                <button class="btn btn-light-light icon-btn d-lg-none small-screen-horizontal-toggle" type="button" aria-expanded="false" aria-controls="main-menu">
                    <span class="visually-hidden">Sidebar toggle for horizontal</span>
                    <i class="ri-arrow-right-fill"></i>
                </button>

                @if ($area === 'tenant' && $user?->can('patients.view'))
                    <livewire:tenant.global-search />
                @elseif ($area === 'tenant' || $area === 'portal')
                    <span class="fw-semibold d-none d-md-inline">{{ hospital()?->name }}</span>
                @else
                    <span class="badge bg-primary-subtle text-primary fs-12 d-none d-md-inline">Super Admin Console</span>
                @endif
            </div>

            <div class="header-right hstack gap-3">
                <div class="hstack gap-0 gap-sm-1">
                    @if ($area === 'tenant')
                        <span class="d-none d-xl-inline-flex align-items-center text-muted fs-12 me-2">
                            <i class="ri-hospital-line me-1"></i>{{ hospital()?->name }}
                        </span>
                    @endif

                    <!-- Theme -->
                    <div class="dropdown features-dropdown d-none d-sm-block">
                        <button type="button" class="btn icon-btn btn-text-primary rounded-circle" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Light or Dark Mode Switch</span>
                            <i class="ri-sun-line fs-20"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <div class="dropdown-item cursor-pointer" id="light-theme"><span class="hstack gap-2 align-middle"><i class="ri-sun-line"></i>Light</span></div>
                            <div class="dropdown-item cursor-pointer" id="dark-theme"><span class="hstack gap-2 align-middle"><i class="ri-moon-clear-line"></i>Dark</span></div>
                            <div class="dropdown-item cursor-pointer" id="system-theme"><span class="hstack gap-2 align-middle"><i class="ri-computer-line"></i>System</span></div>
                        </div>
                    </div>

                    @auth
                        <livewire:notification-bell />
                    @endauth

                    <button type="button" id="fullscreen-button" class="btn icon-btn btn-text-primary rounded-circle custom-toggle d-none d-sm-block" aria-pressed="false">
                        <span class="visually-hidden">Toggle Fullscreen</span>
                        <span class="icon-on"><i class="ri-fullscreen-exit-line fs-16"></i></span>
                        <span class="icon-off"><i class="ri-fullscreen-line fs-16"></i></span>
                    </button>
                </div>

                @auth
                    <div class="dropdown profile-dropdown features-dropdown">
                        <button type="button" id="accountNavbarDropdown" class="btn profile-btn shadow-none px-0 hstack gap-0 gap-sm-3" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
                            <span class="position-relative">
                                <x-avatar :src="$user->avatarUrl()" :name="$user->name" />
                                <span class="position-absolute border-2 border border-white h-12px w-12px rounded-circle bg-success end-0 bottom-0"></span>
                            </span>
                            <span>
                                <span class="h6 d-none d-xl-inline-block text-start fw-semibold mb-0">{{ $user->name }}</span>
                                <span class="d-none d-xl-block fs-12 text-start text-muted">{{ $user->roleLabel() }}</span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end" aria-labelledby="accountNavbarDropdown">
                            <div class="dropdown-header">
                                <h6 class="mb-0">{{ $user->name }}</h6>
                                <small class="text-muted">{{ $user->email }}</small>
                            </div>
                            <div class="dropdown-divider"></div>
                            @if ($area === 'tenant')
                                <a class="dropdown-item" href="{{ route('tenant.profile') }}" wire:navigate><i class="ri-user-line me-2"></i>My Profile</a>
                                @can('settings.manage')
                                    <a class="dropdown-item" href="{{ route('tenant.settings.profile') }}" wire:navigate><i class="ri-settings-3-line me-2"></i>Hospital Settings</a>
                                @endcan
                            @elseif ($area === 'portal')
                                <a class="dropdown-item" href="{{ route('portal.profile') }}" wire:navigate><i class="ri-user-line me-2"></i>My Profile</a>
                            @else
                                <a class="dropdown-item" href="{{ route('admin.settings') }}" wire:navigate><i class="ri-settings-3-line me-2"></i>Global Settings</a>
                            @endif
                            <div class="dropdown-divider"></div>
                            <form method="POST" action="{{ route($logoutRoute) }}">
                                @csrf
                                <button type="submit" class="dropdown-item text-danger"><i class="ri-logout-box-r-line me-2"></i>Sign out</button>
                            </form>
                        </div>
                    </div>
                @endauth
            </div>
        </div>
    </div>
</header>
<!-- END HEADER -->
