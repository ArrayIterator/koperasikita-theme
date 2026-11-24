<?php

declare(strict_types=1);

namespace KoperasiKita\Setup;

/**
 * Silent upgrader skin for the Setup page installer.
 *
 * The default skins print progress straight into the admin page. The
 * Setup installer runs on admin-post and redirects, so any output
 * would break the redirect — this skin swallows it and keeps the
 * errors for the page to show instead.
 */
final class SetupUpgraderSkin extends \WP_Upgrader_Skin
{
    /** @var array<int, string> */
    private array $errors = [];

    public function header(): void
    {
    }

    public function footer(): void
    {
    }

    public function feedback($string, ...$args): void
    {
    }

    /**
     * @param string|\WP_Error $errors
     */
    public function error($errors): void
    {
        if ($errors instanceof \WP_Error) {
            foreach ($errors->get_error_messages() as $message) {
                $this->errors[] = (string) $message;
            }

            return;
        }

        if (is_string($errors) && $errors !== '') {
            $this->errors[] = $errors;
        }
    }

    /** @return array<int, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
