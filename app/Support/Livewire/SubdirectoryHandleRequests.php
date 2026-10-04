<?php

namespace App\Support\Livewire;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

/**
 * Livewire builds its update endpoint as a root-relative path ("/livewire/update")
 * and strips the request base path. When the app is served from a sub-directory
 * (http://host/hms) that endpoint would miss the app, so prefix the base path.
 */
class SubdirectoryHandleRequests extends HandleRequests
{
    public function getUpdateUri()
    {
        $base = app()->runningInConsole() ? '' : rtrim(request()->getBaseUrl(), '/');

        return $base.parent::getUpdateUri();
    }
}
