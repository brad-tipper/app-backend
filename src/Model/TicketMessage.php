<?php

namespace BradTipper\AppBackend\Model;

use BradTipper\AppBackend\Api\Uuid;
use BradTipper\AppBackend\Auth\ApiMemberExtension;
use BradTipper\AppBackend\Support\Notifications;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * One note in a ticket's conversation. Notes added in the admin are from the team (Staff) and are emailed to the
 * reporter unless they are Internal; notes from the user come in through the API.
 *
 * @property string $UUID
 * @property string $Body
 * @property bool $Staff
 * @property bool $Internal
 * @property int $TicketID
 * @property int $AuthorID
 * @method Ticket Ticket()
 * @method Member Author()
 */
class TicketMessage extends DataObject
{
    private static string $singular_name = 'Message';

    private static string $plural_name = 'Messages';

    private static string $table_name = 'AppTicketMessage';

    private static array $db = [
        'UUID' => 'Varchar(36)',
        'Body' => 'Text',
        'Staff' => 'Boolean',
        'Internal' => 'Boolean',
    ];

    private static array $defaults = ['Staff' => true];

    private static array $has_one = [
        'Ticket' => Ticket::class,
        'Author' => Member::class,
    ];

    private static array $indexes = ['UUID' => ['type' => 'unique', 'columns' => ['UUID']]];

    private static array $summary_fields = [
        'Created' => 'When',
        'FromLabel' => 'From',
        'BodyPreview' => 'Message',
        'InternalLabel' => 'Internal',
    ];

    private static string $default_sort = 'Created ASC';

    private bool $wasNew = false;

    public function getFromLabel(): string
    {
        return $this->Staff ? 'Support' : 'User';
    }

    public function getInternalLabel(): string
    {
        return $this->Internal ? 'Internal' : '';
    }

    public function getBodyPreview(): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', (string) $this->Body), 0, 120, '…');
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->UUID) {
            $this->UUID = Uuid::v4();
        }
        $this->wasNew = !$this->isInDB();
        if ($this->wasNew && $this->Staff && !$this->AuthorID && ($admin = Security::getCurrentUser())) {
            $this->AuthorID = $admin->ID;
        }
    }

    public function onAfterWrite(): void
    {
        parent::onAfterWrite();
        if (!$this->wasNew) {
            return;
        }
        $this->wasNew = false;
        $ticket = $this->Ticket();
        if (!$ticket->exists() || $this->Internal) {
            return;
        }
        $ticket->touchActivity();
        if ($this->Staff) {
            // A staff reply to a fresh ticket means it is being looked at.
            if ($ticket->Status === 'open') {
                $ticket->Status = 'in_progress';
                $ticket->silent = true;
            }
            $ticket->write();
            $owner = $ticket->Owner();
            if ($owner->exists()) {
                Notifications::ticketReply($owner, $ticket, $this);
            }
            return;
        }
        $ticket->write();
    }

    public function getCMSFields(): FieldList
    {
        $fields = FieldList::create();
        if ($this->isInDB()) {
            $author = $this->Author();
            $fields->push(ReadonlyField::create('From', 'From', $this->Staff ? 'Support' . ($author->exists() ? " ({$author->Email})" : '') : ($author->exists() ? $author->Email : 'User')));
            $fields->push(ReadonlyField::create('Sent', 'Sent', (string) $this->Created));
        }
        $fields->push(TextareaField::create('Body', 'Message')->setRows(10));
        $fields->push(CheckboxField::create('Internal', 'Internal note (not shown to the user, no email)'));
        return $fields;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->UUID,
            'from' => $this->Staff ? 'support' : 'you',
            'body' => $this->Body,
            'createdAt' => ApiMemberExtension::iso($this->Created),
        ];
    }
}
