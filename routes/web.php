<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/
Volt::route('/', 'landing')->name('home');

if (config('hms.template_demo')) {
    // Herozi demo pages kept for reference at /template/*
    Route::redirect('template', 'template/index');
    Route::get('template/{page}', TemplateController::class)->where('page', '[A-Za-z0-9\-]+')->name('template');
}

// One-click demo sign-in (landing page "Try Demo Hospital" buttons).
Route::post('demo/{role}', [DemoController::class, 'login'])->middleware('throttle:30,1')->name('demo.login');

Route::get('verify/lab/{code}', [VerificationController::class, 'lab'])->name('verify.lab');
Route::get('files/{path}', FileController::class)->where('path', '.*')->middleware('auth')->name('files.show');
Route::post('impersonate/leave', [ImpersonationController::class, 'leave'])->middleware('auth')->name('impersonate.leave');

/*
|--------------------------------------------------------------------------
| Super Admin (platform)  /admin
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Volt::route('login', 'auth.admin-login')->middleware('guest')->name('login');

    Route::middleware(['auth', 'super_admin'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Volt::route('/', 'admin.dashboard')->name('dashboard');
        Volt::route('hospitals', 'admin.hospitals.index')->name('hospitals.index');
        Volt::route('hospitals/create', 'admin.hospitals.create')->name('hospitals.create');
        Volt::route('hospitals/{hospital}', 'admin.hospitals.show')->name('hospitals.show');
        Volt::route('plans', 'admin.plans')->name('plans');
        Volt::route('invoices', 'admin.invoices')->name('invoices');
        Volt::route('settings', 'admin.settings')->name('settings');
        Volt::route('audit', 'admin.audit')->name('audit');

        Route::post('hospitals/{hospital}/impersonate', [ImpersonationController::class, 'start'])->name('impersonate');
        Route::get('invoices/{invoiceId}/pdf', [PdfController::class, 'subscriptionInvoice'])->name('invoices.pdf');
    });
});

/*
|--------------------------------------------------------------------------
| Hospital (tenant)  /h/{hospital-slug}
|--------------------------------------------------------------------------
*/
Route::prefix('h/{hospital}')->middleware('tenant')->group(function () {

    // Public token board for waiting-room TVs (protected by a per-hospital display key).
    Volt::route('queue-display', 'tenant.queue.display')->name('tenant.queue.display');

    Route::name('tenant.')->group(function () {
        Volt::route('login', 'auth.tenant-login')->middleware('guest')->name('login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
        Route::redirect('/', 'dashboard');

        Route::middleware(['auth', 'staff'])->group(function () {
            Volt::route('dashboard', 'tenant.dashboard')->name('dashboard');
            Volt::route('profile', 'tenant.profile')->name('profile');
            Volt::route('subscription', 'tenant.subscription')->middleware('can:subscription.manage')->name('subscription');

            // Patients & EMR
            Route::middleware('can:patients.view')->group(function () {
                Volt::route('patients', 'tenant.patients.index')->name('patients.index');
                Volt::route('patients/create', 'tenant.patients.form')->middleware('can:patients.create')->name('patients.create');
                Volt::route('patients/{patient}/edit', 'tenant.patients.form')->middleware('can:patients.update')->name('patients.edit');
                Volt::route('patients/{patient}', 'tenant.patients.show')->name('patients.show');
                Route::get('patients/{patientId}/card', [PdfController::class, 'patientCard'])->name('patients.card');
            });

            // Appointments & queue
            Route::middleware('module:appointments')->group(function () {
                Volt::route('appointments', 'tenant.appointments.index')->middleware('can:appointments.view')->name('appointments.index');
                Volt::route('queue', 'tenant.queue.index')->middleware('can:queue.manage')->name('queue.index');
                Volt::route('schedules', 'tenant.appointments.schedules')->middleware('can:schedules.manage')->name('schedules.index');
            });

            // OPD / doctor portal
            Route::middleware('module:opd')->group(function () {
                Volt::route('doctor/workspace', 'tenant.doctor.workspace')->middleware('can:opd.consult')->name('doctor.workspace');
                Volt::route('opd', 'tenant.opd.index')->middleware('can:opd.view')->name('opd.index');
                Volt::route('opd/{visit}/consult', 'tenant.opd.consult')->middleware('can:opd.consult')->name('opd.consult');
                Volt::route('prescriptions', 'tenant.opd.prescriptions')->middleware('can:prescriptions.view')->name('prescriptions.index');
                Route::get('prescriptions/{prescriptionId}/pdf', [PdfController::class, 'prescription'])->middleware('can:prescriptions.view')->name('prescriptions.pdf');
                Route::get('opd/{visitId}/slip', [PdfController::class, 'opdSlip'])->middleware('can:opd.view')->name('opd.slip');
            });

            // IPD / wards
            Route::middleware('module:ipd')->prefix('ipd')->name('ipd.')->group(function () {
                Volt::route('/', 'tenant.ipd.index')->middleware('can:ipd.view')->name('index');
                Volt::route('admit', 'tenant.ipd.admit')->middleware('can:ipd.admit')->name('admit');
                Volt::route('beds', 'tenant.ipd.beds')->middleware('can:ipd.view')->name('beds');
                Volt::route('nurse-station', 'tenant.ipd.nurse-station')->middleware('can:ipd.vitals')->name('nurse-station');
                Volt::route('wards', 'tenant.ipd.wards')->middleware('can:beds.manage')->name('wards');
                Volt::route('{admission}', 'tenant.ipd.show')->middleware('can:ipd.view')->name('show');
                Route::get('{admissionId}/discharge-summary', [PdfController::class, 'dischargeSummary'])->middleware('can:ipd.view')->name('discharge-summary');
            });

            // Pharmacy
            Route::middleware('module:pharmacy')->prefix('pharmacy')->name('pharmacy.')->group(function () {
                Volt::route('pos', 'tenant.pharmacy.pos')->middleware('can:pharmacy.sell')->name('pos');
                Volt::route('dispense', 'tenant.pharmacy.dispense')->middleware('can:pharmacy.dispense')->name('dispense');
                Volt::route('sales', 'tenant.pharmacy.sales')->middleware('can:pharmacy.view')->name('sales');
                Volt::route('medicines', 'tenant.pharmacy.medicines')->middleware('can:pharmacy.view')->name('medicines');
                Volt::route('stock', 'tenant.pharmacy.stock')->middleware('can:pharmacy.view')->name('stock');
                Volt::route('purchase-orders', 'tenant.pharmacy.purchase-orders')->middleware('can:pharmacy.purchase')->name('purchase-orders');
                Volt::route('purchase-orders/{purchaseOrder}', 'tenant.pharmacy.purchase-order')->middleware('can:pharmacy.purchase')->name('purchase-order');
                Volt::route('suppliers', 'tenant.pharmacy.suppliers')->middleware('can:pharmacy.purchase')->name('suppliers');
                Route::get('sales/{saleId}/receipt', [PdfController::class, 'pharmacyReceipt'])->middleware('can:pharmacy.view')->name('receipt');
            });

            // Laboratory
            Route::middleware('module:laboratory')->prefix('lab')->name('lab.')->group(function () {
                Volt::route('orders', 'tenant.lab.orders')->middleware('can:lab.view')->name('orders');
                Volt::route('orders/{labOrder}', 'tenant.lab.order')->middleware('can:lab.view')->name('order');
                Volt::route('tests', 'tenant.lab.tests')->middleware('can:lab.manage_tests')->name('tests');
                Volt::route('qc', 'tenant.lab.qc')->middleware('can:lab.qc')->name('qc');
                Volt::route('devices', 'tenant.lab.devices')->middleware('can:lab.qc')->name('devices');
                Route::get('orders/{orderId}/report', [PdfController::class, 'labReport'])->middleware('can:lab.view')->name('report');
                Route::get('orders/{orderId}/labels', [PdfController::class, 'labLabels'])->middleware('can:lab.collect_sample')->name('labels');
            });

            // Radiology
            Route::middleware('module:radiology')->prefix('radiology')->name('radiology.')->group(function () {
                Volt::route('orders', 'tenant.radiology.orders')->middleware('can:radiology.view')->name('orders');
                Volt::route('orders/{radiologyOrder}', 'tenant.radiology.order')->middleware('can:radiology.view')->name('order');
                Volt::route('tests', 'tenant.radiology.tests')->middleware('can:radiology.manage_tests')->name('tests');
                Route::get('orders/{orderId}/report', [PdfController::class, 'radiologyReport'])->middleware('can:radiology.view')->name('report');
            });

            // Blood bank
            Route::middleware(['module:bloodbank', 'can:bloodbank.view'])->prefix('blood-bank')->name('bloodbank.')->group(function () {
                Volt::route('/', 'tenant.bloodbank.inventory')->name('inventory');
                Volt::route('donors', 'tenant.bloodbank.donors')->name('donors');
                Volt::route('requests', 'tenant.bloodbank.requests')->name('requests');
            });

            // Operation theater
            Route::middleware(['module:ot', 'can:ot.view'])->prefix('ot')->name('ot.')->group(function () {
                Volt::route('/', 'tenant.ot.index')->name('index');
                Volt::route('rooms', 'tenant.ot.rooms')->middleware('can:ot.manage')->name('rooms');
                Volt::route('surgeries/{surgery}', 'tenant.ot.show')->name('show');
            });

            // Telemedicine
            Route::middleware(['module:telemedicine', 'can:telemedicine.conduct'])->prefix('telemedicine')->name('telemedicine.')->group(function () {
                Volt::route('/', 'tenant.telemedicine.index')->name('index');
                Volt::route('room/{appointment}', 'tenant.telemedicine.room')->name('room');
            });

            // Billing & finance
            Route::middleware('module:billing')->prefix('billing')->name('billing.')->group(function () {
                Volt::route('invoices', 'tenant.billing.invoices')->middleware('can:billing.view')->name('invoices');
                Volt::route('invoices/create', 'tenant.billing.create')->middleware('can:billing.create')->name('create');
                Volt::route('invoices/{invoice}', 'tenant.billing.show')->middleware('can:billing.view')->name('show');
                Volt::route('payments', 'tenant.billing.payments')->middleware('can:billing.view')->name('payments');
                Volt::route('claims', 'tenant.billing.claims')->middleware('can:insurance.manage')->name('claims');
                Volt::route('expenses', 'tenant.billing.expenses')->middleware('can:expenses.manage')->name('expenses');
                Volt::route('services', 'tenant.billing.services')->middleware('can:billing.create')->name('services');
                Volt::route('reports', 'tenant.billing.reports')->middleware('can:billing.reports')->name('reports');
                Route::get('invoices/{invoiceId}/pdf', [PdfController::class, 'invoice'])->middleware('can:billing.view')->name('pdf');
            });

            // Banks & Cash (every plan: all money movements post here)
            Route::prefix('banks')->name('banks.')->middleware('can:banks.view')->group(function () {
                Volt::route('/', 'tenant.banks.index')->name('index');
                Volt::route('{account}', 'tenant.banks.show')->name('show');
            });

            // HR & payroll
            Route::middleware('module:hr')->prefix('hr')->name('hr.')->group(function () {
                Volt::route('staff', 'tenant.hr.staff')->middleware('can:hr.staff')->name('staff');
                Volt::route('shifts', 'tenant.hr.shifts')->middleware('can:hr.shifts')->name('shifts');
                Volt::route('attendance', 'tenant.hr.attendance')->middleware('can:hr.attendance')->name('attendance');
                Volt::route('payroll', 'tenant.hr.payroll')->middleware('can:hr.payroll')->name('payroll');
                Volt::route('commissions', 'tenant.hr.commissions')->middleware('can:hr.commissions')->name('commissions');
                Route::get('payroll/{payrollId}/payslip', [PdfController::class, 'payslip'])->middleware('can:hr.payroll')->name('payslip');
            });

            // Administration
            Route::prefix('settings')->name('settings.')->group(function () {
                Volt::route('profile', 'tenant.settings.profile')->middleware('can:settings.manage')->name('profile');
                Volt::route('users', 'tenant.settings.users')->middleware('can:users.manage')->name('users');
                Volt::route('roles', 'tenant.settings.roles')->middleware('can:roles.manage')->name('roles');
                Volt::route('departments', 'tenant.settings.departments')->middleware('can:departments.manage')->name('departments');
                Volt::route('insurance-companies', 'tenant.settings.tpas')->name('tpas');
                Volt::route('audit-logs', 'tenant.settings.audit')->middleware('can:audit.view')->name('audit');
            });
        });
    });

    /*
    | Patient portal  /h/{hospital}/portal
    */
    Route::prefix('portal')->name('portal.')->group(function () {
        Volt::route('login', 'auth.portal-login')->middleware('guest')->name('login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

        Route::middleware(['auth', 'patient'])->group(function () {
            Volt::route('/', 'portal.dashboard')->name('dashboard');
            Volt::route('appointments', 'portal.appointments')->name('appointments');
            Volt::route('lab-reports', 'portal.lab-reports')->name('lab-reports');
            Volt::route('prescriptions', 'portal.prescriptions')->name('prescriptions');
            Volt::route('history', 'portal.history')->name('history');
            Volt::route('bills', 'portal.bills')->name('bills');
            Volt::route('profile', 'portal.profile')->name('profile');
            Volt::route('video/{appointment}', 'portal.video')->name('video');
            Route::get('lab-reports/{orderId}/pdf', [PdfController::class, 'portalLabReport'])->name('lab-report.pdf');
            Route::get('prescriptions/{prescriptionId}/pdf', [PdfController::class, 'portalPrescription'])->name('prescription.pdf');
            Route::get('bills/{invoiceId}/pdf', [PdfController::class, 'portalInvoice'])->name('bill.pdf');
        });
    });
});
