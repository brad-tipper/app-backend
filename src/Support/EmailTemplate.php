<?php

namespace BradTipper\AppBackend\Support;

use BradTipper\AppBackend\AppConfig;

/**
 * The one email layout every app shares, branded per app through AppConfig
 * (`name`, `brand_color`, `brand_color_text`, `brand_logo_url`, `support_email`).
 *
 * Content is data, never markup, so a message cannot inject HTML:
 *
 *   ['heading' => 'Welcome', 'paragraphs' => ['...'], 'button' => ['label' => 'Open', 'url' => '...'],
 *    'footnote' => 'Small print', 'preheader' => 'Inbox preview text']
 */
final class EmailTemplate
{
    /**
     * @param array{heading: string, paragraphs?: string[], button?: array{label: string, url: string}, footnote?: string, preheader?: string} $content
     * @return array{html: string, text: string}
     */
    public static function render(array $content): array
    {
        $name = (string) AppConfig::get('name');
        $color = self::color((string) AppConfig::get('brand_color'), '#2f4c63');
        $onColor = self::color((string) AppConfig::get('brand_color_text'), '#ffffff');
        $logo = self::logoUrl();
        $support = self::supportEmail();
        $webUrl = (string) AppConfig::get('web_url');

        $heading = (string) $content['heading'];
        $paragraphs = array_values((array) ($content['paragraphs'] ?? []));
        $button = $content['button'] ?? null;
        $footnote = (string) ($content['footnote'] ?? '');
        $preheader = (string) ($content['preheader'] ?? ($paragraphs[0] ?? $heading));

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '';
        foreach ($paragraphs as $paragraph) {
            $body .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#1f2933;">' . $e((string) $paragraph) . '</p>';
        }
        $buttonHtml = '';
        if (is_array($button) && !empty($button['url'])) {
            $buttonHtml = '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:8px 0 24px;"><tr>'
                . '<td style="border-radius:8px;background:' . $color . ';">'
                . '<a href="' . $e((string) $button['url']) . '" style="display:inline-block;padding:13px 26px;font-size:16px;font-weight:600;color:' . $onColor . ';text-decoration:none;border-radius:8px;">'
                . $e((string) $button['label']) . '</a></td></tr></table>'
                . '<p style="margin:0 0 16px;font-size:13px;line-height:1.5;color:#6b7280;">Button not working? Copy this link into your browser:<br>'
                . '<a href="' . $e((string) $button['url']) . '" style="color:' . $color . ';word-break:break-all;">' . $e((string) $button['url']) . '</a></p>';
        }
        $footnoteHtml = $footnote !== ''
            ? '<p style="margin:0 0 16px;font-size:14px;line-height:1.5;color:#6b7280;">' . $e($footnote) . '</p>'
            : '';
        $logoHtml = $logo !== ''
            ? '<img src="' . $e($logo) . '" width="40" height="40" alt="" style="display:inline-block;vertical-align:middle;border-radius:8px;margin-right:12px;">'
            : '';
        $contact = $support !== ''
            ? 'Questions? Contact <a href="mailto:' . $e($support) . '" style="color:#6b7280;">' . $e($support) . '</a>.'
            : '';

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light"><title>' . $e($heading) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f4f5f7;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $e($preheader) . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f5f7;padding:24px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
            . '<tr><td style="background:' . $color . ';padding:20px 28px;border-radius:12px 12px 0 0;color:' . $onColor . ';font-size:20px;font-weight:700;">'
            . $logoHtml . '<span style="vertical-align:middle;">' . $e($name) . '</span></td></tr>'
            . '<tr><td style="background:#ffffff;padding:32px 28px 16px;border-radius:0 0 12px 12px;">'
            . '<h1 style="margin:0 0 20px;font-size:22px;line-height:1.3;color:#111827;">' . $e($heading) . '</h1>'
            . $body . $buttonHtml . $footnoteHtml
            . '</td></tr>'
            . '<tr><td style="padding:20px 28px;font-size:12px;line-height:1.6;color:#6b7280;text-align:center;">'
            . $e($name) . ($webUrl !== '' ? ' &middot; <a href="' . $e($webUrl) . '" style="color:#6b7280;">' . $e(preg_replace('#^https?://#', '', $webUrl)) . '</a>' : '')
            . ($contact !== '' ? '<br>' . $contact : '')
            . '<br>You are receiving this because of activity on your ' . $e($name) . ' account.'
            . '</td></tr></table></td></tr></table></body></html>';

        $text = $heading . "\n\n";
        foreach ($paragraphs as $paragraph) {
            $text .= $paragraph . "\n\n";
        }
        if (is_array($button) && !empty($button['url'])) {
            $text .= $button['label'] . ': ' . $button['url'] . "\n\n";
        }
        if ($footnote !== '') {
            $text .= $footnote . "\n\n";
        }
        $text .= "--\n" . $name . ($support !== '' ? " | {$support}" : '') . "\n";

        return ['html' => $html, 'text' => $text];
    }

    public static function supportEmail(): string
    {
        $support = trim((string) AppConfig::get('support_email'));
        if ($support !== '') {
            return $support;
        }
        // A no-reply sender is not somewhere to send questions, so say nothing rather than point at it.
        $from = trim((string) AppConfig::get('mail_from_address'));
        return stripos($from, 'no-reply@') === 0 || stripos($from, 'noreply@') === 0 ? '' : $from;
    }

    /** Only a plain #rgb/#rrggbb value reaches the stylesheet, whatever the yml says. */
    private static function color(string $value, string $fallback): string
    {
        return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', trim($value)) ? trim($value) : $fallback;
    }

    private static function logoUrl(): string
    {
        $url = trim((string) AppConfig::get('brand_logo_url'));
        return str_starts_with($url, 'https://') ? $url : '';
    }
}
