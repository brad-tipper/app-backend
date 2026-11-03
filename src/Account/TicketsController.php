<?php

namespace BradTipper\AppBackend\Account;

use BradTipper\AppBackend\Api\ApiController;
use BradTipper\AppBackend\Api\ApiException;
use BradTipper\AppBackend\Api\Input;
use BradTipper\AppBackend\Model\Ticket;
use BradTipper\AppBackend\Model\TicketMessage;
use BradTipper\AppBackend\OpenApi\Spec;
use BradTipper\AppBackend\Support\Notifications;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\Member;

/**
 * A light ticketing system for signed-in users: report a bug, ask for a feature or leave feedback, then follow it
 * and add notes. Users only ever see their own tickets and the notes meant for them. Plain text only.
 *
 *   GET  /v1/tickets                  the user's tickets, newest activity first (?status=open|closed)
 *   POST /v1/tickets                  open a ticket
 *   GET  /v1/tickets/{id}             one ticket with its conversation
 *   POST /v1/tickets/{id}/messages    add a note (reopens a resolved or closed ticket)
 *   POST /v1/tickets/{id}/status      {"status":"closed"|"open"}
 */
class TicketsController extends ApiController
{
    private const MAX_MESSAGES = 200;

    public static function describe(Spec $spec): void
    {
        $string = static fn () => Spec::string();
        $spec->schema('TicketSummary', Spec::object([
            'id' => Spec::string('uuid'),
            'number' => Spec::integer(),
            'type' => Spec::enum(array_keys(Ticket::TYPES)),
            'title' => $string(),
            'status' => Spec::enum(array_keys(Ticket::STATUSES)),
            'createdAt' => Spec::string('date-time'),
            'updatedAt' => Spec::string('date-time'),
            'awaitingSupport' => Spec::boolean(),
        ]));
        $spec->schema('TicketMessage', Spec::object([
            'id' => Spec::string('uuid'),
            'from' => Spec::enum(['you', 'support']),
            'body' => $string(),
            'createdAt' => Spec::string('date-time'),
        ]));
        $spec->schema('Ticket', Spec::object([
            'id' => Spec::string('uuid'),
            'number' => Spec::integer(),
            'type' => Spec::enum(array_keys(Ticket::TYPES)),
            'title' => $string(),
            'status' => Spec::enum(array_keys(Ticket::STATUSES)),
            'createdAt' => Spec::string('date-time'),
            'updatedAt' => Spec::string('date-time'),
            'awaitingSupport' => Spec::boolean(),
            'description' => $string(),
            'appVersion' => Spec::string(null, true),
            'platform' => Spec::string(null, true),
            'messages' => Spec::arrayOf(Spec::ref('TicketMessage')),
        ]));
        $spec->schema('TicketList', Spec::object(['tickets' => Spec::arrayOf(Spec::ref('TicketSummary'))]));
        $spec->schema('CreateTicketRequest', Spec::object([
            'type' => Spec::enum(array_keys(Ticket::TYPES)),
            'title' => $string(),
            'description' => $string(),
            'appVersion' => $string(),
            'platform' => $string(),
        ], ['type', 'title', 'description']));
        $spec->schema('TicketMessageRequest', Spec::object(['body' => $string()], ['body']));
        $spec->schema('TicketStatusRequest', Spec::object(['status' => Spec::enum(['open', 'closed'])], ['status']));

        $tags = ['Support'];
        $id = ['id' => Spec::string('uuid')];
        $spec->operation('get', '/v1/tickets', 'listTickets', [
            'tags' => $tags,
            'auth' => true,
            'summary' => 'List my tickets',
            'query' => ['status' => ['schema' => Spec::enum(['open', 'closed'])]],
            'responses' => [200 => Spec::ref('TicketList')],
        ]);
        $spec->operation('post', '/v1/tickets', 'createTicket', [
            'tags' => $tags,
            'auth' => true,
            'summary' => 'Open a ticket',
            'body' => Spec::ref('CreateTicketRequest'),
            'responses' => [201 => Spec::ref('Ticket')],
        ]);
        $spec->operation('get', '/v1/tickets/{id}', 'getTicket', [
            'tags' => $tags,
            'auth' => true,
            'summary' => 'One of my tickets with its conversation',
            'path' => $id,
            'responses' => [200 => Spec::ref('Ticket')],
        ]);
        $spec->operation('post', '/v1/tickets/{id}/messages', 'addTicketMessage', [
            'tags' => $tags,
            'auth' => true,
            'summary' => 'Add a note to a ticket',
            'path' => $id,
            'body' => Spec::ref('TicketMessageRequest'),
            'responses' => [201 => Spec::ref('Ticket')],
        ]);
        $spec->operation('post', '/v1/tickets/{id}/status', 'setTicketStatus', [
            'tags' => $tags,
            'auth' => true,
            'summary' => 'Close or reopen a ticket',
            'path' => $id,
            'body' => Spec::ref('TicketStatusRequest'),
            'responses' => [200 => Spec::ref('Ticket')],
        ]);
    }

