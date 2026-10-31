<?php

namespace BradTipper\AppBackend\Model;

use SilverStripe\ORM\DataObject;

/**
 * @property string $Name
 * @property string $Email
 * @property string $Subject
 * @property string $Message
 * @property string $PageUrl
 * @property bool $GdprConsentGiven
 * @property string $GdprConsentAt
 * @property string $IpAddress
 * @property string $UserAgent
 */
class ContactSubmission extends DataObject
{
    private static string $table_name = 'AppContactSubmission';

    private static array $db = [
        'Name' => 'Varchar(255)',
        'Email' => 'Varchar(254)',
        'Subject' => 'Varchar(255)',
        'Message' => 'Text',
        'PageUrl' => 'Varchar(2048)',
        'GdprConsentGiven' => 'Boolean',
        'GdprConsentAt' => 'Datetime',
        'IpAddress' => 'Varchar(64)',
        'UserAgent' => 'Varchar(512)',
    ];

    private static array $indexes = ['Email' => true];

    private static array $summary_fields = ['Created', 'Name', 'Email', 'Subject'];

    private static string $default_sort = 'Created DESC';
}
