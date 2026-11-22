<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\Installation;
use App\Services\ApiException;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The website's side of a backup: pick it up, upload it in chunks, report the result (docs/API.md). */
class BackupApiController extends Controller
{
    public function __construct(protected BackupService $backups) {}

    public function accept(Request $request, int $backup): JsonResponse
    {
        return response()->json($this->backups->accept($this->backup($request, $backup)));
    }

    /** Raw bytes in the body; X-Offset = where they go. */
    public function chunk(Request $request, int $backup): JsonResponse
    {
        $offset = (string) $request->header('X-Offset');
        if (! ctype_digit($offset)) {
            throw new ApiException('bad_chunk', 'Missing X-Offset header.', 422);
        }

        return response()->json(['received' => $this->backups->appendChunk($this->backup($request, $backup), (int) $offset, $request->getContent())]);
    }

    public function complete(Request $request, int $backup): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', 'in:completed,failed'],
            'size' => ['required_if:result,completed', 'integer', 'min:1'],
            'sha256' => ['required_if:result,completed', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'contents' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:500'],
        ]);
        $b = $this->backup($request, $backup);
        if (! $b->isActive()) {
            return response()->json(['ok' => true, 'status' => $b->status]);
        }

        $b = $data['result'] === 'completed'
            ? $this->backups->complete($b, (int) $data['size'], $data['sha256'], (array) ($data['contents'] ?? []))
            : $this->backups->fail($b, $data['error'] ?? 'The website could not create the backup.');

        return response()->json(['ok' => $b->status === 'completed', 'status' => $b->status, 'error' => $b->status === 'completed' ? null : $b->error]);
    }

    /** 404 (not 403) for other installations' backups: never confirm they exist. */
    protected function backup(Request $request, int $id): Backup
    {
        /** @var Installation $installation */
        $installation = $request->attributes->get('installation');
        $backup = Backup::query()->with('installation')->where('installation_id', $installation->id)->find($id);
        if (! $backup) {
            throw new ApiException('not_found', 'Not found.', 404);
        }

        return $backup;
    }
}
