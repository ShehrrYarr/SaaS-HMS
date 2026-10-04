/**
 * HMS glue between the Herozi theme and Livewire 3 (wire:navigate SPA mode).
 *
 * Herozi's scripts bind on DOMContentLoaded only. With wire:navigate the <body>
 * is swapped without a full load, so everything page-level is re-initialised
 * here on `livewire:navigated` (which also fires on the very first load).
 */
(function () {
    'use strict';

    // ---------------------------------------------------------------- sidebar
    function setActiveMenu() {
        const path = window.location.pathname.replace(/\/+$/, '');

        document.querySelectorAll('.main-menu').forEach((menu) => {
            menu.querySelectorAll('.side-menu__item.active').forEach((el) => el.classList.remove('active'));

            let best = null;
            let bestLength = -1;
            menu.querySelectorAll('a.side-menu__item[href]').forEach((link) => {
                const href = link.getAttribute('href');
                if (!href || href.startsWith('#')) return;
                let linkPath;
                try {
                    linkPath = new URL(link.href, window.location.origin).pathname.replace(/\/+$/, '');
                } catch (e) {
                    return;
                }
                if ((path === linkPath || path.startsWith(linkPath + '/')) && linkPath.length > bestLength) {
                    best = link;
                    bestLength = linkPath.length;
                }
            });

            if (!best) return;
            best.classList.add('active');
            let parentUl = best.closest('.slide-menu');
            while (parentUl) {
                const li = parentUl.parentElement;
                li.classList.add('open-menu');
                if (parentUl.previousElementSibling) parentUl.previousElementSibling.classList.add('active');
                parentUl = li.closest('.slide-menu');
            }
        });
    }

    function closeMobileSidebar() {
        const el = document.getElementById('smallScreenSidebar');
        if (el && window.bootstrap && el.classList.contains('show')) {
            bootstrap.Offcanvas.getOrCreateInstance(el).hide();
        }
    }

    // --------------------------------------------------------------- page UI
    function initPageUi() {
        if (window.bootstrap) {
            document.querySelectorAll('.tooltip').forEach((t) => t.remove());
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => bootstrap.Tooltip.getOrCreateInstance(el));
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach((el) => bootstrap.Popover.getOrCreateInstance(el));
        }
        if (window.SimpleBar) {
            document.querySelectorAll('.app-wrapper [data-simplebar]').forEach((el) => {
                if (!SimpleBar.instances.has(el)) new SimpleBar(el);
            });
        }
        // Remove stray Bootstrap modal backdrops left behind by a navigation.
        if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach((b) => b.remove());
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');
        }
    }

    // ---------------------------------------------------------------- toasts
    window.hmsToast = function (type, message) {
        if (!message) return;
        const icon = { success: 'success', error: 'error', warning: 'warning', info: 'info' }[type] || 'info';
        if (!window.Swal) {
            alert(message);
            return;
        }
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: message,
            showConfirmButton: false,
            timer: type === 'error' ? 5000 : 3000,
            timerProgressBar: true,
        });
    };

    window.hmsConfirm = function (message, onConfirm, options) {
        options = options || {};
        if (!window.Swal) {
            if (confirm(message)) onConfirm();
            return;
        }
        Swal.fire({
            title: options.title || 'Are you sure?',
            text: message,
            icon: options.icon || 'warning',
            showCancelButton: true,
            confirmButtonText: options.confirmText || 'Yes, continue',
            cancelButtonText: 'Cancel',
            customClass: { confirmButton: 'btn btn-' + (options.color || 'danger') + ' me-2', cancelButton: 'btn btn-light' },
            buttonsStyling: false,
        }).then((result) => {
            if (result.isConfirmed) onConfirm();
        });
    };

    // Called after async Livewire responses: browsers block window.open() outside a user
    // gesture, so offer a button (a real click) that opens the printable document.
    window.hmsPrint = function (url, title) {
        if (!window.Swal) {
            window.open(url, '_blank');
            return;
        }
        Swal.fire({
            icon: 'success',
            title: title || 'Done',
            text: 'Open the printable document?',
            showCancelButton: true,
            confirmButtonText: '<i class="ri-printer-line"></i> Print / open',
            cancelButtonText: 'Close',
            customClass: { confirmButton: 'btn btn-primary me-2', cancelButton: 'btn btn-light' },
            buttonsStyling: false,
        }).then((result) => {
            if (result.isConfirmed) {
                const w = window.open(url, '_blank');
                if (w) w.focus();
            }
        });
    };

    // ------------------------------------------------------------ Alpine bits
    document.addEventListener('alpine:init', () => {
        // x-on:click="$confirm('Delete this patient?', () => $wire.delete(1))"
        Alpine.magic('confirm', () => (message, callback, options) => window.hmsConfirm(message, callback, options));

        // <div x-data="apexChart(@js($options))" wire:ignore></div>
        Alpine.data('apexChart', (options) => ({
            chart: null,
            init() {
                if (!window.ApexCharts) return;
                const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
                const merged = Object.assign({ theme: { mode: dark ? 'dark' : 'light' } }, options);
                merged.chart = Object.assign({ background: 'transparent', toolbar: { show: false }, fontFamily: 'inherit' }, options.chart || {});
                this.chart = new ApexCharts(this.$el, merged);
                this.chart.render();
            },
            destroy() {
                if (this.chart) this.chart.destroy();
            },
        }));

        // Barcode scanner / keyboard-wedge friendly: focus element on "/" key.
        Alpine.directive('hotkey-focus', (el, { expression }) => {
            const handler = (e) => {
                if (e.key === expression && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) {
                    e.preventDefault();
                    el.focus();
                }
            };
            window.addEventListener('keydown', handler);
        });
    });

    document.addEventListener('livewire:init', () => {
        // $this->dispatch('toast', type: 'success', message: 'Saved');
        Livewire.on('toast', (event) => {
            const data = Array.isArray(event) ? event[0] : event;
            window.hmsToast(data.type || 'success', data.message);
        });

        // $this->dispatch('print', url: route(...));
        Livewire.on('print', (event) => {
            const data = Array.isArray(event) ? event[0] : event;
            window.hmsPrint(data.url, data.title);
        });

        // Session expired / CSRF mismatch → reload to the login page instead of the default modal.
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });

    document.addEventListener('livewire:navigating', () => closeMobileSidebar());

    document.addEventListener('livewire:navigated', () => {
        setActiveMenu();
        initPageUi();
    });

    // Fallback for pages rendered without Livewire scripts.
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.Livewire) {
            setActiveMenu();
            initPageUi();
        }
    });
})();
