<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Sidebar definitions per area. Items are filtered by plan module and by the
 * user's permissions ("can" = any of the listed abilities).
 */
class Menu
{
    public static function for(string $area): array
    {
        $sections = match ($area) {
            'admin' => static::admin(),
            'portal' => static::portal(),
            default => static::tenant(),
        };

        return static::filter($sections);
    }

    protected static function tenant(): array
    {
        return [
            ['title' => 'Main', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'tenant.dashboard'],
                ['label' => 'Doctor Workspace', 'icon' => 'ri-stethoscope-line', 'route' => 'tenant.doctor.workspace', 'can' => ['opd.consult'], 'module' => 'opd'],
            ]],
            ['title' => 'Clinical', 'items' => [
                ['label' => 'Patients', 'icon' => 'ri-user-heart-line', 'can' => ['patients.view'], 'children' => [
                    ['label' => 'All Patients', 'route' => 'tenant.patients.index'],
                    ['label' => 'Register Patient', 'route' => 'tenant.patients.create', 'can' => ['patients.create']],
                ]],
                ['label' => 'Appointments', 'icon' => 'ri-calendar-check-line', 'module' => 'appointments', 'can' => ['appointments.view', 'queue.manage', 'schedules.manage'], 'children' => [
                    ['label' => 'Appointments', 'route' => 'tenant.appointments.index', 'can' => ['appointments.view']],
                    ['label' => 'OPD Queue & Tokens', 'route' => 'tenant.queue.index', 'can' => ['queue.manage']],
                    ['label' => 'Doctor Schedules', 'route' => 'tenant.schedules.index', 'can' => ['schedules.manage']],
                ]],
                ['label' => 'OPD', 'icon' => 'ri-hospital-line', 'module' => 'opd', 'can' => ['opd.view'], 'children' => [
                    ['label' => 'OPD Visits', 'route' => 'tenant.opd.index'],
                    ['label' => 'Prescriptions', 'route' => 'tenant.prescriptions.index', 'can' => ['prescriptions.view']],
                ]],
                ['label' => 'IPD & Wards', 'icon' => 'ri-hotel-bed-line', 'module' => 'ipd', 'can' => ['ipd.view', 'beds.manage'], 'children' => [
                    ['label' => 'Admissions', 'route' => 'tenant.ipd.index', 'can' => ['ipd.view']],
                    ['label' => 'Bed Matrix', 'route' => 'tenant.ipd.beds', 'can' => ['ipd.view']],
                    ['label' => 'Nurse Station', 'route' => 'tenant.ipd.nurse-station', 'can' => ['ipd.vitals']],
                    ['label' => 'Wards & Beds', 'route' => 'tenant.ipd.wards', 'can' => ['beds.manage']],
                ]],
                ['label' => 'Operation Theater', 'icon' => 'ri-surgical-mask-line', 'module' => 'ot', 'can' => ['ot.view'], 'children' => [
                    ['label' => 'OT Schedule', 'route' => 'tenant.ot.index'],
                    ['label' => 'OT Rooms', 'route' => 'tenant.ot.rooms', 'can' => ['ot.manage']],
                ]],
                ['label' => 'Telemedicine', 'icon' => 'ri-video-chat-line', 'module' => 'telemedicine', 'route' => 'tenant.telemedicine.index', 'can' => ['telemedicine.conduct']],
            ]],
            ['title' => 'Diagnostics & Pharmacy', 'items' => [
                ['label' => 'Pharmacy', 'icon' => 'ri-capsule-line', 'module' => 'pharmacy', 'can' => ['pharmacy.view', 'pharmacy.sell', 'pharmacy.dispense', 'pharmacy.inventory', 'pharmacy.purchase'], 'children' => [
                    ['label' => 'POS Billing', 'route' => 'tenant.pharmacy.pos', 'can' => ['pharmacy.sell']],
                    ['label' => 'Dispense Rx', 'route' => 'tenant.pharmacy.dispense', 'can' => ['pharmacy.dispense']],
                    ['label' => 'Sales', 'route' => 'tenant.pharmacy.sales', 'can' => ['pharmacy.view', 'pharmacy.sell']],
                    ['label' => 'Medicines', 'route' => 'tenant.pharmacy.medicines', 'can' => ['pharmacy.inventory', 'pharmacy.view']],
                    ['label' => 'Stock & Expiry', 'route' => 'tenant.pharmacy.stock', 'can' => ['pharmacy.inventory', 'pharmacy.view']],
                    ['label' => 'Purchase Orders', 'route' => 'tenant.pharmacy.purchase-orders', 'can' => ['pharmacy.purchase']],
                    ['label' => 'Suppliers', 'route' => 'tenant.pharmacy.suppliers', 'can' => ['pharmacy.purchase']],
                ]],
                ['label' => 'Laboratory', 'icon' => 'ri-flask-line', 'module' => 'laboratory', 'can' => ['lab.view', 'lab.manage_tests', 'lab.qc'], 'children' => [
                    ['label' => 'Lab Orders', 'route' => 'tenant.lab.orders', 'can' => ['lab.view']],
                    ['label' => 'Test Catalog', 'route' => 'tenant.lab.tests', 'can' => ['lab.manage_tests']],
                    ['label' => 'Quality Control', 'route' => 'tenant.lab.qc', 'can' => ['lab.qc']],
                    ['label' => 'Devices', 'route' => 'tenant.lab.devices', 'can' => ['lab.qc']],
                ]],
                ['label' => 'Radiology', 'icon' => 'ri-scan-2-line', 'module' => 'radiology', 'can' => ['radiology.view', 'radiology.manage_tests'], 'children' => [
                    ['label' => 'Imaging Orders', 'route' => 'tenant.radiology.orders', 'can' => ['radiology.view']],
                    ['label' => 'Imaging Catalog', 'route' => 'tenant.radiology.tests', 'can' => ['radiology.manage_tests']],
                ]],
                ['label' => 'Blood Bank', 'icon' => 'ri-drop-line', 'module' => 'bloodbank', 'can' => ['bloodbank.view'], 'children' => [
                    ['label' => 'Inventory', 'route' => 'tenant.bloodbank.inventory'],
                    ['label' => 'Donors', 'route' => 'tenant.bloodbank.donors'],
                    ['label' => 'Requests & Cross-match', 'route' => 'tenant.bloodbank.requests'],
                ]],
            ]],
            ['title' => 'Finance & HR', 'items' => [
                ['label' => 'Billing', 'icon' => 'ri-bill-line', 'module' => 'billing', 'can' => ['billing.view', 'insurance.manage', 'expenses.manage', 'billing.reports'], 'children' => [
                    ['label' => 'Invoices', 'route' => 'tenant.billing.invoices', 'can' => ['billing.view']],
                    ['label' => 'New Invoice', 'route' => 'tenant.billing.create', 'can' => ['billing.create']],
                    ['label' => 'Payments', 'route' => 'tenant.billing.payments', 'can' => ['billing.view']],
                    ['label' => 'Insurance / TPA', 'route' => 'tenant.billing.claims', 'can' => ['insurance.manage']],
                    ['label' => 'Expenses', 'route' => 'tenant.billing.expenses', 'can' => ['expenses.manage']],
                    ['label' => 'Service Charges', 'route' => 'tenant.billing.services', 'can' => ['billing.create']],
                    ['label' => 'Financial Reports', 'route' => 'tenant.billing.reports', 'can' => ['billing.reports']],
                ]],
                ['label' => 'Banks & Cash', 'icon' => 'ri-bank-line', 'route' => 'tenant.banks.index', 'can' => ['banks.view']],
                ['label' => 'HR & Payroll', 'icon' => 'ri-team-line', 'module' => 'hr', 'can' => ['hr.staff', 'hr.shifts', 'hr.attendance', 'hr.payroll', 'hr.commissions'], 'children' => [
                    ['label' => 'Staff Directory', 'route' => 'tenant.hr.staff', 'can' => ['hr.staff']],
                    ['label' => 'Shifts & Roster', 'route' => 'tenant.hr.shifts', 'can' => ['hr.shifts']],
                    ['label' => 'Attendance', 'route' => 'tenant.hr.attendance', 'can' => ['hr.attendance']],
                    ['label' => 'Payroll', 'route' => 'tenant.hr.payroll', 'can' => ['hr.payroll']],
                    ['label' => 'Doctor Commissions', 'route' => 'tenant.hr.commissions', 'can' => ['hr.commissions']],
                ]],
            ]],
            ['title' => 'Administration', 'items' => [
                ['label' => 'Settings', 'icon' => 'ri-settings-3-line', 'can' => ['settings.manage', 'users.manage', 'roles.manage', 'departments.manage', 'audit.view', 'subscription.manage'], 'children' => [
                    ['label' => 'Hospital Profile', 'route' => 'tenant.settings.profile', 'can' => ['settings.manage']],
                    ['label' => 'Users', 'route' => 'tenant.settings.users', 'can' => ['users.manage']],
                    ['label' => 'Roles & Permissions', 'route' => 'tenant.settings.roles', 'can' => ['roles.manage']],
                    ['label' => 'Departments', 'route' => 'tenant.settings.departments', 'can' => ['departments.manage']],
                    ['label' => 'Insurance Companies', 'route' => 'tenant.settings.tpas', 'can' => ['insurance.manage', 'settings.manage']],
                    ['label' => 'Subscription', 'route' => 'tenant.subscription', 'can' => ['subscription.manage']],
                    ['label' => 'Audit Logs', 'route' => 'tenant.settings.audit', 'can' => ['audit.view']],
                ]],
            ]],
        ];
    }

    protected static function admin(): array
    {
        return [
            ['title' => 'Platform', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'admin.dashboard'],
                ['label' => 'Hospitals', 'icon' => 'ri-hospital-line', 'route' => 'admin.hospitals.index'],
                ['label' => 'Subscription Plans', 'icon' => 'ri-price-tag-3-line', 'route' => 'admin.plans'],
                ['label' => 'Invoices & Payments', 'icon' => 'ri-bill-line', 'route' => 'admin.invoices'],
            ]],
            ['title' => 'System', 'items' => [
                ['label' => 'Global Settings', 'icon' => 'ri-settings-3-line', 'route' => 'admin.settings'],
                ['label' => 'Platform Audit Log', 'icon' => 'ri-file-list-3-line', 'route' => 'admin.audit'],
                ['label' => 'Template Reference', 'icon' => 'ri-layout-masonry-line', 'url' => config('hms.template_demo') ? url('template/index') : null, 'external' => true],
            ]],
        ];
    }

    protected static function portal(): array
    {
        return [
            ['title' => 'My Health', 'items' => [
                ['label' => 'Dashboard', 'icon' => 'ri-dashboard-3-line', 'route' => 'portal.dashboard'],
                ['label' => 'Appointments', 'icon' => 'ri-calendar-check-line', 'route' => 'portal.appointments', 'module' => 'appointments'],
                ['label' => 'Lab Reports', 'icon' => 'ri-flask-line', 'route' => 'portal.lab-reports', 'module' => 'laboratory'],
                ['label' => 'Prescriptions', 'icon' => 'ri-capsule-line', 'route' => 'portal.prescriptions', 'module' => 'opd'],
                ['label' => 'Medical History', 'icon' => 'ri-heart-pulse-line', 'route' => 'portal.history'],
                ['label' => 'Bills & Payments', 'icon' => 'ri-bill-line', 'route' => 'portal.bills', 'module' => 'billing'],
                ['label' => 'My Profile', 'icon' => 'ri-user-settings-line', 'route' => 'portal.profile'],
            ]],
        ];
    }

    protected static function filter(array $sections): array
    {
        $user = auth()->user();
        $hospital = hospital();

        $allowed = function (array $item) use ($user, $hospital): bool {
            if (! empty($item['module']) && $hospital && ! $hospital->hasModule($item['module'])) {
                return false;
            }
            if (! empty($item['can']) && ! collect($item['can'])->contains(fn ($ability) => $user?->can($ability))) {
                return false;
            }
            if (! empty($item['route']) && ! Route::has($item['route'])) {
                return false;
            }
            if (array_key_exists('url', $item) && empty($item['url'])) {
                return false;
            }

            return true;
        };

        return collect($sections)->map(function ($section) use ($allowed) {
            $section['items'] = collect($section['items'])->filter($allowed)->map(function ($item) use ($allowed) {
                if (! empty($item['children'])) {
                    $item['children'] = collect($item['children'])->filter($allowed)
                        ->map(fn ($c) => $c + ['url' => $c['url'] ?? route($c['route'])])->values()->all();
                }
                $item['url'] = $item['url'] ?? (isset($item['route']) ? route($item['route']) : '#!');

                return $item;
            })->filter(fn ($item) => ! array_key_exists('children', $item) || ! empty($item['children']))->values()->all();

            return $section;
        })->filter(fn ($s) => ! empty($s['items']))->values()->all();
    }
}
