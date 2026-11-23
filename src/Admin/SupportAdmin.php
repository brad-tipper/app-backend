<?php

namespace BradTipper\AppBackend\Admin;

use BradTipper\AppBackend\Model\ContactSubmission;
use BradTipper\AppBackend\Model\FeedbackSubmission;
use BradTipper\AppBackend\Model\RevenueCatEvent;
use SilverStripe\Admin\ModelAdmin;

/** Things people send in, and the subscription events RevenueCat has reported. */
class SupportAdmin extends ModelAdmin
{
    private static string $url_segment = 'support';

    private static string $menu_title = 'Support';

    private static string $menu_icon_class = 'font-icon-chat';

    private static $menu_priority = -0.3;

    private static array $managed_models = [
        ContactSubmission::class,
        FeedbackSubmission::class,
        RevenueCatEvent::class,
    ];
}
