<?php

declare(strict_types=1);

namespace KoperasiKita\Features;

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
 *    transient keyed by a salted hash of the visitor's address; the raw
 *    address is never stored or logged. The address itself is read from
 *    the Cloudflare header when present, then X-Forwarded-For, then
 *    REMOTE_ADDR. Those headers are only used to pick a rate-limit
 *    bucket - a spoofed header only changes which bucket the attempt
 *    counts against, and the nonce and honeypot still apply.
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

    /** Maximum submits from one address within the rate window. */
    public const RATE_LIMIT_MAX = 5;

    /** Rate-limit window in seconds (one hour, sliding). */
    public const RATE_LIMIT_WINDOW = HOUR_IN_SECONDS;

    /** @var array{type: string, message: string}|null Notice for this request. */
    private static ?array $notice = null;

    public static function register(): void
    {
        add_action('init', [self::class, 'handleSubmission'], 20);
        add_shortcode(self::SHORTCODE, [self::class, 'renderShortcode']);
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

        self::recordAttempt();

        // The local record comes first and is the success
        // criterion: the site keeps its own list whether MailPoet
        // is there or not. A failed local write is the only case
        // where the submit itself fails.
        if (!Subscribers::record($email, Subscribers::SOURCE_LOCAL, Subscribers::STATUS_SUBSCRIBED)) {
            self::$notice = [
                'type' => 'error',
                'message' => __('Could not save your subscription. Please try again later.', 'koperasikita'),
            ];

            return;
        }

        // MailPoet sync is best-effort. Only a confirmed sync
        // flips the row's source to 'mailpoet'; anything else
        // (absent, not set up, no lists, call failed) leaves the
        // honest 'local' source and still succeeds.
        $source = Subscribers::SOURCE_LOCAL;

        if (
            self::subscribeWithMailPoet($email) === 'ok'
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
     * honeypot and the nonce. One markup for the shortcode, the
     * newsletter widget and the newsletter section, so the form
     * behaves identically wherever it is placed.
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
     * Subscribe through MailPoet's public API.
     *
     * @return 'ok'|'unavailable'|'error' 'unavailable' means MailPoet is
     *         not active (or has no lists): the caller must say so
     *         honestly instead of faking a success.
     */
    private static function subscribeWithMailPoet(string $email): string
    {
        if (!class_exists(\MailPoet\API\API::class)) {
            return 'unavailable';
        }

        try {
            $api = \MailPoet\API\API::MP('v1');
        } catch (\Throwable) {
            return 'unavailable';
        }

        if (
            !method_exists($api, 'getLists')
            || !method_exists($api, 'addSubscriber')
            || !method_exists($api, 'subscribeToLists')
        ) {
            return 'unavailable';
        }

        try {
            $lists = $api->getLists();
        } catch (\Throwable) {
            return 'error';
        }

        if (!is_array($lists)) {
            return 'unavailable';
        }

        $listIds = [];

        foreach ($lists as $list) {
            if (is_array($list) && isset($list['id'])) {
                $listIds[] = (int) $list['id'];
            }
        }

        if ($listIds === []) {
            return 'unavailable';
        }

        try {
            $existing = null;

            if (method_exists($api, 'getSubscriber')) {
                try {
                    // Throws when the address is not a subscriber yet.
                    $existing = $api->getSubscriber($email);
                } catch (\Throwable) {
                    $existing = null;
                }
            }

            if ($existing) {
                $api->subscribeToLists($email, $listIds);
            } else {
                $api->addSubscriber(
                    ['email' => $email],
                    $listIds,
                    [
                        'send_confirmation_email' => true,
                        'schedule_welcome_email' => true,
                    ]
                );
            }
        } catch (\Throwable) {
            return 'error';
        }

        return 'ok';
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
     * Salted hash of the visitor's address. The raw address is never
     * stored, logged or put in a transient key.
     */
    private static function clientIpHash(): string
    {
        $headers = [
            isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']) : '',
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '',
            isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '',
        ];

        $ip = '';

        foreach ($headers as $header) {
            // A forwarded-for header can hold a chain; the first entry
            // is the client.
            $candidate = trim(explode(',', (string) $header)[0]);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidate;
                break;
            }
        }

        return hash('sha256', $ip . wp_salt());
    }
}
