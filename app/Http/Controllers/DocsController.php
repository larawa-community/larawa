<?php

namespace App\Http\Controllers;

use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocsController extends Controller
{
    public function swagger(): View
    {
        $this->ensureDocsAreAvailable();

        return view('docs.swagger');
    }

    public function openApi(): BinaryFileResponse
    {
        $this->ensureDocsAreAvailable();

        return response()->file(base_path('docs/openapi.yaml'), [
            'Content-Type' => 'application/yaml',
        ]);
    }

    private function ensureDocsAreAvailable(): void
    {
        abort_if(app()->environment('production'), 404);
    }
}
