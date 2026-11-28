<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

use KoperasiKita\Api\Ajax;
use KoperasiKita\Api\ApiException;
use KoperasiKita\Api\Response;

/**
 * Newsletter subscribe form: the [kk_subscribe] shortcode.
 *
 * The form posts back to the same page and is handled on `init`, before
 * anything renders. A successful submit redirects (Post/Redirect/Get),
 * so refreshing the page or pressing submit twice never subscribes the
 * same address twice.
 *
 * Every submit is checked, in order:
 *
 * Nonce - a failed check shows a generic error, it never kills the
 *    page (this shortcode lives in the footer of every page).
 * Honeypot - a hidden "website" field that must stay empty. Bots fill
 *    it; when it is filled the request is silently treated as a success
 *    (same redirect, same message) and nobody is subscribed.
 * Per-IP rate limit - 5 attempts per hour. The count lives in a
 *    transient keyed by a salted hash of the visitor's address; the
 *    transient key never holds the raw address. The address itself
 *    is read from the Cloudflare header when present, then
 *    X-Forwarded-For, then REMOTE_ADDR. Those headers are only
 *    used to pick a rate-limit bucket - a spoofed header only
 *    changes which bucket the attempt counts against, and the
 *    nonce and honeypot still apply. The same address is stored
 *    as the subscriber's subscribed_ip on the local row: the
 *    signup address is audit data, kept with the record.
 * Email - sanitised with sanitize_email() and validated with
 *    is_email().
 *
 * MailPoet is optional. When it is active the address is also
 * synced to every MailPoet list through MailPoet's own public
 * API (v1), with confirmation and welcome mail left on so double
 * opt-in keeps working. The sync is best-effort: the submit
 * succeeds as soon as the local record is stored, and the
 * subscribers table's Source column shows where the address
 * actually landed ('local' when MailPoet is absent, not set up,
 * or its call failed). A MailPoet failure must never report the
 * signup itself as failed - the address is already subscribed
 * to the site's own list.
 */
final class Subscribe
{
    public const SHORTCODE = 'kk_subscribe';

    public const NONCE_ACTION = 'kk_subscribe';

    /** Theme option for the MailPoet sync: 'yes' or 'no'. */
    public const OPTION_MAILPOET_SYNC = 'kk_mailpoet_sync';

    /** MailPoet readiness values returned by mailPoetStatus(). */
    public const MAILPOET_ACTIVE = 'active';

    public const MAILPOET_INACTIVE = 'inactive';

    public const MAILPOET_NO_LISTS = 'no_lists';

    public const MAILPOET_ERROR = 'error';

    /** Maximum submits from one address within the rate window. */
    public const RATE_LIMIT_MAX = 5;

    /** Addresses synced per MailPoet sync click, each direction. */
    public const SYNC_BATCH = 100;

    /** Rate-limit window in seconds (one hour, sliding). */
    public const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

    /** @var array{type: string, message: string}|null Notice for this request. */
    private static ?array $notice = null;

    public static function register(): void
    {
        add_action('init', [self::class, 'handleSubmission'], 20);
        add_shortcode(self::SHORTCODE, [self::class, 'renderShortcode']);
        Ajax::add('kk_mailpoet_sync', [self::class, 'ajaxSync'], false, 'manage_options');
    }

    /**
     * Handle a form submit. Runs on `init`, before any output, so the
     * success path can redirect.
     */
    public static function handleSubmission(): void
    {
        if (is_admin() || !isset($_POST['kk_subscribe_submit'])) {
            return;
        }

        $rawNonce = $_POST['kk_subscribe_nonce'] ?? '';
        $nonce = is_string($rawNonce) ? sanitize_key(wp_unslash($rawNonce)) : '';

        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            self::$notice = [
                'type' => 'error',
                'message' => __('Security check failed. Please try again.', 'koperasikita'),
            ];

            return;
        }

        $rawHoneypot = $_POST['kk_subscribe_website'] ?? '';
        // An array here is not a human typing: treat it as filled.
        $honeypot = is_string($rawHoneypot) ? sanitize_text_field(wp_unslash($rawHoneypot)) : '1';

        if ($honeypot !== '') {
            // A bot filled the hidden field: count it against the rate
            // limit, then pretend it worked. Nobody is subscribed.
            self::recordAttempt();
            self::redirectWithSuccess(Subscribers::SOURCE_LOCAL);
        }

