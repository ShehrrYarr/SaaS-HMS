<?php

use App\Models\Bed;
use App\Models\Hospital;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Plan;
use App\Models\Staff;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.landing')] class extends Component
{
    public string $hospital = '';

    public string $target = 'staff';

    public function go()
    {
        $this->validate(['hospital' => 'required|string|max:100'], [], ['hospital' => 'hospital code']);

        $value = trim(strtolower($this->hospital));
        $found = Hospital::where('slug', $value)->orWhere('code', strtoupper($value))->first();

        if (! $found) {
            $this->addError('hospital', 'No hospital found with that code.');

            return;
        }

        return $this->redirect(route($this->target === 'patient' ? 'portal.login' : 'tenant.login', ['hospital' => $found->slug]));
    }

    public function with(): array
    {
        $demo = config('hms.demo.enabled') ? Hospital::where('slug', config('hms.demo.hospital'))->first() : null;

        return [
            'platform' => platform_setting('platform_name', config('app.name')),
            'supportEmail' => platform_setting('support_email'),
            'demo' => $demo,
            'demoStats' => $demo ? tenancy()->run($demo, fn () => [
                'doctors' => Staff::doctors()->count(),
                'patients' => Patient::count(),
                'beds' => Bed::count(),
                'medicines' => Medicine::count(),
                'tests' => LabTest::count(),
            ]) : null,
            'accounts' => config('hms.demo.accounts'),
            'resetHours' => config('hms.demo.reset_every_hours'),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'moduleLabels' => collect(config('hms.modules'))->map(fn ($m) => $m['label']),
            'modules' => [
                ['ri-user-heart-line', 'primary', 'Patients & EMR', ['Quick & full registration with unique UHID', 'Vitals trends, SOAP notes, ICD-10 diagnoses', 'Allergies, history & document vault']],
                ['ri-calendar-check-line', 'info', 'Appointments & Queue', ['Doctor schedules, leaves & free-slot booking', 'Walk-in tokens & live OPD queue', 'Waiting-room TV token display']],
                ['ri-stethoscope-line', 'success', 'Doctor Portal', ['Personal workspace & consultation screen', 'e-Prescriptions routed to the pharmacy', 'Lab & imaging orders in one click']],
                ['ri-hotel-bed-line', 'warning', 'IPD & Bed Management', ['Real-time bed matrix (ICU, wards, rooms)', 'Transfers, daily charges & nurse station', 'Discharge summary & consolidated bill']],
                ['ri-capsule-line', 'danger', 'Pharmacy & POS', ['Barcode POS with instant receipts', 'Batch & expiry tracking (FEFO)', 'Purchase orders, suppliers, re-order alerts']],
                ['ri-flask-line', 'secondary', 'Laboratory', ['Test catalog with reference ranges', 'Sample barcodes, results & auto flags', 'Signed PDF reports with QR verification']],
                ['ri-scan-2-line', 'primary', 'Radiology & PACS', ['X-Ray, CT, MRI & ultrasound worklist', 'DICOM / image uploads', 'Radiologist reporting & PACS viewer link']],
                ['ri-bill-line', 'success', 'Billing & Insurance', ['One bill for OPD, IPD, pharmacy & lab', 'TPA / insurance claim tracking', 'Expenses, finance dashboard & tax report']],
                ['ri-team-line', 'info', 'HR & Payroll', ['Staff directory, shifts & roster', 'Attendance & monthly payroll', 'Doctor commission / fee splitting']],
                ['ri-surgical-mask-line', 'warning', 'Operation Theater', ['OT scheduling with room conflict checks', 'Surgical team & WHO checklists', 'Charges posted to the patient bill']],
                ['ri-drop-line', 'danger', 'Blood Bank', ['Donor registry with eligibility rules', 'Component inventory & screening', 'ABO/Rh-aware cross-match & issue']],
                ['ri-video-chat-line', 'secondary', 'Telemedicine & Portal', ['Video consultations', 'Patients book visits & download reports', 'Bills, prescriptions & history online']],
            ],
        ];
    }
}; ?>

