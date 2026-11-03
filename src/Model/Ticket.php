<?php

namespace BradTipper\AppBackend\Model;

use BradTipper\AppBackend\Auth\ApiMemberExtension;
use BradTipper\AppBackend\Support\Notifications;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * A bug report, feature request or piece of feedback from a signed-in user, with a conversation attached. The
 * reporter (Owner, via SyncedExtension) can read their own tickets and add notes through the API; the team works
 * them in the Tickets admin. Plain text only, no attachments.
 *
 * @property string $Type
 * @property string $Title
 * @property string $Description
 * @property string $Status
 * @property string $Priority
 * @property string $AppVersion
 * @property string $Platform
 * @property string $LastActivityAt
 * @method \SilverStripe\ORM\HasManyList<TicketMessage> Messages()
 */
class Ticket extends DataObject
{
    public const TYPES = ['bug' => 'Bug report', 'feature' => 'Feature request', 'feedback' => 'Feedback'];

    public const STATUSES = [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'planned' => 'Planned',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

    /** Set by API calls the member made themselves, which must not email them about their own action. */
    public bool $silent = false;

    private bool $statusChanged = false;

    private bool $wasNew = false;

    private static string $singular_name = 'Ticket';

    private static string $plural_name = 'Tickets';

    private static string $table_name = 'AppTicket';

    private static array $db = [
        'Type' => "Enum('bug,feature,feedback','feedback')",
        'Title' => 'Varchar(150)',
        'Description' => 'Text',
        'Status' => "Enum('open,in_progress,planned,resolved,closed','open')",
        'Priority' => "Enum('low,normal,high','normal')",
        'AppVersion' => 'Varchar(32)',
        'Platform' => 'Varchar(32)',
        'LastActivityAt' => 'Datetime',
    ];

    private static array $has_many = ['Messages' => TicketMessage::class . '.Ticket'];

    private static array $cascade_deletes = ['Messages'];

    private static array $indexes = ['Status' => true, 'LastActivityAt' => true];

    private static array $summary_fields = [
        'ID' => '#',
        'Type' => 'Type',
        'Title' => 'Title',
        'Status' => 'Status',
        'Priority' => 'Priority',
        'Owner.Email' => 'User',
        'AwaitingLabel' => 'Needs reply',
        'LastActivityAt' => 'Last activity',
    ];

    private static array $searchable_fields = [
        'Title',
        'Description',
        'Type' => ['filter' => 'ExactMatchFilter'],
        'Status' => ['filter' => 'ExactMatchFilter'],
        'Priority' => ['filter' => 'ExactMatchFilter'],
        'Owner.Email' => ['title' => 'User email', 'filter' => 'PartialMatchFilter'],
    ];

    private static string $default_sort = 'LastActivityAt DESC';

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->Type] ?? $this->Type;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->Status] ?? $this->Status;
    }

    /** True when the last thing said was by the user and the ticket is still being worked. */
    public function isAwaitingStaff(): bool
    {
        if (in_array($this->Status, ['resolved', 'closed'], true)) {
            return false;
        }
        $last = $this->Messages()->filter('Internal', false)->sort('Created', 'DESC')->first();
        return !$last || !$last->Staff;
    }

    public function getAwaitingLabel(): string
    {
        return $this->isAwaitingStaff() ? 'Yes' : '';
    }

    public function touchActivity(): void
    {
        $this->LastActivityAt = DBDatetime::now()->getValue();
    }

    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->wasNew = !$this->isInDB();
        $this->statusChanged = !$this->wasNew && $this->isChanged('Status', DataObject::CHANGE_VALUE);
        if (!$this->LastActivityAt) {
            $this->touchActivity();
        }
    }

    public function onAfterWrite(): void
    {
        parent::onAfterWrite();
        $owner = $this->Owner();
        if ($this->statusChanged && !$this->silent && $owner->exists()) {
            Notifications::ticketStatus($owner, $this);
        }
        $this->statusChanged = false;
    }

    public function getCMSFields(): FieldList
    {
        $owner = $this->Owner();
        $fields = FieldList::create();
        $fields->push(\SilverStripe\Forms\TabSet::create('Root'));
        $main = [
            ReadonlyField::create('TicketTypeLabel', 'Type', $this->getTypeLabel()),
            ReadonlyField::create('TicketTitle', 'Title', $this->Title),
            TextareaField::create('TicketDescription', 'Description', $this->Description)->setReadonly(true)->setRows(8),
            ReadonlyField::create('Reporter', 'Reported by', $owner->exists() ? "{$owner->Email} (user {$owner->ID})" : 'Deleted user'),
            ReadonlyField::create('TicketApp', 'App', trim($this->Platform . ' ' . $this->AppVersion)),
            ReadonlyField::create('TicketCreated', 'Reported', (string) $this->Created),
            DropdownField::create('Status', 'Status', self::STATUSES),
            DropdownField::create('Priority', 'Priority', self::PRIORITIES),
        ];
        foreach ($main as $field) {
            $fields->addFieldToTab('Root.Main', $field);
        }
        if ($this->isInDB()) {
            $config = GridFieldConfig_RecordEditor::create(50);
            $fields->addFieldToTab('Root.Conversation', GridField::create(
                'Messages',
                'Messages (a reply you add here is emailed to the user unless it is marked internal)',
                $this->Messages()->sort('Created', 'ASC'),
                $config
            ));
        }
        return $fields;
    }

    /** @return array<string, mixed> */
    public function toApiSummary(): array
    {
        return [
            'id' => $this->UUID,
            'number' => (int) $this->ID,
            'type' => $this->Type,
            'title' => $this->Title,
            'status' => $this->Status,
            'createdAt' => ApiMemberExtension::iso($this->Created),
            'updatedAt' => ApiMemberExtension::iso($this->LastActivityAt),
            'awaitingSupport' => $this->isAwaitingStaff(),
        ];
    }

    /** @return array<string, mixed> */
    public function toApiDetail(): array
    {
        return $this->toApiSummary() + [
            'description' => $this->Description,
            'appVersion' => $this->AppVersion ?: null,
            'platform' => $this->Platform ?: null,
            'messages' => array_map(
                static fn (TicketMessage $m) => $m->toApi(),
                $this->Messages()->filter('Internal', false)->sort('Created', 'ASC')->toArray()
            ),
        ];
    }
}
