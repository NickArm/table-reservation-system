<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Platform\PublishPlatformDocument;
use App\Enums\PlatformDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformDocumentRequest;
use App\Models\PlatformDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * The platform's legal texts, editable without a deploy (SPEC §12.2).
 *
 * That is the whole requirement, and it decides the shape of this screen: the
 * texts are being written by a lawyer who will send a document, probably
 * twice, probably after the first customer has signed up. Waiting on a deploy
 * for each revision would mean either a stale page or a deploy nobody tested.
 */
final class LegalController extends Controller
{
    public function index(): View
    {
        return view('admin.legal.index', [
            'types' => PlatformDocumentType::cases(),
            'current' => PlatformDocument::query()
                ->where('is_current', true)
                ->get()
                ->keyBy(fn (PlatformDocument $document): string => $document->type->value),
            'drafts' => PlatformDocument::query()
                ->whereNull('published_at')
                ->get()
                ->keyBy(fn (PlatformDocument $document): string => $document->type->value),
        ]);
    }

    public function edit(string $type): View
    {
        $document = PlatformDocumentType::tryFrom($type);

        abort_if($document === null, 404);

        /*
         * A draft is offered for editing before the published version: a
         * draft exists precisely because somebody is part way through the
         * next revision, and opening the live text instead would lose that
         * work the first time they pressed Save.
         */
        $draft = PlatformDocument::query()
            ->where('type', $document->value)
            ->whereNull('published_at')
            ->first();

        return view('admin.legal.form', [
            'type' => $document,
            'document' => $draft ?? PlatformDocument::current($document),
            'isDraft' => $draft !== null,
            'history' => PlatformDocument::query()
                ->where('type', $document->value)
                ->whereNotNull('published_at')
                ->newestFirst()
                ->get(),
        ]);
    }

    public function update(
        PlatformDocumentRequest $request,
        string $type,
        PublishPlatformDocument $publish,
    ): RedirectResponse {
        $document = PlatformDocumentType::tryFrom($type);

        abort_if($document === null, 404);

        $version = $request->string('version')->toString();
        $wantsToPublish = $request->boolean('publish');

        $existing = PlatformDocument::query()
            ->where('type', $document->value)
            ->where('version', $version)
            ->first();

        /*
         * A published version is frozen, because `tenants.accepted_terms_version`
         * points at it by name and an account that agreed to 1.0 agreed to the
         * words in 1.0. The refusal is here, on the form, rather than only in
         * the action — being told to pick a new version number is useful;
         * silently saving nothing is not.
         */
        if ($existing instanceof PlatformDocument && ! $existing->isDraft()) {
            return back()
                ->withInput()
                ->with('error', 'Version '.$version.' is already published, and a '
                    .'published version cannot be edited — accounts have agreed to '
                    .'those exact words. Give this one a new version number.');
        }

        $publish->handle(
            type: $document,
            version: $version,
            content: $request->string('content')->toString(),
            actor: $request->user(),
            changeSummary: $request->string('change_summary')->toString() ?: null,
            needsReacceptance: $request->boolean('needs_reacceptance'),
            publish: $wantsToPublish,
        );

        return redirect()->route('admin.legal.index')->with(
            'status',
            $wantsToPublish
                ? $document->title().' '.$version.' is live at /'.$document->path().'.'
                : 'Saved as a draft. Nothing is live until you publish it.',
        );
    }
}