        if (self::isRateLimited()) {
            self::$notice = [
                'type' => 'error',
                'message' => __('Too many subscription attempts. Please try again later.', 'koperasikita'),
            ];

            return;
        }

        $rawEmail = $_POST['kk_subscribe_email'] ?? '';
        $email = is_string($rawEmail) ? sanitize_email(wp_unslash($rawEmail)) : '';

        if ($email === '' || !is_email($email)) {
            self::$notice = [
                'type' => 'error',
                'message' => __('Please enter a valid email address.', 'koperasikita'),
            ];

            return;
        }

        // Names are optional: an empty name is a signup without a
        // name, never an error. Capped at the column width, so a
        // long name is cut, never rejected.
        $rawFirstName = $_POST['kk_subscribe_first_name'] ?? '';
        $firstName = is_string($rawFirstName) ? trim(sanitize_text_field(wp_unslash($rawFirstName))) : '';

        if (strlen($firstName) > 100) {
            $firstName = substr($firstName, 0, 100);
        }

        $rawLastName = $_POST['kk_subscribe_last_name'] ?? '';
        $lastName = is_string($rawLastName) ? trim(sanitize_text_field(wp_unslash($rawLastName))) : '';

        if (strlen($lastName) > 100) {
            $lastName = substr($lastName, 0, 100);
        }

        self::recordAttempt();

        // The local record comes first and is the success
        // criterion: the site keeps its own list whether MailPoet
        // is there or not. A failed local write is the only case
        // where the submit itself fails. The visitor's address is
        // stored as the signup address (subscribed_ip).
        if (!Subscribers::record($email, Subscribers::SOURCE_LOCAL, Subscribers::STATUS_SUBSCRIBED, $firstName, $lastName, self::clientIp())) {
            self::$notice = [
                'type' => 'error',
                'message' => __('Could not save your subscription. Please try again later.', 'koperasikita'),
            ];

            return;
        }

        // MailPoet sync is best-effort and gated by the admin's
        // yes/no setting on the Subscribers page (default yes, so
        // the behaviour that shipped first stays on unless the
        // admin turns it off). Only a confirmed sync flips the
        // row's source to 'mailpoet'; anything else (off, absent,
        // not set up, no lists, call failed) leaves the honest
        // 'local' source and still succeeds.
        $source = Subscribers::SOURCE_LOCAL;

        if (
            self::mailPoetSyncEnabled()
            && self::syncEmailWithMailPoet($email, $firstName, $lastName, self::clientIp()) === 'ok'
            && Subscribers::setSource($email, Subscribers::SOURCE_MAILPOET)
        ) {
            $source = Subscribers::SOURCE_MAILPOET;
        }

