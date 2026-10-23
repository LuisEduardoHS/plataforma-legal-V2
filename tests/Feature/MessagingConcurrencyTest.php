<?php

namespace Tests\Feature;

use App\Exceptions\MessagingException;
use App\Models\Message;
use App\Models\User;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MessagingConcurrencyTest extends TestCase
{
    public function test_mysql_serializes_creation_retries_edits_reads_and_blocking(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('plataforma_legal_v2_test', DB::connection()->getDatabaseName());
        $this->assertSame('plataforma_legal_v2_test', DB::selectOne('SELECT DATABASE() db')->db);
        $default = DB::getDefaultConnection();
        $db = DB::connection();
        $users = [];
        $conversation = null;
        $other = null;
        try {
            $a = User::factory()->create()->refresh();
            $users[] = $a->id;
            $b = User::factory()->create()->refresh();
            $users[] = $b->id;
            config(['database.connections.messaging_race' => config('database.connections.'.$default)]);
            $other = DB::connection('messaging_race');
            $this->assertSame('plataforma_legal_v2_test', $other->selectOne('SELECT DATABASE() db')->db);
            $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
            $reportedCode = null;
            app(ExceptionHandler::class)->reportable(function (QueryException $error) use (&$reportedCode) {
                $reportedCode = (int) ($error->errorInfo[1] ?? 0);
            });
            $conversations = app(ConversationService::class);
            $messages = app(MessageService::class);
            $runOther = function (callable $operation) use ($default) {
                DB::setDefaultConnection('messaging_race');
                try {
                    return $operation();
                } finally {
                    DB::setDefaultConnection($default);
                }
            };
            $timesOut = function (callable $operation) use ($runOther, &$reportedCode, $other) {
                try {
                    $runOther($operation);
                    $this->fail('Se ignoró un bloqueo de fila.');
                } catch (MessagingException) {
                    $this->assertSame(1205, $reportedCode);
                    $this->assertSame(0, $other->transactionLevel());
                }
            };
            $db->beginTransaction();
            $conversation = $conversations->start($a, $b->id);
            $timesOut(fn () => $conversations->start($b, $a->id));
            $db->commit();
            $same = $runOther(fn () => $conversations->start($b, $a->id));
            $this->assertSame($conversation->id, $same->id);
            $this->assertSame(2, $conversation->participants()->count());
            try {
                $other->table('conversations')->insert(['user_low_id' => $a->id, 'user_high_id' => $b->id, 'activity_at' => now()]);
                $this->fail('MySQL permitió una conversación duplicada.');
            } catch (QueryException $error) {
                $this->assertSame(1062, (int) $error->errorInfo[1]);
            }

            $token = (string) Str::uuid();
            $data = ['content' => 'Envío único', 'client_token' => $token];
            $other->beginTransaction();
            $this->assertSame(0, $other->table('messages')->where('conversation_id', $conversation->id)->count());
            $message = $messages->send($a, $conversation->id, $data);
            $retry = $runOther(fn () => $messages->send($a, $conversation->id, $data));
            $this->assertSame($message->id, $retry->id);
            $other->commit();

            $db->beginTransaction();
            $messages->edit($a, $message->id, 'Primera edición', 1);
            $timesOut(fn () => $messages->edit($a, $message->id, 'Edición competidora', 1));
            $db->commit();
            try {
                $runOther(fn () => $messages->edit($a, $message->id, 'Obsoleta', 1));
                $this->fail('Se aceptó una versión obsoleta.');
            } catch (ValidationException) {
                $this->assertSame('Primera edición', $message->fresh()->content);
            }

            $second = $messages->send($a, $conversation->id, ['content' => 'Segundo']);
            $db->beginTransaction();
            $conversations->markRead($b, $conversation->id, $second->id);
            $timesOut(fn () => $conversations->markRead($b, $conversation->id, $message->id));
            $db->commit();
            $runOther(fn () => $conversations->markRead($b, $conversation->id, $message->id));
            $this->assertSame($second->id, $conversation->participants()->where('user_id', $b->id)->first()->last_read_message_id);

            $db->beginTransaction();
            $conversations->block($b, $a->id);
            $timesOut(fn () => $messages->send($a, $conversation->id, ['content' => 'Carrera con bloqueo']));
            $db->commit();
            try {
                $runOther(fn () => $messages->send($a, $conversation->id, ['content' => 'Bloqueado']));
                $this->fail('Se envió un mensaje después del bloqueo.');
            } catch (AuthorizationException) {
                $this->assertSame(2, Message::where('conversation_id', $conversation->id)->count());
            }

            $other->beginTransaction();
            $this->assertSame('Primera edición', $other->table('messages')->where('id', $message->id)->value('content'));
            $messages->deleteForEveryone($a, $message->id, 2);
            $history = $runOther(fn () => app(MessagingQuery::class)->history($b, $conversation->id)->items());
            $deleted = collect($history)->firstWhere('id', $message->id);
            $this->assertSame('deleted', $deleted['status']);
            $this->assertNull($deleted['content']);
            $other->commit();

            $other->beginTransaction();
            $this->assertSame(0, $other->table('message_user_hides')->where('message_id', $second->id)->count());
            $messages->hideForMe($b, $second->id);
            $history = $runOther(fn () => app(MessagingQuery::class)->history($b, $conversation->id)->items());
            $this->assertNotContains($second->id, array_column($history, 'id'));
            $other->commit();
        } finally {
            DB::setDefaultConnection($default);
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($other) {
                while ($other->transactionLevel() > 0) {
                    $other->rollBack();
                }
            }
            DB::disconnect('messaging_race');
            if ($conversation !== null) {
                DB::table('conversation_participants')->where('conversation_id', $conversation->id)->update(['last_read_message_id' => null, 'hidden_through_message_id' => null]);
                DB::table('message_user_hides')->where('conversation_id', $conversation->id)->delete();
                DB::table('messages')->where('conversation_id', $conversation->id)->delete();
                DB::table('conversation_participants')->where('conversation_id', $conversation->id)->delete();
                DB::table('conversations')->where('id', $conversation->id)->delete();
            }
            DB::table('user_blocks')->whereIn('blocker_id', $users)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        }
    }
}
