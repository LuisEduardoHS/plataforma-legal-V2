<?php

namespace App\Http\Controllers\Messaging;

use App\Exceptions\AttachmentException;
use App\Http\Requests\Messaging\MessagingRequest;
use App\Services\Attachments\AttachmentService;
use App\Services\Messaging\MessageUploadService;
use App\Services\Messaging\MessagingMediaService;
use App\Services\Messaging\MessagingQuery;
use Illuminate\Validation\ValidationException;

class AttachmentController extends MessagingController
{
    public function store(MessagingRequest $request, int $conversation, MessagingQuery $query, MessageUploadService $uploads)
    {
        $query->state($request->user(), $conversation); // Authorize before creating session state.
        $composer = $this->composer($request, $conversation);
        if (count($composer['attachments']) + count($request->file('uploads')) > config('messaging.max_attachments')) {
            throw ValidationException::withMessages(['uploads' => 'Conserva como máximo cuatro archivos preparados.']);
        }
        $files = $uploads->upload($request->user(), $conversation, $request->file('uploads'));
        $key = $this->draftKey($request, $conversation);
        $draft = $request->session()->get($key);
        $draft['attachment_ids'] = [...array_column($composer['attachments'], 'id'), ...array_column($files, 'id')];
        $request->session()->put($key, $draft);

        $files = array_map(fn ($file) => $file + ['remove_url' => route('messages.upload.remove', ['conversation' => $conversation, 'attachment' => $file['id']])], $files);

        return $this->completed($request, route('messages.show', $conversation), 'Archivos listos para enviar.', ['attachments' => $files], 201);
    }

    public function remove(MessagingRequest $request, int $conversation, int $attachment, MessagingQuery $query, AttachmentService $attachments)
    {
        $query->state($request->user(), $conversation);
        $key = $this->draftKey($request, $conversation);
        $draft = $request->session()->get($key);
        abort_unless($draft && in_array($attachment, $draft['attachment_ids'], true), 404);
        try {
            $attachments->requestDeletion($request->user(), $attachment);
        } catch (AttachmentException $error) {
            // A reference created elsewhere may retain this file. Removing it
            // from this composer must not destroy that reference or the object.
            report($error);
        }
        $draft['attachment_ids'] = array_values(array_diff($draft['attachment_ids'], [$attachment]));
        $request->session()->put($key, $draft);

        return $this->completed($request, route('messages.show', $conversation), 'Archivo retirado del borrador.', ['removed' => $attachment]);
    }

    public function download(MessagingRequest $request, int $conversation, int $message, int $attachment, MessagingMediaService $media)
    {
        return $media->download($request->user(), $conversation, $message, $attachment);
    }
}
