<?php

namespace App\Http\Controllers\Verification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Verification\VerificationFormRequest;
use App\Services\Verification\VerificationWorkflow;

class VerificationController extends Controller
{
    public function __construct(private VerificationWorkflow $workflow) {}

    public function index(VerificationFormRequest $request)
    {
        return $this->page('index', $this->workflow->own($request->user()));
    }

    public function store(VerificationFormRequest $request)
    {
        $id = $this->workflow->create($request->user());

        return $this->completed($request, $id, 'Se creó el borrador. Añade la evidencia y preséntalo cuando esté completo.', status: 201);
    }

    public function show(VerificationFormRequest $request, int $dossier)
    {
        return $this->page('show', $this->workflow->detail($request->user(), $dossier));
    }

    public function queue(VerificationFormRequest $request)
    {
        return $this->page('queue', $this->workflow->queue($request->user(), $request->validated('status', 'pending')));
    }

    public function review(VerificationFormRequest $request, int $dossier)
    {
        return $this->page('show', $this->workflow->detail($request->user(), $dossier, review: true));
    }

    public function upload(VerificationFormRequest $request, int $dossier)
    {
        $this->workflow->upload($request->user(), $dossier, $request->validated('version'), $request->file('file'), $request->validated('type'));

        return $this->completed($request, $dossier, 'Documento privado asociado al borrador.', status: 201);
    }

    public function remove(VerificationFormRequest $request, int $dossier, int $evidence)
    {
        $this->workflow->change($request->user(), $dossier, $request->validated('version'), 'remove', compact('evidence'));

        return $this->completed($request, $dossier, 'Evidencia retirada del borrador. No se ha eliminado físicamente el archivo.');
    }

    public function submit(VerificationFormRequest $request, int $dossier)
    {
        $this->workflow->change($request->user(), $dossier, $request->validated('version'), 'submit');

        return $this->completed($request, $dossier, 'Solicitud presentada. Sus documentos quedan retenidos para conservar el expediente.');
    }

    public function decide(VerificationFormRequest $request, int $dossier)
    {
        $action = $request->route('decision'); // Fixed route default, never client input.
        $this->workflow->change($request->user(), $dossier, $request->validated('version'), $action, ['reason' => $request->validated('reason')]);

        return $this->completed($request, $dossier, 'Decisión registrada y comunicable al solicitante.', review: true);
    }

    public function history(VerificationFormRequest $request, int $dossier)
    {
        return $this->page('history', $this->workflow->history($request->user(), $dossier));
    }

    public function download(VerificationFormRequest $request, int $dossier, int $evidence)
    {
        return $this->workflow->download($request->user(), $dossier, $evidence);
    }

    private function page(string $view, array $data)
    {
        // Read pages are HTML, not a JSON serialization of sensitive dossiers.
        return response()->view('pages.verification.'.$view, $data)->header('Cache-Control', 'private, no-store');
    }

    private function completed(VerificationFormRequest $request, int $id, string $message, bool $review = false, int $status = 200)
    {
        $url = route($review ? 'verification.review.show' : 'verification.show', $id);

        return ($request->expectsJson()
            ? response()->json(['id' => $id, 'message' => $message, 'redirect' => $url], $status)
            : redirect()->to($url)->with('status', $message))->header('Cache-Control', 'private, no-store');
    }
}