<div>
    {{-- ================================================================ NAV --}}
    <nav class="navbar navbar-expand-lg fixed-top lp-nav py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 lp-brand" href="{{ route('home') }}">
                <img src="{{ asset('assets/images/Favicon.png') }}" height="32" alt=""> {{ $platform }}
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#lpNav" aria-label="Menu"><i class="ri-menu-line fs-4"></i></button>
            <div class="collapse navbar-collapse" id="lpNav">
                <ul class="navbar-nav mx-auto gap-lg-2">
                    <li class="nav-item"><a class="nav-link" href="#modules">Features</a></li>
                    <li class="nav-item"><a class="nav-link" href="#journey">How it works</a></li>
                    @if ($demo)<li class="nav-item"><a class="nav-link" href="#demo">Live demo</a></li>@endif
                    <li class="nav-item"><a class="nav-link" href="#pricing">Pricing</a></li>
                    <li class="nav-item"><a class="nav-link" href="#signin">Sign in</a></li>
                </ul>
                <div class="d-flex gap-2 mt-3 mt-lg-0">
                    @auth
                        <a href="{{ auth()->user()->homeUrl() }}" class="btn btn-primary"><i class="ri-dashboard-3-line me-1"></i>My dashboard</a>
                    @else
                        <a href="#signin" class="btn btn-light">Hospital sign in</a>
                        @if ($demo)
                            <form method="POST" action="{{ route('demo.login', 'admin') }}">@csrf<button class="btn btn-primary"><i class="ri-play-circle-line me-1"></i>Try Demo</button></form>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </nav>

    {{-- ================================================================ HERO --}}
    <header class="lp-hero">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="lp-eyebrow"><i class="ri-hospital-line"></i> Cloud hospital management · Multi-hospital SaaS</span>
                    <h1 class="mt-3 mb-3">Run every department of your hospital from one secure platform</h1>
                    <p class="lead mb-4">Registration to discharge: EMR, appointments, OPD &amp; IPD, pharmacy POS, laboratory, radiology, billing &amp; insurance, HR, operation theater, blood bank, telemedicine and a patient portal, connected in real time.</p>
                    <div class="d-flex flex-wrap gap-2">
                        @if ($demo)
                            <form method="POST" action="{{ route('demo.login', 'admin') }}">
                                @csrf
                                <button class="btn btn-primary btn-lg px-4"><i class="ri-hospital-line me-1"></i> Try Demo Hospital <small class="opacity-75">as Admin</small></button>
                            </form>
                            <a href="#demo" class="btn btn-light btn-lg px-4"><i class="ri-user-shared-line me-1"></i> Try another role</a>
                        @else
                            <a href="#signin" class="btn btn-primary btn-lg px-4">Sign in to your hospital</a>
                        @endif
                    </div>
                    @if ($demo)
                        <p class="text-muted fs-13 mt-3 mb-0"><i class="ri-shield-check-line text-success"></i> No sign-up or password. Explore <strong>{{ $demo->name }}</strong> with realistic data. The demo resets every {{ $resetHours }} hours.</p>
                    @endif
                </div>
                <div class="col-lg-6">
                    {{-- Product preview (pure CSS mock-up of the dashboard) --}}
                    <div class="lp-mock" aria-hidden="true">
                        <div class="lp-mock-bar"><span></span><span></span><span></span></div>
                        <div class="lp-mock-body">
                            <div class="lp-mock-side"><i class="ri-dashboard-3-line"></i><i class="ri-user-heart-line"></i><i class="ri-calendar-check-line"></i><i class="ri-hotel-bed-line"></i><i class="ri-capsule-line"></i><i class="ri-flask-line"></i><i class="ri-bill-line"></i></div>
                            <div class="lp-mock-main">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <strong class="fs-13">Welcome back, Dr. Ahmed</strong>
                                    <span class="badge bg-success-subtle text-success">Live</span>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6 col-md-3"><div class="lp-tile"><small>Patients today</small><b>48</b></div></div>
                                    <div class="col-6 col-md-3"><div class="lp-tile"><small>Bed occupancy</small><b>82%</b></div></div>
                                    <div class="col-6 col-md-3"><div class="lp-tile"><small>Lab pending</small><b>12</b></div></div>
                                    <div class="col-6 col-md-3"><div class="lp-tile"><small>Collections</small><b>Rs 3.2 lac</b></div></div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-7"><div class="lp-tile h-100"><small>OPD visits · 14 days</small>
                                        <div class="lp-bars mt-2">@foreach ([40, 55, 48, 70, 62, 80, 58, 66, 90, 74, 85, 60, 78, 95] as $h)<span style="height: {{ $h }}%"></span>@endforeach</div></div></div>
                                    <div class="col-5"><div class="lp-tile h-100"><small>Now serving</small>
                                        <div class="text-center py-2"><div class="fs-1 fw-bold text-primary lh-1">#14</div><div class="fs-12 text-muted">Cardiology · Room 2</div></div></div></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-5 text-center">
                <div class="col-6 col-md-3"><div class="lp-stat">13</div><div class="text-muted fs-13">integrated modules</div></div>
                <div class="col-6 col-md-3"><div class="lp-stat">10</div><div class="text-muted fs-13">ready-made roles, plus your own</div></div>
                <div class="col-6 col-md-3"><div class="lp-stat">100%</div><div class="text-muted fs-13">data isolation per hospital</div></div>
                <div class="col-6 col-md-3"><div class="lp-stat">0</div><div class="text-muted fs-13">installs: runs in the browser</div></div>
            </div>
        </div>
    </header>

    {{-- ================================================================ LIVE DEMO --}}
    @if ($demo)
        <section id="demo" class="lp-section">
            <div class="container">
                <div class="text-center mb-5">
                    <div class="lp-kicker mb-2">Live demo</div>
                    <h2>Try every role in one click</h2>
                    <p class="text-muted mx-auto" style="max-width: 640px;">Step into <strong>{{ $demo->name }}</strong>, a fully working hospital with
                        {{ $demoStats['doctors'] }} doctors, {{ $demoStats['patients'] }} patients, {{ $demoStats['beds'] }} beds, {{ $demoStats['medicines'] }} medicines and {{ $demoStats['tests'] }} lab tests.
                        Each role sees only the screens its permissions allow. Switch roles any time from the banner inside the app.</p>
                </div>
                <div class="row g-4">
                    @foreach ($accounts as $key => $account)
                        <div class="col-sm-6 col-lg-3">
                            <div class="lp-card p-4 d-flex flex-column {{ $key === 'admin' ? 'border-primary' : '' }}">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <span class="lp-icon bg-{{ $account['color'] }}-subtle text-{{ $account['color'] }}"><i class="{{ $account['icon'] }}"></i></span>
                                    <div><h6 class="mb-0">{{ $account['label'] }}</h6>@if ($key === 'admin')<span class="badge bg-primary-subtle text-primary">Recommended</span>@endif</div>
                                </div>
                                <p class="text-muted fs-13 flex-grow-1">{{ $account['blurb'] }}</p>
                                <form method="POST" action="{{ route('demo.login', $key) }}">
                                    @csrf
                                    <button class="btn {{ $key === 'admin' ? 'btn-primary' : 'btn-light-'.$account['color'] }} w-100">Enter as {{ $account['label'] }} <i class="ri-arrow-right-line"></i></button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="text-center text-muted fs-12 mt-4 mb-0"><i class="ri-information-line"></i> The demo is shared by all visitors. Changes are welcome and are wiped every {{ $resetHours }} hours. Account credentials and hospital branding are locked.</p>
            </div>
        </section>
    @endif

    {{-- ================================================================ MODULES --}}
    <section id="modules" class="lp-section lp-section-alt">
        <div class="container">
            <div class="text-center mb-5">
                <div class="lp-kicker mb-2">Everything in one place</div>
                <h2>Built for every department</h2>
                <p class="text-muted mx-auto" style="max-width: 640px;">Each module shares one patient record, so a prescription reaches the pharmacy, a lab order reaches the lab and every service lands on the same bill, with no re-typing.</p>
            </div>
            <div class="row g-4">
                @foreach ($modules as [$icon, $color, $title, $points])
                    <div class="col-md-6 col-lg-4 col-xl-3">
                        <div class="lp-card p-4">
                            <span class="lp-icon bg-{{ $color }}-subtle text-{{ $color }} mb-3"><i class="{{ $icon }}"></i></span>
                            <h6 class="mb-2">{{ $title }}</h6>
                            <ul>@foreach ($points as $p)<li>{{ $p }}</li>@endforeach</ul>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================ JOURNEY --}}
    <section id="journey" class="lp-section">
        <div class="container">
            <div class="text-center mb-5">
                <div class="lp-kicker mb-2">How it works</div>
                <h2>One connected patient journey</h2>
            </div>
            <div class="row g-4">
                @foreach ([
                    ['ri-user-add-line', 'Register', 'Quick or full registration issues a UHID and an ID card in seconds.'],
                    ['ri-coupon-3-line', 'Book & queue', 'Online or walk-in booking, token issued, live queue on the waiting-room TV.'],
                    ['ri-stethoscope-line', 'Consult', 'Doctor records vitals, notes and ICD-10 diagnosis and writes an e-prescription.'],
                    ['ri-flask-line', 'Diagnose & dispense', 'Orders flow to the lab, radiology and pharmacy instantly, and stock updates itself.'],
                    ['ri-hotel-bed-line', 'Admit & treat', 'Bed allocation, nursing vitals, OT and blood bank, with every charge tracked.'],
                    ['ri-secure-payment-line', 'Bill & follow up', 'One consolidated bill and insurance claim; reports in the patient portal.'],
                ] as [$icon, $title, $text])
                    <div class="col-6 col-lg-2 lp-step">
                        <span class="lp-icon mb-3"><i class="{{ $icon }}"></i></span>
                        <h6 class="mb-1">{{ $title }}</h6>
                        <p class="text-muted fs-13 mb-0">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ================================================================ PLATFORM --}}
    <section class="lp-section lp-section-alt">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-6">
                    <div class="lp-card p-4 p-lg-5">
                        <div class="lp-kicker mb-2">For hospital groups &amp; providers</div>
                        <h3 class="fw-bold mb-3">One platform, many hospitals</h3>
                        <ul class="list-unstyled fs-14 mb-0">
                            <li class="mb-2"><i class="ri-checkbox-circle-fill text-success me-2"></i>Each hospital gets its own workspace, branding, tax and timezone, with amounts in whole rupees (Rs)</li>
                            <li class="mb-2"><i class="ri-checkbox-circle-fill text-success me-2"></i>Subscription plans unlock modules; upgrade any time</li>
                            <li class="mb-2"><i class="ri-checkbox-circle-fill text-success me-2"></i>Super Admin console: onboarding, invoicing, analytics, storage</li>
                            <li class="mb-2"><i class="ri-checkbox-circle-fill text-success me-2"></i>Custom roles with per-module permissions</li>
                            <li><i class="ri-checkbox-circle-fill text-success me-2"></i>Works on desktop, tablet and phone, with nothing to install</li>
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="lp-card p-4 p-lg-5">
                        <div class="lp-kicker mb-2">Security &amp; trust</div>
                        <h3 class="fw-bold mb-3">Patient data stays where it belongs</h3>
                        <ul class="list-unstyled fs-14 mb-0">
                            <li class="mb-2"><i class="ri-lock-2-fill text-primary me-2"></i>Strict per-hospital isolation on every query, upload and report</li>
                            <li class="mb-2"><i class="ri-file-list-3-fill text-primary me-2"></i>Full audit trail of who changed what, and when</li>
                            <li class="mb-2"><i class="ri-qr-code-fill text-primary me-2"></i>Lab reports with QR verification and optional digital signatures</li>
                            <li class="mb-2"><i class="ri-shield-user-fill text-primary me-2"></i>Role-based access; staff only see what their job needs</li>
                            <li><i class="ri-folder-shield-2-fill text-primary me-2"></i>Private document storage, never publicly linked</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================ PRICING --}}
    @if ($plans->isNotEmpty())
        <section id="pricing" class="lp-section">
            <div class="container">
                <div class="text-center mb-5">
                    <div class="lp-kicker mb-2">Pricing</div>
                    <h2>Simple plans that grow with you</h2>
                    <p class="text-muted">Patients &amp; EMR and administration are included in every plan.</p>
                </div>
                <div class="row g-4 justify-content-center">
                    @foreach ($plans as $i => $plan)
                        @php $featured = $plans->count() > 2 ? $i === 1 : $loop->last; @endphp
                        <div class="col-md-6 col-lg-4">
                            <div class="lp-price {{ $featured ? 'featured' : '' }}">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="mb-0">{{ $plan->name }}</h5>
                                    @if ($featured)<span class="badge bg-primary">Most popular</span>@endif
                                </div>
                                <p class="text-muted fs-13">{{ $plan->description }}</p>
                                <div class="mb-1"><span class="amount">{{ money($plan->price_monthly) }}</span><span class="text-muted"> / month</span></div>
                                <p class="text-muted fs-12">or {{ money($plan->price_yearly) }} / year · {{ $plan->trial_days }}-day free trial</p>
                                <ul class="list-unstyled fs-13 mb-4">
                                    @foreach ($moduleLabels as $key => $label)
                                        @php $included = in_array($key, $plan->modules ?? []) || ! empty(config("hms.modules.{$key}.core")); @endphp
                                        <li class="mb-1 {{ $included ? '' : 'text-muted text-decoration-line-through opacity-50' }}"><i class="{{ $included ? 'ri-check-line text-success' : 'ri-close-line' }} me-1"></i>{{ $label }}</li>
                                    @endforeach
                                </ul>
                                @if ($supportEmail)
                                    <a href="mailto:{{ $supportEmail }}?subject={{ rawurlencode($plan->name.' plan enquiry') }}" class="btn {{ $featured ? 'btn-primary' : 'btn-light-primary' }} w-100">Get started</a>
                                @elseif ($demo)
                                    <form method="POST" action="{{ route('demo.login', 'admin') }}">@csrf<button class="btn {{ $featured ? 'btn-primary' : 'btn-light-primary' }} w-100">Try it in the demo</button></form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- ================================================================ CTA + SIGN IN --}}
    <section id="signin" class="lp-section lp-section-alt">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-6">
                    <div class="lp-cta p-4 p-lg-5 h-100 d-flex flex-column justify-content-center">
                        <h3 class="text-white fw-bold mb-2">See it working in your hands</h3>
                        <p class="mb-4 opacity-75">Open the demo hospital as its administrator and walk a patient from registration to discharge.</p>
                        <div class="d-flex flex-wrap gap-2">
                            @if ($demo)
                                <form method="POST" action="{{ route('demo.login', 'admin') }}">@csrf<button class="btn btn-light btn-lg"><i class="ri-hospital-line me-1"></i>Try Demo Hospital</button></form>
                                <form method="POST" action="{{ route('demo.login', 'patient') }}">@csrf<button class="btn btn-outline-light btn-lg"><i class="ri-user-heart-line me-1"></i>Patient portal demo</button></form>
                            @endif
                            @if ($supportEmail)<a href="mailto:{{ $supportEmail }}" class="btn btn-outline-light btn-lg"><i class="ri-mail-line me-1"></i>Contact us</a>@endif
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="lp-card p-4 p-lg-5">
                        <h4 class="fw-bold mb-1">Already using {{ $platform }}?</h4>
                        <p class="text-muted mb-4">Enter your hospital code to sign in.</p>
                        <form wire:submit="go">
                            <div class="btn-group w-100 mb-3" role="group">
                                <input type="radio" class="btn-check" id="t-staff" value="staff" wire:model="target">
                                <label class="btn btn-outline-primary" for="t-staff"><i class="ri-hospital-line me-1"></i> Staff</label>
                                <input type="radio" class="btn-check" id="t-patient" value="patient" wire:model="target">
                                <label class="btn btn-outline-primary" for="t-patient"><i class="ri-user-heart-line me-1"></i> Patient portal</label>
                            </div>
                            <x-form.input model="hospital" placeholder="Hospital code, e.g. city-hospital or CGH" />
                            <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="go">Continue <i class="ri-arrow-right-line"></i></span>
                                <span wire:loading wire:target="go">Please wait...</span>
                            </button>
                        </form>
                        <div class="d-flex flex-wrap justify-content-between gap-2 mt-4 fs-13">
                            <a href="{{ route('admin.login') }}"><i class="ri-shield-user-line me-1"></i>Platform (Super Admin) login</a>
                            @if (config('hms.template_demo'))<a href="{{ url('template/index') }}" target="_blank" class="text-muted"><i class="ri-layout-masonry-line me-1"></i>UI template reference</a>@endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ================================================================ FOOTER --}}
    <footer class="lp-footer py-5">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="d-flex align-items-center gap-2 mb-2"><img src="{{ asset('assets/images/Favicon.png') }}" height="28" alt=""><strong class="text-white">{{ $platform }}</strong></div>
                    <p class="fs-13 mb-0">Cloud hospital management for clinics, hospitals and healthcare groups.</p>
                </div>
                <div class="col-6 col-md-3">
                    <h6 class="text-white">Product</h6>
                    <ul class="list-unstyled fs-13 mb-0"><li><a href="#modules">Features</a></li><li><a href="#journey">How it works</a></li><li><a href="#pricing">Pricing</a></li></ul>
                </div>
                <div class="col-6 col-md-4">
                    <h6 class="text-white">Get in touch</h6>
                    <ul class="list-unstyled fs-13 mb-0">
                        @if ($supportEmail)<li><a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></li>@endif
                        @if ($phone = platform_setting('support_phone'))<li>{{ $phone }}</li>@endif
                        <li><a href="#signin">Hospital sign in</a></li>
                    </ul>
                </div>
            </div>
            <hr class="border-secondary my-4">
            <p class="fs-12 mb-0">&copy; {{ date('Y') }} {{ $platform }}. All rights reserved.</p>
        </div>
    </footer>
</div>
