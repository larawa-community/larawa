<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageMediaController extends Controller
{
    public function api(Request $request, Message $message, AuditLogger $audit): StreamedResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($message->workspace_id === $workspace->id, 404);

        $response = $this->download($message);
        $audit->log('api.message.media_downloaded', $workspace, apiKey: $request->attributes->get('apiKey'), auditable: $message, request: $request);

        return $response;
    }

    public function dashboard(Request $request, Message $message, AuditLogger $audit): StreamedResponse
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'messages.view', $message->workspace);
        abort_unless($this->isSiteAdmin($request) || $message->workspace_id === $workspace->id, 404);
        $workspace = $message->workspace;

        $response = $this->download($message);
        $audit->log('message.media_downloaded', $workspace, $request->user(), auditable: $message, request: $request);

        return $response;
    }

    private function download(Message $message): StreamedResponse
    {
        abort_unless($message->media_path, 404, 'Message has no stored media.');

        $disk = data_get($message->payload, 'media.disk') ?: config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($message->media_path), 404, 'Stored media was not found.');

        return Storage::disk($disk)->download(
            $message->media_path,
            $this->downloadName($message),
            array_filter(['Content-Type' => $message->mime_type])
        );
    }

    private function downloadName(Message $message): string
    {
        $filename = data_get($message->payload, 'filename') ?: basename($message->media_path);

        return preg_replace('/[\r\n\/\\\\]+/', '-', (string) $filename) ?: 'message-media.bin';
    }
}