        self::redirectWithSuccess($source);
    }

    /**
     * The [kk_subscribe] shortcode. Kept for existing content; the
     * form itself is renderForm(), shared with the widget and the
     * newsletter section. Shortcode attributes are intentionally
     * ignored: the form is the same wherever it is placed.
     *
     * @param array<string, string> $atts
     */
    public static function renderShortcode(array $atts = []): string
    {
        return self::renderForm();
    }

    /**
     * Render the subscribe form: the notice, the email field, the
     * optional first and last name fields, the honeypot and the
     * nonce. One markup for the shortcode, the newsletter widget
     * and the newsletter section, so the form behaves identically
     * wherever it is placed.
     */
    public static function renderForm(): string
    {
        $notice = self::$notice;

        $subscribedVia = self::justSubscribed();

        if ($notice === null && $subscribedVia !== '') {
            // MailPoet sends a confirmation email (double opt-in),
            // so the inbox line is true there. A local-only signup
            // gets no email at all - promising one would be a lie.
            $notice = [
                'type' => 'success',
                'message' => $subscribedVia === Subscribers::SOURCE_MAILPOET
                    ? __('Thank you for subscribing. Please check your inbox to confirm your subscription.', 'koperasikita')
                    : __('Thank you for subscribing.', 'koperasikita'),
            ];
        }

        $html = '<div class="kk-subscribe">';

        if ($notice !== null) {
            $html .= '<p class="kk-notice kk-subscribe-notice" role="status">'
                . esc_html($notice['message']) . '</p>';
        }

        $html .= '<form class="kk-subscribe-form" method="post">';
        $html .= '<input type="email" name="kk_subscribe_email" class="kk-subscribe-email"'
            . ' required maxlength="254" autocomplete="email"'
            . ' aria-label="' . esc_attr__('Email address', 'koperasikita') . '"'
            . ' placeholder="' . esc_attr__('Your email address', 'koperasikita') . '">';
        // Names are optional, so no required attribute: a signup
        // without a name is a valid signup. They sit after the
        // email field and share its field styling (the
        // kk-subscribe-name class, in newsletter.css, widgets.css
        // and footer.css).
        $html .= '<input type="text" name="kk_subscribe_first_name" class="kk-subscribe-name kk-subscribe-first-name"'
            . ' maxlength="100" autocomplete="given-name"'
            . ' aria-label="' . esc_attr__('First name', 'koperasikita') . '"'
            . ' placeholder="' . esc_attr__('Your first name', 'koperasikita') . '">';
        $html .= '<input type="text" name="kk_subscribe_last_name" class="kk-subscribe-name kk-subscribe-last-name"'
            . ' maxlength="100" autocomplete="family-name"'
            . ' aria-label="' . esc_attr__('Last name', 'koperasikita') . '"'
            . ' placeholder="' . esc_attr__('Your last name', 'koperasikita') . '">';
        // Honeypot: positioned off-screen, not display:none, so a bot
        // that skips hidden fields still finds it - and a human never
        // sees it. The label text tells a human reader to leave it be.
        $html .= '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
        $html .= '<label>' . esc_html__('Leave this field empty', 'koperasikita')
            . '<input type="text" name="kk_subscribe_website" value="" tabindex="-1" autocomplete="off"></label>';
        $html .= '</div>';
        $html .= wp_nonce_field(self::NONCE_ACTION, 'kk_subscribe_nonce', true, false);
        $html .= '<button type="submit" name="kk_subscribe_submit" value="1" class="kk-button kk-subscribe-submit">'
            . esc_html__('Subscribe', 'koperasikita') . '</button>';
        $html .= '</form></div>';

        return $html;
    }

    /**
     * The source the signup went through, after the
     * Post/Redirect/Get success redirect: 'local' or 'mailpoet',
     * or '' when this is not a post-signup render.
     */
    private static function justSubscribed(): string
    {
        $rawFlag = $_GET['kk_subscribed'] ?? '';
        $flag = is_string($rawFlag) ? sanitize_key(wp_unslash($rawFlag)) : '';

        return in_array($flag, [Subscribers::SOURCE_LOCAL, Subscribers::SOURCE_MAILPOET], true) ? $flag : '';
    }

    private static function redirectWithSuccess(string $source): void
    {
        wp_safe_redirect(add_query_arg('kk_subscribed', $source, remove_query_arg('kk_subscribed')));
        exit;
    }

    /**
     * Whether new signups may be synced to MailPoet. The admin
     * yes/no setting on the Subscribers page; yes by default, so
     * the best-effort sync that shipped first stays on unless the
     * admin turns it off.
     */
    public static function mailPoetSyncEnabled(): bool
    {
        return get_option(self::OPTION_MAILPOET_SYNC, 'yes') !== 'no';
    }

    /**
     * Whether the MailPoet plugin is active at all (its API class
     * is loaded). Cheap check for the frontend stylesheet gate;
     * mailPoetStatus() is the full readiness check for the admin
     * section and the signup flow.
     */
    public static function mailPoetActive(): bool
    {
        return class_exists(\MailPoet\API\API::class);
    }

    /**
     * MailPoet readiness for syncing. One source of truth for the
     * admin section and the signup flow: 'active' (API works and at
     * least one list exists), 'inactive' (plugin not active),
     * 'no_lists' (active but no list to sync into), 'error' (the
     * API call itself failed).
     */
    public static function mailPoetStatus(): string
    {
        $api = self::mailPoetApi();

        if ($api === null) {
            return self::MAILPOET_INACTIVE;
        }

        $lists = self::mailPoetLists($api);

        if ($lists === null) {
            return self::MAILPOET_ERROR;
        }

        if (!is_array($lists)) {
            return self::MAILPOET_INACTIVE;
        }

        return self::mailPoetListIds($lists) === [] ? self::MAILPOET_NO_LISTS : self::MAILPOET_ACTIVE;
    }

    /**
     * The list ids to sync into, or an empty array when MailPoet is
     * not ready. The admin batch fetches these once and reuses them
     * for every address instead of asking for the lists per email.
     *
     * @return array<int, int>
     */
    public static function mailPoetSyncLists(): array
    {
        $api = self::mailPoetApi();

        if ($api === null) {
            return [];
        }

        return self::mailPoetListIds(self::mailPoetLists($api));
    }

    /**
     * Subscribe one address through MailPoet's public API. The
     * frontend signup flow: confirmation and welcome mail stay
     * on, so double opt-in keeps working. Best-effort - the
     * caller treats anything but 'ok' as a local-only signup.
     *
     * @return 'ok'|'unavailable'|'error' 'unavailable' means MailPoet is
     *         not active (or has no lists): the caller must say so
     *         honestly instead of faking a success.
     */
    public static function syncEmailWithMailPoet(string $email, string $firstName = '', string $lastName = '', string $subscribedIp = ''): string
    {
        $api = self::mailPoetApi();

        if ($api === null) {
            return 'unavailable';
        }

        $lists = self::mailPoetLists($api);

        if ($lists === null) {
            return 'error';
        }

        $listIds = self::mailPoetListIds($lists);

        if ($listIds === []) {
            return 'unavailable';
        }

        return self::pushEmailToMailPoetLists($api, $email, $listIds, [], $firstName, $lastName, $subscribedIp)['status'] === 'ok' ? 'ok' : 'error';
    }

    /**
     * Push one address into MailPoet, with the outcome and the
     * reason when it fails. The success criterion is the
     * subscriber record plus the list subscription; the
     * confirmation and welcome mail are side effects (see
     * mailPoetAddToLists) and never decide the outcome. Names
     * and the signup address are carried when given: a new
     * subscriber is created with them, an existing one gets
     * them updated.
     *
     * @param mixed $api
     * @param array<int, int> $listIds
     * @param array{send_confirmation_email?: bool, schedule_welcome_email?: bool} $options
     * @return array{status: string, reason: string}
     */
    private static function pushEmailToMailPoetLists($api, string $email, array $listIds, array $options = [], string $firstName = '', string $lastName = '', string $subscribedIp = ''): array
    {
        try {
            self::mailPoetAddToLists($api, $email, $listIds, $options, $firstName, $lastName, $subscribedIp);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'reason' => self::mailPoetErrorReason($e)];
        }

        return ['status' => 'ok', 'reason' => ''];
    }

    /**
     * The human-readable reason for a failed MailPoet call.
     * MailPoet's API messages are user-facing strings; anything
     * else gets a generic reason so internals never leak.
     */
    private static function mailPoetErrorReason(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return __('MailPoet reported an error.', 'koperasikita');
        }

        if (strlen($message) > 200) {
            $message = substr($message, 0, 200);
        }

        return $message;
    }

    /**
     * The MailPoet v1 API, or null when the plugin is not active or
     * does not expose the methods the theme needs.
     *
     * @return mixed
     */
    private static function mailPoetApi()
    {
        if (!self::mailPoetActive()) {
            return null;
        }

        try {
            $api = \MailPoet\API\API::MP('v1');
        } catch (\Throwable) {
            return null;
        }

        if (
            !method_exists($api, 'getLists')
            || !method_exists($api, 'addSubscriber')
            || !method_exists($api, 'subscribeToLists')
        ) {
            return null;
        }

        return $api;
    }

    /**
     * The raw getLists() result, or null when the call itself failed.
     *
     * @param mixed $api
     * @return mixed
     */
    private static function mailPoetLists($api)
    {
        try {
            return $api->getLists();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The numeric list ids from a getLists() result.
     *
     * @param mixed $lists
     * @return array<int, int>
     */
    private static function mailPoetListIds($lists): array
    {
        if (!is_array($lists)) {
            return [];
        }

        $listIds = [];

        foreach ($lists as $list) {
            if (is_array($list) && isset($list['id'])) {
                $listIds[] = (int) $list['id'];
            }
        }

        return $listIds;
    }

    /**
     * Add one address to the lists, creating it when it is not a
     * subscriber yet. Throws when the API call fails; the caller
     * turns that into an 'error' result with the reason.
     *
     * The confirmation and welcome mail are on by default (the
     * frontend signup flow keeps double opt-in). The admin sync
     * passes both off: it syncs addresses that are already
     * subscribed on the site, so mail delivery must never fail
     * the sync - a failed confirmation send used to report a
     * successful add as a failure, with the local row left
     * behind as still pending.
     *
     * Names ride along when given: a new subscriber is created
     * with them, an existing one is updated with them before
     * the list subscription. Empty names send nothing, so a
     * push can never wipe the names MailPoet already has. The
     * signup address rides along the same way (MailPoet
     * accepts subscribed_ip on both add and update). The
     * confirmation address is never sent: MailPoet owns it and
     * sets it when the subscriber confirms, and the API strips
     * it from incoming data.
     *
     * @param mixed $api
     * @param array<int, int> $listIds
     * @param array{send_confirmation_email?: bool, schedule_welcome_email?: bool} $options
     */
    private static function mailPoetAddToLists($api, string $email, array $listIds, array $options = [], string $firstName = '', string $lastName = '', string $subscribedIp = ''): void
    {
        $mailOptions = [
            'send_confirmation_email' => $options['send_confirmation_email'] ?? true,
            'schedule_welcome_email' => $options['schedule_welcome_email'] ?? true,
        ];

        $subscriberData = ['email' => $email];

        if ($firstName !== '') {
            $subscriberData['first_name'] = $firstName;
        }

        if ($lastName !== '') {
            $subscriberData['last_name'] = $lastName;
        }

        if ($subscribedIp !== '') {
            $subscriberData['subscribed_ip'] = $subscribedIp;
        }

        $existing = self::mailPoetFindSubscriber($api, $email);

        if ($existing !== null) {
            // subscribeToLists takes the subscriber id; the
            // getSubscriber response carries it, so an existing
            // subscriber is subscribed by id, never re-added.
            // Names go in first, through updateSubscriber, so a
            // local name lands on the MailPoet record too.
            $subscriberId = (int) ($existing['id'] ?? 0);
            $target = $subscriberId > 0 ? $subscriberId : $email;

            if (count($subscriberData) > 1) {
                $nameData = $subscriberData;
                unset($nameData['email']);
                $api->updateSubscriber($target, $nameData);
            }

            $api->subscribeToLists($target, $listIds, $mailOptions);

            return;
        }

        try {
            $api->addSubscriber(
                $subscriberData,
                $listIds,
                $mailOptions
            );
        } catch (\Throwable $e) {
            // Created between the lookup and the add: subscribe
            // the existing subscriber instead of reporting a
            // failure for an address that is already there.
            $existing = self::mailPoetFindSubscriber($api, $email);

            if ($existing === null) {
                throw $e;
            }

            $subscriberId = (int) ($existing['id'] ?? 0);
            $target = $subscriberId > 0 ? $subscriberId : $email;

            if (count($subscriberData) > 1) {
                $nameData = $subscriberData;
                unset($nameData['email']);
                $api->updateSubscriber($target, $nameData);
            }

            $api->subscribeToLists($target, $listIds, $mailOptions);
        }
    }

    /**
     * One subscriber by email, or null when MailPoet has no such
     * subscriber. getSubscriber throws for a missing address; a
     * missing address is a normal case here, not an error.
     *
     * @param mixed $api
     * @return array<string, mixed>|null
     */
    private static function mailPoetFindSubscriber($api, string $email): ?array
    {
        if (!method_exists($api, 'getSubscriber')) {
            return null;
        }

        try {
            $found = $api->getSubscriber($email);
        } catch (\Throwable) {
            return null;
        }

        return is_array($found) ? $found : null;
    }

    /**
     * Subscribed contacts on the sync lists, oldest first,
     * capped. Only status 'subscribed' contacts on the sync
     * (default) lists: unconfirmed, unsubscribed, inactive,
     * bounced, and WordPress users who never subscribed to the
     * newsletter are never pulled. Ignored test addresses are
     * skipped here too.
     *
     * @param array<int, int> $listIds
     * @return array<int, string>
     */
    public static function mailPoetSubscribedEmails(array $listIds, int $limit = 100): array
    {
        return array_column(self::mailPoetSubscribedRows($listIds, $limit), 'email');
    }

    /**
     * The same contacts as mailPoetSubscribedEmails, with the
     * first and last name and the audit IPs per contact, so the
     * pull stores the names and the signup and confirmation
     * addresses on the local rows. The IPs come straight off
     * the MailPoet record; only valid addresses are kept.
     *
     * @param array<int, int> $listIds
     * @return array<int, array{email: string, first_name: string, last_name: string, subscribed_ip: string, confirmed_ip: string}>
     */
    public static function mailPoetSubscribedRows(array $listIds, int $limit = 100): array
    {
        $api = self::mailPoetApi();

        if ($api === null || !method_exists($api, 'getSubscribers') || $listIds === []) {
            return [];
        }

        $contacts = [];
        $seen = [];

        foreach ($listIds as $listId) {
            $offset = 0;

            while (count($contacts) < $limit) {
                $pageSize = min(100, $limit - count($contacts));

                try {
                    $rows = $api->getSubscribers(
                        ['status' => 'subscribed', 'listId' => (int) $listId],
                        $pageSize,
                        $offset
                    );
                } catch (\Throwable) {
                    break;
                }

                if (!is_array($rows) || $rows === []) {
                    break;
                }

                $offset += count($rows);

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    // The server-side filter is the first gate; the
                    // per-row check is the second, so a list that
                    // ignores the filter can never leak a
                    // non-subscriber into the pull.
                    if ((string) ($row['status'] ?? '') !== 'subscribed') {
                        continue;
                    }

                    $email = sanitize_email((string) ($row['email'] ?? ''));

                    if ($email === '' || !is_email($email) || Subscribers::isIgnoredSyncAddress($email)) {
                        continue;
                    }

                    $key = strtolower($email);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $contacts[] = [
                        'email' => $email,
                        'first_name' => trim(sanitize_text_field((string) ($row['first_name'] ?? ''))),
                        'last_name' => trim(sanitize_text_field((string) ($row['last_name'] ?? ''))),
                        'subscribed_ip' => self::cleanApiIp((string) ($row['subscribed_ip'] ?? '')),
                        'confirmed_ip' => self::cleanApiIp((string) ($row['confirmed_ip'] ?? '')),
                    ];

                    if (count($contacts) >= $limit) {
                        break;
                    }
                }

                if (count($rows) < $pageSize) {
                    break;
                }
            }

            if (count($contacts) >= $limit) {
                break;
            }
        }

        return $contacts;
    }

    /**
     * One IP address off a MailPoet record: only a valid IPv4
     * or IPv6 address is kept, anything else reads as empty.
     */
    private static function cleanApiIp(string $ip): string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * One two-way MailPoet sync click: push the locally stored
     * subscribers into MailPoet, then pull the MailPoet
     * subscribers that are not stored here yet. Both directions
     * only move real subscribers (status 'subscribed'); test
     * addresses and non-subscribers are skipped and never
     * counted. A successful add is a success even when
     * MailPoet's confirmation mail cannot be sent - the admin
     * sync never sends mail, so delivery can never fail the
     * sync (see mailPoetAddToLists).
     *
     * @return array{lines: array<int, string>, summary: string, pushed: int, pulled: int, already: int, failed: int, ignored: int, remaining: int}
     */
    public static function runTwoWaySync(int $batch = 100): array
    {
        $batch = max(1, $batch);
        $listIds = self::mailPoetSyncLists();

        $lines = [];
        $pushed = 0;
        $pulled = 0;
        $already = 0;
        $failed = 0;
        $ignored = 0;

        $api = self::mailPoetApi();

        /* Push: local subscribed rows into MailPoet. The batch
         * already excludes ignored addresses and non-subscribed
         * rows; the checks below are defence in depth. Names and
         * the signup address ride along with each address. */
        if ($api !== null && $listIds !== []) {
            $mailOptions = ['send_confirmation_email' => false, 'schedule_welcome_email' => false];

            foreach (Subscribers::localSubscribedRows($batch) as $row) {
                $email = $row['email'];

                if (Subscribers::isIgnoredSyncAddress($email)) {
                    $ignored++;
                    $lines[] = sprintf(__('%s: ignored (test address)', 'koperasikita'), $email);
                    continue;
                }

                $result = self::pushEmailToMailPoetLists($api, $email, $listIds, $mailOptions, $row['first_name'], $row['last_name'], $row['subscribed_ip']);

                if ($result['status'] === 'ok' && Subscribers::setSource($email, Subscribers::SOURCE_MAILPOET)) {
                    $pushed++;
                    $lines[] = sprintf(__('%s: pushed to MailPoet', 'koperasikita'), $email);
                } elseif ($result['status'] === 'ok') {
                    $failed++;
                    $lines[] = sprintf(__('%s: failed - could not update the local record', 'koperasikita'), $email);
                } else {
                    $failed++;
                    $lines[] = sprintf(__('%s: failed - %s', 'koperasikita'), $email, $result['reason']);
                }
            }
        }

        /* Pull: MailPoet subscribed contacts not stored here yet.
         * An address already in the local table is left exactly
         * as it is - the local row wins, so a local unsubscribe
         * is never overwritten by a pull. The only exception is
         * the audit IPs: a stored row that has none yet takes the
         * addresses off the MailPoet record, so a confirmation
         * that happened in MailPoet still lands here. */
        foreach (self::mailPoetSubscribedRows($listIds, $batch) as $contact) {
            $email = $contact['email'];

            if (Subscribers::isIgnoredSyncAddress($email)) {
                $ignored++;
                $lines[] = sprintf(__('%s: ignored (test address)', 'koperasikita'), $email);
                continue;
            }

            if (Subscribers::exists($email)) {
                Subscribers::backfillIps($email, $contact['subscribed_ip'], $contact['confirmed_ip']);
                $already++;
                $lines[] = sprintf(__('%s: already synced', 'koperasikita'), $email);
                continue;
            }

            if (Subscribers::record($email, Subscribers::SOURCE_MAILPOET, Subscribers::STATUS_SUBSCRIBED, $contact['first_name'], $contact['last_name'], $contact['subscribed_ip'], $contact['confirmed_ip'])) {
                $pulled++;
                $lines[] = sprintf(__('%s: pulled from MailPoet', 'koperasikita'), $email);
            } else {
                $failed++;
                $lines[] = sprintf(__('%s: failed - could not save the local record', 'koperasikita'), $email);
            }
        }

        $remaining = Subscribers::countLocalPending();

        $summary = sprintf(
            /* translators: %d counts: pushed, pulled, already synced, failed, remaining. */
            __('Sync complete: %d pushed, %d pulled, %d already synced, %d failed, %d remaining.', 'koperasikita'),
            $pushed,
            $pulled,
            $already,
            $failed,
            $remaining
        );

        return [
            'lines' => $lines,
            'summary' => $summary,
            'pushed' => $pushed,
            'pulled' => $pulled,
            'already' => $already,
            'failed' => $failed,
            'ignored' => $ignored,
            'remaining' => $remaining,
        ];
    }

    /**
     * The AJAX MailPoet sync (Subscribers page popup): one call
     * runs both directions and answers with the per-address
     * lines and the totals, so the popup renders them without
     * a reload.
     *
     * @return array<string, mixed>
     */
    public static function ajaxSync(): array
    {
        if (self::mailPoetStatus() !== self::MAILPOET_ACTIVE) {
            throw new ApiException(
                Response::ERR_UPSTREAM_FAILED,
                __('MailPoet is not available, so nothing was synced.', 'koperasikita'),
                503
            );
        }

        return self::runTwoWaySync(self::SYNC_BATCH);
    }

    private static function isRateLimited(): bool
    {
        return self::attempts() >= self::RATE_LIMIT_MAX;
    }

    private static function attempts(): int
    {
        $count = get_transient(self::rateKey());

        return is_numeric($count) ? (int) $count : 0;
    }

    private static function recordAttempt(): void
    {
        set_transient(self::rateKey(), self::attempts() + 1, self::RATE_LIMIT_WINDOW);
    }

    private static function rateKey(): string
    {
        return 'kk_subscribe_rate_' . substr(self::clientIpHash(), 0, 32);
    }

    /**
     * The visitor's address, from the Cloudflare header when
     * present, then X-Forwarded-For, then REMOTE_ADDR. Only a
     * single valid address is ever returned - a header dump or
     * a spoofed value reads as empty. The same source the rate
     * limit trusts.
     */
    private static function clientIp(): string
    {
        $headers = [
            isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']) : '',
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '',
            isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '',
        ];

        foreach ($headers as $header) {
            // A forwarded-for header can hold a chain; the first entry
            // is the client.
            $candidate = trim(explode(',', (string) $header)[0]);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Salted hash of the visitor's address, for the rate-limit
     * transient key. The key holds the hash only; the raw
     * address itself is stored nowhere here - it lives on the
     * subscriber row as subscribed_ip.
     */
    private static function clientIpHash(): string
    {
        return hash('sha256', self::clientIp() . wp_salt());
    }
}
