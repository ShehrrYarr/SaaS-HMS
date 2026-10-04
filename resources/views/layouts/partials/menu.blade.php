<ul class="main-menu" id="{{ $menuId }}" role="menu">
    @foreach ($menu as $section)
        <li class="menu-title" role="presentation">{{ $section['title'] }}</li>
        @foreach ($section['items'] as $item)
            @if (! empty($item['children']))
                <li class="slide">
                    <a href="#!" class="side-menu__item" role="menuitem">
                        <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                        <span class="side-menu__label">{{ $item['label'] }}</span>
                        <i class="ri-arrow-down-s-line side-menu__angle"></i>
                    </a>
                    <ul class="slide-menu" role="menu">
                        @foreach ($item['children'] as $child)
                            <li class="slide">
                                <a href="{{ $child['url'] }}" class="side-menu__item" role="menuitem" wire:navigate>{{ $child['label'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li class="slide">
                    <a href="{{ $item['url'] }}" class="side-menu__item" role="menuitem"
                        @if (! empty($item['external'])) target="_blank" @else wire:navigate @endif>
                        <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                        <span class="side-menu__label">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endif
        @endforeach
    @endforeach
</ul>
