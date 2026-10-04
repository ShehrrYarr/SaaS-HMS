<?php

namespace App\Http\Controllers;

/**
 * Serves the original Herozi demo pages (resources/views/template/*) at
 * /template/{page} as a UI reference. Disable with HMS_TEMPLATE_DEMO=false.
 */
class TemplateController extends Controller
{
    public function __invoke(string $page)
    {
        abort_unless(view()->exists("template.{$page}"), 404);

        return view("template.{$page}");
    }
}
