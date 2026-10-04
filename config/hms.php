<?php

/*
|--------------------------------------------------------------------------
| HMS platform configuration
|--------------------------------------------------------------------------
| Modules are the unit that subscription plans grant. Every permission
| belongs to exactly one module; a hospital can only use (and grant to its
| roles) permissions whose module is included in its plan. "core" modules
| are always available.
*/

return [

    'template_demo' => env('HMS_TEMPLATE_DEMO', true),

    'super_admin_email' => env('HMS_SUPER_ADMIN_EMAIL', 'superadmin@hms.test'),

    'sms_driver' => env('HMS_SMS_DRIVER', 'log'),

    'telemedicine' => [
        'driver' => env('HMS_TELEMEDICINE_DRIVER', 'jitsi'),
        'jitsi_domain' => env('HMS_JITSI_DOMAIN', 'meet.jit.si'),
        'agora_app_id' => env('HMS_AGORA_APP_ID'),
        'agora_app_certificate' => env('HMS_AGORA_APP_CERTIFICATE'),
    ],

    // Days after an unpaid subscription invoice is due before the hospital is suspended.
    'suspension_grace_days' => 7,

    // Days before subscription end at which the renewal invoice is generated.
    'invoice_lead_days' => 7,

    'pharmacy_expiry_alert_days' => 90,

    'modules' => [
        'core' => [
            'label' => 'Administration',
            'core' => true,
            'permissions' => [
                'settings.manage' => 'Manage hospital profile & settings',
                'users.manage' => 'Manage user accounts',
                'roles.manage' => 'Manage roles & permissions',
                'departments.manage' => 'Manage departments',
                'audit.view' => 'View audit logs',
                'subscription.manage' => 'View subscription & pay invoices',
                'reports.view' => 'View dashboards & reports',
            ],
        ],
        'patients' => [
            'label' => 'Patients & EMR',
            'core' => true,
            'permissions' => [
                'patients.view' => 'View patients',
                'patients.create' => 'Register patients',
                'patients.update' => 'Edit patients',
                'patients.delete' => 'Delete patients',
                'emr.view' => 'View medical records',
                'emr.manage' => 'Record vitals, notes, diagnoses, allergies & history',
                'emr.documents' => 'Upload & manage patient documents',
            ],
        ],
        'appointments' => [
            'label' => 'Appointments & Queue',
            'permissions' => [
                'appointments.view' => 'View appointments',
                'appointments.manage' => 'Book, reschedule & cancel appointments',
                'schedules.manage' => 'Manage doctor schedules',
                'queue.manage' => 'Manage OPD tokens & queue',
            ],
        ],
        'opd' => [
            'label' => 'OPD & Doctor Portal',
            'permissions' => [
                'opd.view' => 'View OPD visits',
                'opd.create' => 'Create OPD visits',
                'opd.consult' => 'Conduct consultations',
                'prescriptions.view' => 'View prescriptions',
                'prescriptions.create' => 'Write e-prescriptions',
            ],
        ],
        'ipd' => [
            'label' => 'IPD & Bed Management',
            'permissions' => [
                'ipd.view' => 'View admissions & bed matrix',
                'ipd.admit' => 'Admit patients',
                'ipd.transfer' => 'Transfer beds',
                'ipd.charges' => 'Add IPD charges',
                'ipd.vitals' => 'Nurse station vitals & notes',
                'ipd.discharge' => 'Discharge patients',
                'beds.manage' => 'Manage wards & beds',
            ],
        ],
        'pharmacy' => [
            'label' => 'Pharmacy',
            'permissions' => [
                'pharmacy.view' => 'View pharmacy',
                'pharmacy.sell' => 'POS billing',
                'pharmacy.dispense' => 'Dispense prescriptions',
                'pharmacy.inventory' => 'Manage medicines & stock',
                'pharmacy.purchase' => 'Purchase orders & suppliers',
                'pharmacy.reports' => 'Pharmacy reports',
            ],
        ],
        'laboratory' => [
            'label' => 'Laboratory',
            'permissions' => [
                'lab.view' => 'View lab orders',
                'lab.order' => 'Order lab tests',
                'lab.collect_sample' => 'Collect & tag samples',
                'lab.enter_results' => 'Enter results',
                'lab.approve_reports' => 'Approve & sign reports',
                'lab.manage_tests' => 'Manage test catalog',
                'lab.qc' => 'Quality control & devices',
            ],
        ],
        'radiology' => [
            'label' => 'Radiology & Imaging',
            'permissions' => [
                'radiology.view' => 'View imaging orders',
                'radiology.order' => 'Order imaging',
                'radiology.perform' => 'Perform scans & upload images',
                'radiology.report' => 'Write & approve radiology reports',
                'radiology.manage_tests' => 'Manage imaging catalog',
            ],
        ],
        'billing' => [
            'label' => 'Billing & Finance',
            'permissions' => [
                'billing.view' => 'View invoices',
                'billing.create' => 'Create invoices',
                'billing.collect' => 'Collect payments',
                'billing.cancel' => 'Cancel invoices & refunds',
                'billing.reports' => 'Financial dashboards & tax reports',
                'insurance.manage' => 'TPA & insurance claims',
                'expenses.manage' => 'Manage expenses',
            ],
        ],
        'hr' => [
            'label' => 'HR & Payroll',
            'permissions' => [
                'hr.staff' => 'Manage staff directory',
                'hr.shifts' => 'Manage shifts',
                'hr.attendance' => 'Mark attendance',
                'hr.payroll' => 'Process payroll',
                'hr.commissions' => 'Doctor commissions',
            ],
        ],
        'ot' => [
            'label' => 'Operation Theater',
            'permissions' => [
                'ot.view' => 'View OT schedule',
                'ot.manage' => 'Schedule surgeries & checklists',
            ],
        ],
        'bloodbank' => [
            'label' => 'Blood Bank',
            'permissions' => [
                'bloodbank.view' => 'View blood bank',
                'bloodbank.manage' => 'Donors, inventory, cross-match & issue',
            ],
        ],
        'telemedicine' => [
            'label' => 'Telemedicine',
            'permissions' => [
                'telemedicine.conduct' => 'Conduct video consultations',
            ],
        ],
        'portal' => [
            'label' => 'Patient Portal',
            'permissions' => [
                'portal.access' => 'Access patient portal',
            ],
        ],
    ],

    /*
    | Default roles created for every new hospital. "*" = every permission
    | in the hospital's plan. Hospital Admin also bypasses checks via Gate::before.
    */
    'default_roles' => [
        'Hospital Admin' => ['*'],
        'Doctor' => [
            'reports.view', 'patients.view', 'patients.update', 'emr.view', 'emr.manage', 'emr.documents',
            'appointments.view', 'opd.view', 'opd.consult', 'prescriptions.view', 'prescriptions.create',
            'ipd.view', 'ipd.vitals', 'ipd.discharge', 'lab.view', 'lab.order', 'lab.approve_reports',
            'radiology.view', 'radiology.order', 'ot.view', 'bloodbank.view', 'telemedicine.conduct',
        ],
        'Nurse' => [
            'patients.view', 'emr.view', 'emr.manage', 'appointments.view', 'queue.manage', 'opd.view',
            'ipd.view', 'ipd.vitals', 'ipd.charges', 'ipd.transfer', 'lab.view', 'lab.collect_sample',
            'ot.view', 'bloodbank.view', 'prescriptions.view',
        ],
        'Receptionist' => [
            'patients.view', 'patients.create', 'patients.update', 'emr.documents', 'appointments.view',
            'appointments.manage', 'queue.manage', 'opd.view', 'opd.create', 'ipd.view', 'ipd.admit',
            'billing.view', 'billing.create', 'billing.collect', 'lab.view', 'lab.order', 'radiology.view',
            'radiology.order',
        ],
        'Pharmacist' => [
            'patients.view', 'prescriptions.view', 'pharmacy.view', 'pharmacy.sell', 'pharmacy.dispense',
            'pharmacy.inventory', 'pharmacy.purchase', 'pharmacy.reports',
        ],
        'Lab Technician' => [
            'patients.view', 'lab.view', 'lab.collect_sample', 'lab.enter_results', 'lab.qc',
        ],
        'Radiologist' => [
            'patients.view', 'emr.view', 'radiology.view', 'radiology.perform', 'radiology.report',
        ],
        'Accountant' => [
            'reports.view', 'patients.view', 'billing.view', 'billing.create', 'billing.collect', 'billing.cancel',
            'billing.reports', 'insurance.manage', 'expenses.manage', 'hr.payroll', 'hr.commissions',
            'pharmacy.reports',
        ],
        'HR Manager' => [
            'hr.staff', 'hr.shifts', 'hr.attendance', 'hr.payroll', 'hr.commissions', 'departments.manage',
        ],
        'Patient' => ['portal.access'],
    ],

    // Roles a hospital cannot delete or rename.
    'protected_roles' => ['Hospital Admin', 'Patient'],

    'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],

    'genders' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],

    'currencies' => [
        'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'PKR' => 'Rs', 'INR' => '₹', 'AED' => 'AED', 'SAR' => 'SAR',
        'BDT' => '৳', 'NGN' => '₦', 'KES' => 'KSh', 'ZAR' => 'R', 'CAD' => 'C$', 'AUD' => 'A$',
    ],
];
