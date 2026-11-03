<?php

namespace BradTipper\AppBackend\Admin;

use BradTipper\AppBackend\Model\Ticket;
use SilverStripe\Admin\ModelAdmin;

/**
 * The team's inbox: every bug report, feature request and piece of feedback, newest activity first. Filter by
 * status, type, priority or the user's email, open a ticket to read the conversation and reply (replies are
 * emailed to the user unless marked internal).
 */
class TicketAdmin extends ModelAdmin
{
    private static string $url_segment = 'tickets';

    private static string $menu_title = 'Tickets';

    private static string $menu_icon_class = 'font-icon-comment';

    private static $menu_priority = -0.2;

    private static array $managed_models = [Ticket::class];

    public function getList()
    {
        return parent::getList()->sort('LastActivityAt', 'DESC');
    }
}
