<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Event;
use App\Models\GithubSource;
use App\Services\GithubImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Releases → GitHub: repositories to pull releases from. */
class GithubController extends Controller
{
    public function __construct(protected GithubImporter $importer) {}

    public function index(Request $request): Response
    {
        $selected = $request->integer('source') ?: null;
        $sources = GithubSource::query()->with('client:id,name')->withCount('releases')->orderBy('repository')->get();
        $releases = null;
        $error = null;
        if ($selected && ($source = $sources->firstWhere('id', $selected))) {
            try {
                $releases = $this->importer->list($source);
                $source->update(['last_checked_at' => now()]);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('Github/Index', [
            'sources' => $sources->map(fn (GithubSource $s) => $s->only('id', 'repository', 'type', 'client_id', 'asset_pattern', 'include_prereleases', 'auto_import', 'auto_publish', 'last_checked_at', 'last_error', 'releases_count') + ['client' => $s->client?->name, 'has_token' => filled($s->token)]),
            'selected' => $selected,
            'releases' => $releases,
            'error' => $error,
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $source = new GithubSource;
        $this->fill($source, $request);
        $this->assertReachable($source);
        $source->save();
        Event::log('github.source_added', "GitHub repository {$source->repository} added ({$source->type}).", ['client' => $source->client]);

        return redirect()->route('github.index', ['source' => $source->id])->with('success', "Connected to {$source->repository}. Its releases are listed below.");
    }

    public function update(Request $request, GithubSource $source): RedirectResponse
    {
        $this->fill($source, $request);
        $this->assertReachable($source);
        $source->save();

        return redirect()->route('github.index', ['source' => $source->id])->with('success', 'Saved.');
    }

    public function destroy(GithubSource $source): RedirectResponse
    {
        $source->delete(); // imported releases stay

        return redirect()->route('github.index')->with('success', 'Repository removed. Releases already imported are kept.');
    }

    public function import(Request $request, GithubSource $source, int $githubRelease): RedirectResponse
    {
        try {
            $release = $this->importer->import($source, $githubRelease, $request->user());
        } catch (Throwable $e) {
            $message = $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage();

            return back()->with('error', $message);
        }

        return redirect()->route('releases.show', $release)->with('success', $release->isPublished()
            ? "Imported and published {$release->label()}. It can be deployed now."
            : "Imported {$release->label()} as a draft. Check it, then Sign & publish.");
    }

    public function sync(Request $request, GithubSource $source): RedirectResponse
    {
        try {
            $result = $this->importer->sync($source, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($result['failed'] ? 'error' : 'success', ($result['imported'] ? 'Imported '.implode(', ', $result['imported']).'. ' : 'Nothing new to import. ').($result['failed'] ? 'Not imported: '.implode(' ', $result['failed']) : ''));
    }

    protected function fill(GithubSource $source, Request $request): void
    {
        $data = $request->validate([
            'repository' => ['required', 'string', 'max:200'],
            'token' => ['nullable', 'string', 'max:500'],
            'remove_token' => ['boolean'],
            'type' => ['required', 'in:core,module,extension'],
            'client_id' => ['nullable', 'required_if:type,extension', 'exists:clients,id'],
            'asset_pattern' => ['nullable', 'string', 'max:100'],
            'include_prereleases' => ['boolean'],
            'auto_import' => ['boolean'],
            'auto_publish' => ['boolean'],
        ], ['client_id.required_if' => 'Choose the client this extension belongs to.']);

        $repo = GithubSource::cleanRepository($data['repository']);
        if (! $repo) {
            throw ValidationException::withMessages(['repository' => 'Enter it as owner/name, e.g. my-company/catalog (or paste the github.com link).']);
        }
        $source->fill([
            'repository' => $repo,
            'type' => $data['type'],
            'client_id' => $data['type'] === 'extension' ? $data['client_id'] : null,
            'asset_pattern' => ($data['asset_pattern'] ?? null) ?: '*.zip',
            'include_prereleases' => $request->boolean('include_prereleases'),
            'auto_import' => $request->boolean('auto_import'),
            'auto_publish' => $request->boolean('auto_publish'),
        ]);
        if (filled($data['token'] ?? null)) {
            $source->token = trim($data['token']);
        } elseif ($request->boolean('remove_token')) {
            $source->token = null;
        }
    }

    protected function assertReachable(GithubSource $source): void
    {
        try {
            $this->importer->list($source);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['repository' => $e->getMessage()]);
        }
    }
}