    protected function dispatch(HTTPRequest $request): HTTPResponse|array|null
    {
        $member = $this->requireMember($request);
        $method = strtoupper($request->httpMethod());
        $id = (string) $request->param('Action');
        $sub = (string) $request->param('ID');

        if ($id === '') {
            return match ($method) {
                'GET' => $this->listTickets($request, $member),
                'POST' => $this->createTicket($request, $member),
                default => throw ApiException::methodNotAllowed(),
            };
        }
        $ticket = $this->findTicket($member, $id);
        if ($sub === '') {
            $this->requireMethod($request, 'GET');
            return $ticket->toApiDetail();
        }
        $this->requireMethod($request, 'POST');
        return match ($sub) {
            'messages' => $this->addMessage($request, $member, $ticket),
            'status' => $this->setStatus($request, $member, $ticket),
            default => throw ApiException::notFound(),
        };
    }

    /** @return array<string, mixed> */
    private function listTickets(HTTPRequest $request, Member $member): array
    {
        $list = Ticket::get()->filter('OwnerID', $member->ID)->sort('LastActivityAt', 'DESC')->limit(200);
        $status = $request->getVar('status');
        if ($status === 'open') {
            $list = $list->exclude('Status', ['resolved', 'closed']);
        } elseif ($status === 'closed') {
            $list = $list->filter('Status', ['resolved', 'closed']);
        }
        return ['tickets' => array_map(static fn (Ticket $t) => $t->toApiSummary(), $list->toArray())];
    }

    private function createTicket(HTTPRequest $request, Member $member): HTTPResponse
    {
        $this->limit("ticket-create:{$member->ID}", 10, 3600);
        $in = Input::fromRequest($request);
        $type = $in->enum('type', array_keys(Ticket::TYPES));
        $title = $in->string('title', true, 150, 3);
        $description = $in->string('description', true, 5000, 10);
        $version = $in->string('appVersion', false, 32);
        $platform = $in->string('platform', false, 32);
        $in->assertValid();

        $ticket = Ticket::create([
            'OwnerID' => $member->ID,
            'Type' => $type,
            'Status' => 'open',
            'Priority' => 'normal',
            'Title' => $title,
            'Description' => $description,
            'AppVersion' => (string) $version,
            'Platform' => (string) $platform,
        ]);
        $ticket->silent = true;
        $ticket->write();
        Notifications::ticketReceived($member, $ticket);
        return $this->json($ticket->toApiDetail(), 201);
    }

    private function addMessage(HTTPRequest $request, Member $member, Ticket $ticket): HTTPResponse
    {
        $this->limit("ticket-message:{$member->ID}", 30, 3600);
        $in = Input::fromRequest($request);
        $body = $in->string('body', true, 5000, 1);
        $in->assertValid();
        if ($ticket->Messages()->count() >= self::MAX_MESSAGES) {
            throw ApiException::badRequest('This ticket has too many notes; please open a new one.', 'ticket_full');
        }

        $reopened = in_array($ticket->Status, ['resolved', 'closed'], true);
        TicketMessage::create([
            'TicketID' => $ticket->ID,
            'AuthorID' => $member->ID,
            'Body' => $body,
            'Staff' => false,
            'Internal' => false,
        ])->write();
        $ticket = Ticket::get_by_id($ticket->ID);
        if ($reopened) {
            $ticket->Status = 'open';
            $ticket->silent = true;
            $ticket->write();
        }
        Notifications::ticketUserNote($member, $ticket, $body);
        return $this->json($ticket->toApiDetail(), 201);
    }

    /** @return array<string, mixed> */
    private function setStatus(HTTPRequest $request, Member $member, Ticket $ticket): array
    {
        $in = Input::fromRequest($request);
        $status = $in->enum('status', ['open', 'closed']);
        $in->assertValid();
        if ($ticket->Status !== $status) {
            $ticket->Status = $status;
            $ticket->silent = true;
            $ticket->touchActivity();
            $ticket->write();
        }
        return $ticket->toApiDetail();
    }

    private function findTicket(Member $member, string $uuid): Ticket
    {
        $ticket = preg_match('/^[0-9a-f-]{36}$/i', $uuid)
            ? Ticket::get()->filter(['UUID' => strtolower($uuid), 'OwnerID' => $member->ID])->first()
            : null;
        if (!$ticket) {
            throw ApiException::notFound('Ticket not found.');
        }
        return $ticket;
    }
}
