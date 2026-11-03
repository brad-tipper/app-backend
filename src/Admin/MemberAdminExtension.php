<?php

namespace BradTipper\AppBackend\Admin;

use BradTipper\AppBackend\Auth\ApiToken;
use BradTipper\AppBackend\Auth\AuthChallenge;
use BradTipper\AppBackend\Model\ContactSubmission;
use BradTipper\AppBackend\Model\FeedbackSubmission;
use BradTipper\AppBackend\Model\RevenueCatEvent;
use BradTipper\AppBackend\Sync\SyncedExtension;
use ReflectionClass;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordViewer;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataObject;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Security\Member;

/**
 * Security → Users shows everything about a user in one place: their account state, sessions, password reset and
 * sign-in links, subscription events, feedback, and every row of app data they own (one tab per data type that
 * has any). Secrets (TOTP secrets, recovery codes) are never shown, and subscription state is read-only because it
 * is written only by the RevenueCat webhook.
 *
 * @property Member|MemberAdminExtension $owner
 */
class MemberAdminExtension extends Extension
{
    public function updateSummaryFields(array &$fields): void
    {
        $fields['UUID'] = 'User ID';
        $fields['SubscriptionTier'] = 'Tier';
        $fields['SubscriptionStatus'] = 'Subscription';
        $fields['ScheduledForDeletionAt'] = 'Deleting on';
        $fields['Created'] = 'Joined';
    }

    public function updateSearchableFields(array &$fields): void
    {
        $fields['UUID'] = ['title' => 'User ID', 'filter' => 'PartialMatchFilter'];
        $fields['SubscriptionTier'] = ['title' => 'Tier', 'filter' => 'ExactMatchFilter'];
        $fields['SubscriptionStatus'] = ['title' => 'Subscription', 'filter' => 'ExactMatchFilter'];
        $fields['TotpEnabled'] = ['title' => 'Two-factor on', 'filter' => 'ExactMatchFilter'];
    }

    public function updateCMSFields(FieldList $fields): void
    {
        $member = $this->owner;
        $fields->removeByName(['TotpSecret', 'TotpPendingSecret', 'RecoveryCodes', 'PreferencesJson']);
        foreach (['UUID', 'SubscriptionTier', 'SubscriptionStatus', 'SubscriptionEventAt', 'TotpEnabled', 'EmailConfirmedAt', 'DeletionRequestedAt', 'ScheduledForDeletionAt'] as $name) {
            if ($field = $fields->dataFieldByName($name)) {
                $fields->replaceField($name, $field->performReadonlyTransformation());
            }
        }
        if (!$member->isInDB()) {
            return;
        }

        $fields->addFieldToTab('Root.Sessions', LiteralField::create(
            'SessionsHelp',
            '<p class="message notice">Each row is a signed-in device. Deleting it signs that device out at its next refresh.</p>'
        ));
        $fields->addFieldToTab('Root.Sessions', $this->grid(
            'Sessions',
            ApiToken::get()->filter('MemberID', $member->ID)->sort('Created', 'DESC'),
            $this->viewerWithDelete()
        ));

        $fields->addFieldToTab('Root.Links', LiteralField::create(
            'LinksHelp',
            '<p class="message notice">Password resets, sign-in links, email confirmations and two-factor codes sent to this user. Used ones are kept for the record; deleting a pending one cancels it.</p>'
        ));
        $fields->addFieldToTab('Root.Links', $this->grid(
            'Links',
            AuthChallenge::get()->filter('MemberID', $member->ID)->sort('Created', 'DESC'),
            $this->viewerWithDelete()
        ));

        if ($member->UUID) {
            $fields->addFieldToTab('Root.SubscriptionEvents', $this->grid(
                'SubscriptionEvents',
                RevenueCatEvent::get()->filter('AppUserUuid', $member->UUID)->sort('EventAt', 'DESC'),
                GridFieldConfig_RecordViewer::create(25)
            ));
            $fields->findOrMakeTab('Root.SubscriptionEvents')->setTitle('Subscription events');
        }

        $fields->addFieldToTab('Root.Feedback', $this->grid(
            'FeedbackSent',
            FeedbackSubmission::get()->filter('MemberID', $member->ID)->sort('Created', 'DESC'),
            GridFieldConfig_RecordViewer::create(25),
            'Feedback'
        ));
        $fields->addFieldToTab('Root.Feedback', $this->grid(
            'ContactSent',
            ContactSubmission::get()->filter('Email', $member->Email)->sort('Created', 'DESC'),
            GridFieldConfig_RecordViewer::create(25),
            'Contact messages'
        ));

        $this->addOwnedData($fields);
    }

    private function addOwnedData(FieldList $fields): void
    {
        $member = $this->owner;
        $counts = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || !$class::has_extension(SyncedExtension::class)) {
                continue;
            }
            $list = $class::get()->filter('OwnerID', $member->ID);
            $total = $list->count();
            if (!$total) {
                continue;
            }
            $plural = singleton($class)->i18n_plural_name();
            $counts[] = "{$plural}: {$total}";
            $config = GridFieldConfig_RecordEditor::create(25);
            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $tab = 'Root.Data.' . $reflection->getShortName();
            $fields->addFieldToTab($tab, $this->grid('Data' . $reflection->getShortName(), $list->sort('LastEdited', 'DESC'), $config, $plural));
            $fields->findOrMakeTab($tab)->setTitle("{$plural} ({$total})");
        }
        if ($counts) {
            $fields->findOrMakeTab('Root.Data')->setTitle('Data');
        }
        $fields->addFieldToTab('Root.Main', ReadonlyField::create('OwnedDataSummary', 'Owned data', $counts ? implode(', ', $counts) : 'Nothing yet'));
    }

    private function viewerWithDelete(): GridFieldConfig
    {
        $config = GridFieldConfig_RecordViewer::create(25);
        $config->addComponent(GridFieldDeleteAction::create(false));
        return $config;
    }

    private function grid(string $name, SS_List $list, GridFieldConfig $config, ?string $title = null): GridField
    {
        return GridField::create($name, $title ?? $name, $list, $config);
    }
}
