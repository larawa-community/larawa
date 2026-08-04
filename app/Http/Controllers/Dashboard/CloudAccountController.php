<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\WhatsappSession;
use App\Services\AuditLogger;
use App\Services\MetaWhatsappAccountService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CloudAccountController extends Controller
{
    public function refresh(Request $request, WhatsappSession $session, MetaWhatsappAccountService $account): RedirectResponse
    {
        $this->authorizeSession($request, $session);

        try {
            $account->refreshDetails($session);
        } catch (ConnectionException|RequestException $exception) {
            return back()->with('error', $this->providerError($exception));
        }

        return back()->with('status', 'WhatsApp account status refreshed from Meta.');
    }

    public function setTwoFactor(
        Request $request,
        WhatsappSession $session,
        MetaWhatsappAccountService $account,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeSession($request, $session);
        $data = $request->validate([
            'pin' => ['required', 'digits:6', 'confirmed'],
        ]);

        try {
            $account->setTwoFactorPin($session, $data['pin']);
        } catch (ConnectionException|RequestException $exception) {
            return back()->with('error', $this->providerError($exception));
        }

        $this->updateAccountMetadata($session, ['is_pin_enabled' => true, 'refreshed_at' => now()->toISOString()]);
        $audit->log('cloud_account.two_factor_pin_updated', $workspace, $request->user(), auditable: $session, request: $request);

        return back()->with('status', 'WhatsApp two-step verification PIN updated.');
    }

    public function requestDisplayName(
        Request $request,
        WhatsappSession $session,
        MetaWhatsappAccountService $account,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeSession($request, $session);
        $data = $request->validate([
            'new_display_name' => ['required', 'string', 'min:3', 'max:512'],
        ]);
        $displayName = trim($data['new_display_name']);

        try {
            $account->requestDisplayName($session, $displayName);
        } catch (ConnectionException|RequestException $exception) {
            return back()->withInput($request->except(['pin', 'pin_confirmation', 'display_name_pin']))
                ->with('error', $this->providerError($exception));
        }

        $this->updateAccountMetadata($session, [
            'new_display_name' => $displayName,
            'new_name_status' => 'PENDING_REVIEW',
            'refreshed_at' => now()->toISOString(),
        ]);
        $audit->log('cloud_account.display_name_requested', $workspace, $request->user(), auditable: $session, metadata: ['new_display_name' => $displayName], request: $request);

        return back()->with('status', 'The new display name was submitted to Meta for approval.');
    }

    public function applyDisplayName(
        Request $request,
        WhatsappSession $session,
        MetaWhatsappAccountService $account,
        AuditLogger $audit,
    ): RedirectResponse {
        $workspace = $this->authorizeSession($request, $session);
        $data = $request->validate([
            'display_name_pin' => ['required', 'digits:6'],
        ]);

        try {
            $result = $account->applyApprovedDisplayName($session, $data['display_name_pin']);
        } catch (ConnectionException|RequestException $exception) {
            return back()->with('error', $this->providerError($exception));
        }

        $details = $result['details'];
        $approvedName = $details['new_display_name'] ?? null;
        $details['verified_name'] = $approvedName;
        $details['name_status'] = 'APPROVED';
        $details['new_display_name'] = null;
        $details['new_name_status'] = null;
        $account->storeDetails($session, $details);
        $audit->log('cloud_account.display_name_applied', $workspace, $request->user(), auditable: $session, metadata: ['display_name' => $approvedName], request: $request);

        return back()->with('status', 'The approved display name was applied to the WhatsApp phone number.');
    }

    private function authorizeSession(Request $request, WhatsappSession $session)
    {
        $workspace = $this->workspace($request);
        $this->authorizeWorkspace($request, 'sessions.manage', $session->workspace);
        $this->assertSessionAllowed($workspace, $session);
        abort_unless($session->isCloudApi(), 404);

        return $workspace;
    }

    private function updateAccountMetadata(WhatsappSession $session, array $account): void
    {
        $metadata = $session->metadata ?: [];
        data_set($metadata, 'cloud_api.account', array_merge(data_get($metadata, 'cloud_api.account', []), $account));
        $session->update(['metadata' => $metadata]);
    }

    private function providerError(ConnectionException|RequestException $exception): string
    {
        if ($exception instanceof RequestException) {
            return $exception->response->json('error.message') ?: $exception->response->json('message') ?: 'Meta rejected the WhatsApp account request.';
        }

        return 'Meta is unreachable. Try again shortly.';
    }
}
